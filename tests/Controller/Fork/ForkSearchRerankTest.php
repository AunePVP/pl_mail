<?php

declare(strict_types=1);

namespace App\Tests\Controller\Fork;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Service\Fork\Search\ForkReranker;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Psr\Log\NullLogger;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md): the reranker behind "Beste Treffer" and the chat model's
 * choice of results. Switched on by FORK_RERANK_URL; the model is a mock that
 * scores by a word the test chooses.
 */
final class ForkSearchRerankTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private const string URL = 'http://rerank.invalid/rerank';

    private KernelBrowser $client;
    private Connection $connection;

    /** Documents containing this word score 0.95, everything else 0.02. */
    private string $winner = '';

    /** @var list<array<string, mixed>> */
    private array $reranked = [];

    /** @var list<array<string, mixed>> */
    private array $chats = [];

    private bool $rerankerDown = false;

    protected function setUp(): void
    {
        $this->setRerankUrl(self::URL);

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = self::getContainer();
        $container->set('http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) ($options['body'] ?? '{}'), true) ?? [];

            if (self::URL === $url) {
                $this->reranked[] = $body;

                if (true === $this->rerankerDown) {
                    return new MockResponse('', ['http_code' => 503]);
                }

                $results = [];

                foreach ($body['documents'] as $index => $document) {
                    $results[] = ['index' => $index, 'relevance_score' => str_contains($document, $this->winner) ? 0.95 : 0.02];
                }

                return new MockResponse(json_encode(['results' => $results]));
            }

            $this->chats[] = $body;

            return new MockResponse(ForkSearchInsightTest::ollamaStream('{"answer":"Ok.","important":[],"groups":[]}'));
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

        $this->setRerankUrl(null);

        parent::tearDown();
    }

    public function testTheBestMatchIsShownAndTheRestAreNot(): void
    {
        $this->seedThread('Flug: Newsletter mit Angeboten', '-1 hour');
        $wanted = $this->seedThread('Flug XY1234 Buchungsbestätigung', '-3 days');
        $this->seedThread('Flug-Gutschein für Sie', '-2 hours');
        $this->winner = 'XY1234';

        $this->client->request('GET', '/mail/search/fork/best?q=Flug');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '[data-fork-best-row]');
        self::assertSelectorExists(sprintf('[data-fork-best-row="%d"]', $wanted->id));
        self::assertSame('Flug', $this->reranked[0]['query']);
        self::assertCount(3, $this->reranked[0]['documents']);
    }

    /** Two equally good matches: last week's booking before July's. */
    public function testAmongEqualMatchesTheRecentOneComesFirst(): void
    {
        $old    = $this->seedThread('Flug XY1234 Buchungsbestätigung', '-120 days');
        $recent = $this->seedThread('Flug XY1234 Check-in geöffnet', '-5 days');
        $this->winner = 'XY1234';

        $crawler = $this->client->request('GET', '/mail/search/fork/best?q=Flug');

        self::assertSame(
            [(string) $recent->id, (string) $old->id],
            $crawler->filter('[data-fork-best-row]')->each(static fn ($row): string => (string) $row->attr('data-fork-best-row')),
        );
    }

    public function testNothingClearsTheBarNothingIsShown(): void
    {
        $this->seedThread('Flug: Newsletter mit Angeboten', '-1 hour');
        $this->winner = 'nirgends';

        $this->client->request('GET', '/mail/search/fork/best?q=Flug');

        self::assertSelectorNotExists('[data-fork-best]');
    }

    public function testADownRerankerIsNoPanelAndNoError(): void
    {
        $this->seedThread('Flug XY1234 Buchungsbestätigung', '-3 days');
        $this->rerankerDown = true;

        $this->client->request('GET', '/mail/search/fork/best?q=Flug');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-fork-best]');
    }

    /** The answer's stream and its reload come seconds later; neither waits for a dead reranker again. */
    public function testAFailureIsRememberedForTheSearch(): void
    {
        $this->seedThread('Flug XY1234 Buchungsbestätigung', '-3 days');
        $this->rerankerDown = true;

        $this->client->request('GET', '/mail/search/fork/best?q=Flug');
        // Longer than the one second a failure used to be kept.
        usleep(1_200_000);
        $this->client->request('GET', '/mail/search/fork/best?q=Flug');

        self::assertCount(1, $this->reranked);
    }

    /** The answer's twenty are the reranker's best, not the newest, and the ranking is asked for once. */
    public function testTheModelReadsTheRerankedOrder(): void
    {
        $this->switchOn();
        $this->seedThread('Flug: Newsletter mit Angeboten', '-1 hour');
        $this->seedThread('Flug XY1234 Buchungsbestätigung', '-3 days');
        $this->winner = 'XY1234';

        $this->client->request('GET', '/mail/search/fork/best?q=Flug');
        ob_start();

        try {
            $this->client->request('GET', '/mail/search/fork/answer/stream?q=Flug');
        } finally {
            ob_end_clean();
        }

        self::assertCount(1, $this->reranked, 'cached between the two frames');
        self::assertCount(1, $this->chats);

        $results = json_decode($this->chats[0]['messages'][1]['content'], true)['results'];

        self::assertSame('Flug XY1234 Buchungsbestätigung', $results[0]['subject']);
    }

    public function testWithoutTheVariableThereIsNoReranking(): void
    {
        $http     = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('asked'));
        $reranker = new ForkReranker($http, new NullLogger(), null, '');

        self::assertFalse($reranker->isAvailable());
        self::assertNull($reranker->rank('Flug', [1 => 'Flug']));
        self::assertSame(0, $http->getRequestsCount());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function setRerankUrl(?string $url): void
    {
        if (null === $url) {
            unset($_ENV['FORK_RERANK_URL'], $_SERVER['FORK_RERANK_URL']);
            putenv('FORK_RERANK_URL');

            return;
        }

        $_ENV['FORK_RERANK_URL']    = $url;
        $_SERVER['FORK_RERANK_URL'] = $url;
        putenv('FORK_RERANK_URL=' . $url);
    }

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

    private function seedThread(string $subject, string $when): MessageThread
    {
        $at = new DateTimeImmutable($when);

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
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
        $message->subject        = $subject;
        $message->fromAddress    = 'noreply@example.test';
        $message->fromName       = 'Example';
        $message->receivedAt     = $at;
        $message->sentAt         = $at;
        $message->seenAt         = $at;
        $message->flags          = [];
        $message->hasAttachments = false;
        $message->bodyText       = 'Text zu: ' . $subject;

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }
}
