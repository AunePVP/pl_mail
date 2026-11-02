<?php

declare(strict_types=1);

namespace App\Tests\Controller\Fork;

use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Service\Calendar\CalendarProvisioner;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md): the two panels above the search results.
 */
final class ForkSearchInsightTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private string $answer = '{"answer":"","important":[],"groups":[]}';
    private int $asked = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = self::getContainer();
        $container->set('http_client', new MockHttpClient(function (): MockResponse {
            ++$this->asked;

            return new MockResponse(json_encode(['message' => ['content' => $this->answer], 'done' => true, 'eval_count' => 1]));
        }));

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheFirstPageOfASearchCarriesBothFrames(): void
    {
        $this->seedThread('Ihre Flugbuchung nach Lissabon ist bestätigt');

        $this->client->request('GET', '/mail/search?q=Flug');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#fork-search-trips[loading="lazy"]');
        self::assertSelectorExists('turbo-frame#fork-search-answer[loading="lazy"]');
        // Lazy: rendering the page asked the model nothing.
        self::assertSame(0, $this->asked);
    }

    public function testALaterPageHasNeither(): void
    {
        $this->seedThread('Ihre Flugbuchung nach Lissabon ist bestätigt');

        $this->client->request('GET', '/mail/search?q=Flug&page=2');

        self::assertSelectorNotExists('turbo-frame#fork-search-trips');
    }

    /** "Flug" names the kind; the parking mail names the flight number. */
    public function testTheComingFlightIsShownWithTheMailAboutIt(): void
    {
        $this->seedEvent('XY1234 FRA → LIS', ExtractionKind::Flight, '+10 days');
        $this->seedThread('Ihre Parkplatzbuchung für den Flug XY 1234');
        $this->seedThread('Ihr Paket ist unterwegs');

        $this->client->request('GET', '/mail/search/fork/trips?q=Flug');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '[data-fork-trip]');
        self::assertSelectorTextContains('[data-fork-trip]', 'XY1234 FRA → LIS');
        self::assertSelectorTextContains('[data-fork-trip]', 'Parkplatzbuchung');
        self::assertSelectorTextNotContains('[data-fork-trip]', 'Paket');
    }

    public function testAPastFlightAndADeclinedOneAreNot(): void
    {
        $this->seedEvent('LH400 FRA → JFK', ExtractionKind::Flight, '-5 days');
        $declined = $this->seedEvent('LH401 JFK → FRA', ExtractionKind::Flight, '+5 days');
        $declined->myParticipation = ParticipationStatus::Declined;
        $this->em->flush();

        $this->client->request('GET', '/mail/search/fork/trips?q=Flug');

        self::assertSelectorNotExists('[data-fork-trip]');
    }

    public function testAnOfferIsMarkedAsOne(): void
    {
        $offer = $this->seedEvent('XY4321 LIS → FRA', ExtractionKind::Flight, '+30 days');
        $offer->myParticipation = ParticipationStatus::NeedsAction;
        $this->em->flush();

        $this->client->request('GET', '/mail/search/fork/trips?q=flights');

        self::assertSelectorExists('[data-fork-trip] .bg-accent-soft');
    }

    public function testTheAnswerLinksOnlyResultsItWasGiven(): void
    {
        $this->switchOn();
        $thread = $this->seedThread('Ihre Flugbuchung nach Lissabon ist bestätigt');

        $this->answer = json_encode([
            'answer'    => 'Dein Flug nach Lissabon ist bestätigt.',
            'important' => [$thread->id, 99999999],
            'groups'    => [['title' => 'Eine Gruppe aus einem', 'ids' => [$thread->id]]],
        ]);

        $this->client->request('GET', '/mail/search/fork/answer?q=Flug');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->asked);
        self::assertSelectorTextContains('[data-fork-answer-text]', 'Dein Flug nach Lissabon ist bestätigt.');
        self::assertSelectorCount(1, '[data-fork-answer-important] li');
        // A group of one is not a group.
        self::assertSelectorNotExists('[data-fork-answer-groups]');
    }

    public function testNoAnswerWithoutTheSummarySwitch(): void
    {
        $this->seedThread('Ihre Flugbuchung nach Lissabon ist bestätigt');

        $this->client->request('GET', '/mail/search/fork/answer?q=Flug');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->asked);
        self::assertSelectorNotExists('[data-fork-answer-state]');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function switchOn(): void
    {
        $this->connection->executeStatement('DELETE FROM ai_settings');

        $settings                 = new AiSettings();
        $settings->isEnabled      = true;
        $settings->baseUrl        = 'http://model-host.invalid:11434';
        $settings->chatModel      = 'qwen3:30b';
        $settings->summaryEnabled = true;
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function seedEvent(string $title, ExtractionKind $kind, string $when): CalendarEvent
    {
        $calendar = self::getContainer()->get(CalendarProvisioner::class)->defaultFor($this->user);

        $event           = new CalendarEvent();
        $event->calendar = $calendar;
        $event->usr      = $this->user;
        $event->uid      = uniqid('fork-', true) . '@plmail';
        $event->title    = $title;
        $event->kind     = $kind;
        $event->startsAt = new DateTimeImmutable($when);
        $event->endsAt   = $event->startsAt->modify('+2 hours');

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function seedThread(string $subject): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-2 hours');
        $thread->category          = MessageCategory::Updates;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->addLabel($this->inbox);
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = 'noreply@example.test';
        $message->fromName       = 'Example Travel';
        $message->receivedAt     = new DateTimeImmutable('-2 hours');
        $message->sentAt         = $message->receivedAt;
        $message->seenAt         = $message->receivedAt;
        $message->flags          = [];
        $message->hasAttachments = false;
        $message->bodyText       = 'Vielen Dank für Ihre Buchung. ' . $subject;

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }
}
