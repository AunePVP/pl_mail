<?php

declare(strict_types=1);

namespace App\Tests\Service\Fork;

use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\CalendarProvisioner;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\EventExtractionRunner;
use App\Service\Calendar\Extraction\ExtractedEvent;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md): travel bookings read by the chat model.
 *
 * The model is a MockHttpClient that answers what the test says it answers;
 * what is under test is everything around it — which mail it is asked about,
 * which of its answers survive, and that what survives is only ever offered.
 */
final class ForkLlmTravelExtractorTest extends KernelTestCase
{
    /** An agency confirmation as ReadableBody gives it, shortened; made up. */
    private const string TRIP = "Flugbuchungsbestätigung\nWien - Köln | 4. Dezember 2026\n"
        . "09:20 VIE Flughafen Wien\nExample Air XY4321\n10:55 CGN Flughafen Köln/Bonn\nBuchungsnummer: QZ7K2P";

    private const string FLIGHT = '{"kind":"flight","title":"XY 4321 VIE → CGN","start":"2026-12-04T09:20",'
        . '"start_zone":"Europe/Vienna","end":"2026-12-04T10:55","end_zone":"Europe/Berlin",'
        . '"location":"Flughafen Wien","number":"XY4321","reference":"QZ7K2P","status":"confirmed",'
        . '"evidence":"Wien - Köln | 4. Dezember 2026 ... 09:20 VIE"}';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;
    private string $answer = '{"items":[]}';
    private int $asked = 0;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $container->set('http_client', new MockHttpClient(function (): MockResponse {
            ++$this->asked;

            return new MockResponse(json_encode(['message' => ['content' => $this->answer], 'done' => true, 'eval_count' => 1]));
        }));

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAFlightWithoutMarkupIsRead(): void
    {
        $this->answer = '{"items":[' . self::FLIGHT . ']}';

        $events = $this->extract($this->message(self::TRIP));

        self::assertCount(1, $events);
        self::assertSame(EventSource::Llm, $events[0]->source);
        self::assertSame(ExtractionKind::Flight, $events[0]->kind);
        self::assertSame(EventStatus::Tentative, $events[0]->status);
        // 09:20 in Vienna and 10:55 in Cologne, both CET in December.
        self::assertSame('2026-12-04 08:20', $events[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-12-04 09:55', $events[0]->endsAt->format('Y-m-d H:i'));
    }

    /** The flight number on its date is the flight, whoever sold it. */
    public function testTheKeyIsTheFlightNotTheSender(): void
    {
        $this->answer = '{"items":[' . self::FLIGHT . ']}';
        $first        = $this->extract($this->message(self::TRIP, from: 'noreply@agency.example'));
        $second       = $this->extract($this->message(self::TRIP, from: 'checkin@airline.example'));

        self::assertSame($first[0]->dedupKey, $second[0]->dedupKey);
    }

    public function testATimeThatIsNotInTheMailIsDropped(): void
    {
        $this->answer = '{"items":[' . str_replace('"2026-12-04T09:20"', '"2026-12-04T10:20"', self::FLIGHT) . ']}';

        self::assertSame([], $this->extract($this->message(self::TRIP)));
    }

    public function testANumberThatIsNotInTheMailIsDropped(): void
    {
        $this->answer = '{"items":[' . str_replace('"XY4321"', '"XY4322"', self::FLIGHT) . ']}';

        self::assertSame([], $this->extract($this->message(self::TRIP)));
    }

    public function testAQuoteThatIsNotInTheMailIsDropped(): void
    {
        $this->answer = '{"items":[' . str_replace('4. Dezember 2026', '5. Dezember 2026', self::FLIGHT) . ']}';

        self::assertSame([], $this->extract($this->message(self::TRIP)));
    }

    public function testAnAnswerThatIsNotJsonIsNothing(): void
    {
        $this->answer = 'Sorry, I cannot help with that.';

        self::assertSame([], $this->extract($this->message(self::TRIP)));
    }

    public function testMailWithMarkupIsLeftToTheMarkup(): void
    {
        $this->answer = '{"items":[' . self::FLIGHT . ']}';
        $message      = $this->message(self::TRIP);
        $message->bodyHtml = '<script type="application/ld+json">{"@type":"EmailMessage"}</script>';

        self::assertSame([], $this->extract($message));
        self::assertSame(0, $this->asked);
    }

    public function testPromotionsAreNotAsked(): void
    {
        $this->answer = '{"items":[' . self::FLIGHT . ']}';

        self::assertSame([], $this->extract($this->message(self::TRIP, category: MessageCategory::Promotions)));
        self::assertSame(0, $this->asked);
    }

    public function testMailAboutSomethingElseIsNotAsked(): void
    {
        self::assertSame([], $this->extract($this->message('Die Rechnung für Oktober liegt bei.', subject: 'Rechnung')));
        self::assertSame(0, $this->asked);
    }

    public function testTheModelIsAskedOncePerMessage(): void
    {
        $this->answer = '{"items":[' . self::FLIGHT . ']}';
        $message      = $this->message(self::TRIP);

        $this->extract($message);
        $this->extract($message);

        self::assertSame(1, $this->asked);
    }

    /**
     * The point of EventSource::Llm: a guess is offered, never drawn — even
     * from a sender whose server vouched for them, which would draw markup.
     */
    public function testAGuessIsOnlyEverOffered(): void
    {
        $this->answer = '{"items":[' . self::FLIGHT . ']}';
        $message      = $this->message(self::TRIP);
        $this->em->persist($message);
        $this->em->flush();

        $touched = self::getContainer()->get(EventReconciler::class)->reconcile($message, $this->extract($message));
        $this->em->flush();

        self::assertCount(1, $touched);
        self::assertSame(ParticipationStatus::NeedsAction, $touched[0]->myParticipation);
        self::assertSame(EventSource::Llm, $touched[0]->source);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return list<ExtractedEvent> */
    private function extract(Message $message): array
    {
        return self::getContainer()->get(EventExtractionRunner::class)->run($message);
    }

    private function message(
        string          $text,
        string          $subject = 'Flugbuchungsbestätigung: Wien - Köln',
        string          $from = 'noreply@agency.example',
        MessageCategory $category = MessageCategory::Updates,
    ): Message {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = $subject;
        $message->fromAddress    = $from;
        $message->category       = $category;
        $message->receivedAt     = new DateTimeImmutable('2026-10-01 12:00:00');
        $message->hasAttachments = false;
        $message->bodyText       = $text;
        $message->messageId      = sprintf('<%s@example.test>', uniqid('', true));
        $message->headers        = ['authentication-results' => sprintf(
            'mx.example.test; dkim=pass header.d=%1$s; dmarc=pass header.from=%1$s',
            substr($from, (int) strrpos($from, '@') + 1),
        )];

        return $message;
    }

    private function seed(): void
    {
        $this->connection->executeStatement('DELETE FROM ai_settings');

        $settings                        = new AiSettings();
        $settings->isEnabled             = true;
        $settings->baseUrl               = 'http://model-host.invalid:11434';
        $settings->chatModel             = 'qwen3:30b';
        $settings->categorisationEnabled = true;
        $this->em->persist($settings);

        $user            = new User();
        $user->email     = 'fork-llm-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Fork';
        $user->nameLast  = 'Llm';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $user->timezone  = 'Europe/Berlin';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Fork Llm';
        $account->username       = 'fork-llm@example.test';
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        $this->account = $account;

        $provisioner = self::getContainer()->get(CalendarProvisioner::class);
        $provisioner->defaultFor($user);
        $provisioner->forAccount($account);
        $this->em->flush();
    }
}
