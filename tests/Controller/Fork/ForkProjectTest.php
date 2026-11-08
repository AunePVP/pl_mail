<?php

declare(strict_types=1);

namespace App\Tests\Controller\Fork;

use App\Domain\Enum\Fork\ForkProjectStatus;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Fork\ForkMessageProject;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Fork\ForkMessageProjectRepository;
use App\Service\Fork\Projektakte\ForkProjektakteClient;
use App\Tests\Support\Fork\FakeProjektakte;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md 27): the project chip — the picker, and filing a message under
 * a Projektakte project, under none, or taking that back. Projektakte is a
 * fake answering its contract (FakeProjektakte).
 */
final class ForkProjectTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private FakeProjektakte $projektakte;

    protected function setUp(): void
    {
        $this->projektakte = new FakeProjektakte();
        FakeProjektakte::configure($this->projektakte);

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = self::getContainer();
        $container->set('http_client', $this->projektakte->client());

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

        FakeProjektakte::configure(null);

        parent::tearDown();
    }

    public function testThePickerOffersTheGuessesFirstAndTheWholeList(): void
    {
        $message = $this->seedMessage();

        $this->client->request('GET', $this->url($message));

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '[data-fork-project-suggestion]');
        self::assertSelectorExists('[data-fork-project-suggestion][data-preselected][data-number="BS-0000-0001"]');
        self::assertSelectorTextContains('[data-fork-project-suggestion]', 'Projektnummer BS-0000-0001 im Betreff');
        self::assertSelectorCount(2, '[data-fork-project-list] [data-number]');
        self::assertSelectorExists('[data-fork-project-none]');

        $suggest = $this->call('/api/integration/suggest');
        self::assertSame('Angebot BS-0000-0001', $suggest['body']['subject']);
        self::assertSame('planer@example.test', $suggest['body']['from']);
        self::assertSame(3, $suggest['body']['limit']);
        self::assertSame('Authorization: Bearer ' . FakeProjektakte::TOKEN, $suggest['auth']);
    }

    /** Projektakte may be sure; a person still decides. */
    public function testOpeningThePickerAssignsNothing(): void
    {
        $message = $this->seedMessage();

        $this->client->request('GET', $this->url($message));

        self::assertNull($this->row($message));
        self::assertNotContains('/api/integration/notify', $this->projektakte->paths());
    }

    public function testChoosingAProjectFilesTheMessageAndSaysSo(): void
    {
        $message = $this->seedMessage();
        $jobs    = $this->jobs();

        $this->send('POST', $message, ['number' => 'BS-0000-0001']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString(sprintf('target="fork_project_%d"', $message->id), $this->body());
        self::assertStringContainsString('data-fork-project="assigned"', $this->body());
        self::assertStringContainsString('BS-0000-0001', $this->body());

        $row = $this->row($message);
        self::assertSame(ForkProjectStatus::Assigned, $row?->status);
        self::assertSame('BS-0000-0001', $row->projectNumber);
        self::assertSame('Rathaus Umbau', $row->projectName, 'the name is Projektakte\'s, not the request\'s');
        self::assertSame('Marker Fixture', $row->assignedByName);

        self::assertSame(1, $this->asked('/api/integration/notify'));
        self::assertContains((string) $message->id, $this->emailChanges());
        self::assertSame($jobs, $this->jobs(), 'nothing is queued for the mail server');
    }

    public function testMovingToAnotherProjectToNoneAndBack(): void
    {
        $message = $this->seedMessage();

        $this->send('POST', $message, ['number' => 'BS-0000-0001']);
        $this->send('POST', $message, ['number' => 'BS-0000-0002']);

        $moved = $this->row($message);
        self::assertNotNull($moved);
        self::assertSame('BS-0000-0002', $moved->projectNumber);
        self::assertSame('Schule Nord', $moved->projectName);

        $this->send('POST', $message, ['none' => true]);

        self::assertResponseIsSuccessful();
        $none = $this->row($message);
        self::assertNotNull($none);
        self::assertSame(ForkProjectStatus::None, $none->status);
        self::assertNull($none->projectNumber);
        self::assertStringContainsString('data-fork-project="none"', $this->body());

        $this->send('DELETE', $message);

        self::assertResponseIsSuccessful();
        self::assertNull($this->row($message));
        self::assertStringContainsString('data-fork-project=""', $this->body());
        self::assertSame(4, $this->asked('/api/integration/notify'));
        self::assertSame(1, $this->asked('/api/integration/projects'), 'the list is asked for once and then cached');
    }

    public function testAProjectProjektakteDoesNotKnowIsRefused(): void
    {
        $message = $this->seedMessage();

        $this->send('POST', $message, ['number' => 'BS-9999-9999']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Projektakte does not know this project.', $this->error());
        self::assertNull($this->row($message));
        self::assertSame(0, $this->asked('/api/integration/notify'));
    }

    /** Created since the list was cached, or older than its first 500. */
    public function testAProjectBeyondTheListIsAskedForByNumber(): void
    {
        $message = $this->seedMessage();

        $this->send('POST', $message, ['number' => 'BS-0000-0099']);

        self::assertResponseIsSuccessful();
        self::assertSame('Altbau Lager', $this->row($message)?->projectName);
    }

    public function testADownProjektakteIsASentenceNotAnError(): void
    {
        $message                 = $this->seedMessage();
        $this->projektakte->down = true;

        $this->client->request('GET', $this->url($message));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-fork-project-unavailable]');
        self::assertSelectorNotExists('[data-fork-project-list]');
        self::assertSelectorExists('[data-fork-project-none]');

        $this->send('POST', $message, ['number' => 'BS-0000-0001']);

        self::assertResponseStatusCodeSame(503);
        self::assertSame('Projektakte cannot be reached right now.', $this->error());
        self::assertNull($this->row($message));

        // The two answers that need no list still work.
        $this->send('POST', $message, ['none' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(ForkProjectStatus::None, $this->row($message)?->status);

        $this->send('DELETE', $message);
        self::assertResponseIsSuccessful();
        self::assertNull($this->row($message));
    }

    /** The regular round picks it up; the person's decision stands. */
    public function testAFailedNotifyKeepsTheAssignment(): void
    {
        $message                        = $this->seedMessage();
        $this->projektakte->notifyFails = true;

        $this->send('POST', $message, ['number' => 'BS-0000-0001']);

        self::assertResponseIsSuccessful();
        self::assertSame('BS-0000-0001', $this->row($message)?->projectNumber);
        self::assertSame(1, $this->asked('/api/integration/notify'));
    }

    public function testOnlyTheOwnerMayChooseOrLook(): void
    {
        $message = $this->seedMessage();

        $this->client->loginUser($this->seedUser());
        $this->client->request('GET', $this->url($message));
        self::assertResponseStatusCodeSame(403);

        $this->send('POST', $message, ['number' => 'BS-0000-0001']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->row($message));
    }

    public function testWithoutTheTokenNothingChanges(): void
    {
        $message = $this->seedMessage();

        $this->client->request('POST', $this->url($message), content: '{"number":"BS-0000-0001"}');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->row($message));
    }

    public function testTheChipStandsInTheConversationWithTheAssignment(): void
    {
        $message = $this->seedMessage();
        $this->send('POST', $message, ['number' => 'BS-0000-0001']);

        $this->client->request('GET', sprintf('/mail/thread/%d', $message->thread?->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('#fork_project_%d[data-fork-project="assigned"]', $message->id));
        self::assertSelectorTextContains(sprintf('#fork_project_%d', $message->id), 'Rathaus Umbau');
    }

    public function testWithoutAddressAndTokenNothingIsAsked(): void
    {
        $http   = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('asked'));
        $client = new ForkProjektakteClient($http, new ArrayAdapter(), new NullLogger(), '', '');

        self::assertFalse($client->isAvailable());
        self::assertNull($client->projects());
        self::assertNull($client->lookup('BS-0000-0001'));
        $client->notify();
        self::assertFalse($client->info()['ok']);
        self::assertSame(0, $http->getRequestsCount());

        self::assertFalse(new ForkProjektakteClient($http, new ArrayAdapter(), new NullLogger(), 'http://projektakte.invalid', '')->isAvailable(), 'an address without a token is not configured');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function url(Message $message): string
    {
        return sprintf('/mail/message/%d/fork/project', $message->id);
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, Message $message, ?array $body = null): void
    {
        $token = $this->token();

        $this->client->request(
            $method,
            $this->url($message),
            server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'],
            content: null === $body ? null : (string) json_encode($body),
        );
    }

    private function error(): ?string
    {
        return json_decode($this->body(), true)['error'] ?? null;
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function row(Message $message): ?ForkMessageProject
    {
        $this->em->clear();

        return self::getContainer()->get(ForkMessageProjectRepository::class)->findOneBy(['message' => $message->id]);
    }

    private function asked(string $path): int
    {
        return count(array_filter($this->projektakte->paths(), static fn (string $asked): bool => $asked === $path));
    }

    /** @return array<string, mixed> */
    private function call(string $path): array
    {
        foreach ($this->projektakte->calls as $call) {
            if ($path === $call['path']) {
                return $call;
            }
        }

        self::fail(sprintf('Projektakte was not asked for %s', $path));
    }

    /** @return list<string> the Email ids announced as updated on this account */
    private function emailChanges(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT entity_id FROM jmap_change_log WHERE account_id = ? AND object_type = 'Email' AND change_type = 'updated'",
            [$this->account->id],
        ));
    }

    private function jobs(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM messenger_messages');
    }

    private function token(): string
    {
        $this->client->request('GET', '/mail/inbox');

        $container = static::getContainer();
        $stack     = $container->get('request_stack');
        $stack->push($this->client->getRequest());

        try {
            return (string) $container->get('security.csrf.token_manager')->getToken('ajax')->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function seedMessage(): Message
    {
        $at = new DateTimeImmutable('-1 day');

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Angebot BS-0000-0001';
        $thread->normalizedSubject = 'angebot bs-0000-0001';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = $at;
        $thread->category          = MessageCategory::Updates;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->addLabel($this->inbox);
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Angebot BS-0000-0001';
        $message->fromAddress    = 'planer@example.test';
        $message->fromName       = 'Planer';
        $message->receivedAt     = $at;
        $message->sentAt         = $at;
        $message->seenAt         = $at;
        $message->flags          = [];
        $message->hasAttachments = false;
        $message->bodyText       = 'Anbei das Angebot zum Rathaus.';
        $message->addLabel($this->inbox);

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }
}
