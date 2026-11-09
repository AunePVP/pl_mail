<?php

declare(strict_types=1);

namespace App\Tests\Controller\Fork;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * FORK (FORK.md 29): a row in Sent is drawn from your own mail, and Sent is in
 * the order you sent.
 *
 * The case from the field: you wrote three days ago, somebody answered an hour
 * ago. Sent showed the answer — its recipients ("To: you"), its text, its
 * time, bold while unread — and put the conversation on top, so it read as
 * received mail filed under Sent.
 */
final class ForkSentRowsTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private Label $sent;

    /** You wrote three days ago; the reply came an hour ago and is unread. */
    private int $answered;

    /** You wrote yesterday; nobody answered. */
    private int $quiet;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
        $this->sent    = $this->seedLabel('Sent', LabelRole::Sent);

        $answered = $this->conversation('Site visit report');
        $this->own($answered, 'Recipient Alpha', 'My own words about the report', '-3 days');
        $this->reply($answered, 'Their reply to the report', '-1 hour');

        $quiet = $this->conversation('Invoice question');
        $this->own($quiet, 'Recipient Beta', 'My question about the invoice', '-1 day');

        $this->answered = (int) $answered->id;
        $this->quiet    = (int) $quiet->id;

        $this->em->clear();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSentRowIsDrawnFromYourOwnMail(): void
    {
        $row = $this->row('/mail/sent', $this->answered);

        self::assertStringContainsString('My own words', $row->text(), 'your text, not the reply\'s');
        self::assertStringNotContainsString('Their reply', $row->text());
        self::assertStringContainsString('Recipient Alpha', $row->text(), 'who you wrote to');
        self::assertSame('false', $row->attr('data-unread'), 'the unread reply does not make it bold');
    }

    /** The Inbox row is the conversation's, as before. */
    public function testTheInboxRowIsUnchanged(): void
    {
        $row = $this->row('/mail/inbox', $this->answered);

        self::assertStringContainsString('Their reply', $row->text());
        self::assertSame('true', $row->attr('data-unread'));
    }

    /**
     * The reply is the newest mail of all, and was on top — in the default
     * "Unread first" order all the more, being unread.
     */
    public function testSentIsInTheOrderYouSentIn(): void
    {
        self::assertSame([$this->quiet, $this->answered], $this->order('/mail/sent'), 'the default order');
        self::assertSame([$this->quiet, $this->answered], $this->order('/mail/sent?sort=newest'));
        self::assertSame([$this->quiet, $this->answered], $this->order('/mail/sent?sort=unread'));
        self::assertSame([$this->answered, $this->quiet], $this->order('/mail/sent?sort=oldest'));
    }

    /** One account's Sent folder in the sidebar is the label view of its Sent label. */
    public function testOneAccountsSentFolderIsDrawnAndOrderedTheSameWay(): void
    {
        $url = sprintf('/mail/label/%d?account=%d', $this->sent->id, $this->account->id);
        $row = $this->row($url, $this->answered);

        self::assertStringContainsString('My own words', $row->text());
        self::assertStringContainsString('Recipient Alpha', $row->text());
        self::assertSame([$this->quiet, $this->answered], $this->order($url));
    }

    /** A row redrawn after an action in Sent is the same row, not the conversation's. */
    public function testARowActionInSentRedrawsYourOwnMail(): void
    {
        $this->star($this->answered, ['scope' => 'sent', 'value' => '']);

        $stream = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('My own words', $stream);
        self::assertStringNotContainsString('Their reply', $stream);
        self::assertStringContainsString('Recipient Alpha', $stream);
    }

    public function testARowActionInTheInboxRedrawsTheConversation(): void
    {
        $this->star($this->answered, ['scope' => 'inbox', 'value' => '']);

        self::assertStringContainsString('Their reply', (string) $this->client->getResponse()->getContent());
    }

    /** Opened, the conversation is whole: this is about the row only. */
    public function testOpenedFromSentTheConversationShowsTheReplyToo(): void
    {
        $pane = $this->client->request('GET', '/mail/thread/' . $this->answered, [], [], ['HTTP_X_REQUESTED_WITH' => 'fetch']);

        self::assertCount(2, $pane->filter('[id^="thread_message_"]'));
    }

    /** An empty conversation, filled message by message below. */
    private function conversation(string $subject): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->category          = MessageCategory::Primary;
        $thread->messageCount      = 0;
        $thread->unreadCount       = 0;

        $this->em->persist($thread);

        return $thread;
    }

    private function own(MessageThread $thread, string $to, string $body, string $at): void
    {
        $message              = $this->message($thread, $body, $at);
        $message->fromAddress = $this->account->email;
        $message->toAddresses = [['name' => $to, 'address' => 'someone@example.test']];
        $message->seenAt      = new DateTimeImmutable($at);
        $message->addLabel($this->sent);
        $thread->addLabel($this->sent);
        $this->em->flush();
    }

    private function reply(MessageThread $thread, string $body, string $at): void
    {
        $message              = $this->message($thread, $body, $at);
        $message->fromAddress = 'someone@example.test';
        $message->fromName    = 'Someone Else';
        $message->toAddresses = [['name' => 'Marker Fixture', 'address' => $this->account->email]];
        $message->seenAt      = null;
        $message->addLabel($this->inbox);
        $thread->addLabel($this->inbox);
        $thread->unreadCount++;
        $this->em->flush();
    }

    private function message(MessageThread $thread, string $body, string $at): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = $thread->subject;
        $message->bodyText       = $body;
        $message->receivedAt     = new DateTimeImmutable($at);
        $message->sentAt         = new DateTimeImmutable($at);
        $message->flags          = [];
        $message->hasAttachments = false;

        $thread->addMessage($message);
        $thread->messageCount++;

        if (null === $thread->lastMessageAt || $message->receivedAt > $thread->lastMessageAt) {
            $thread->lastMessageAt = $message->receivedAt;
        }

        $this->em->persist($message);

        return $message;
    }

    private function row(string $list, int $thread): Crawler
    {
        $this->em->clear();

        $row = $this->client->request('GET', $list)->filter('#thread_' . $thread);

        self::assertCount(1, $row, sprintf('the conversation is listed in %s', $list));

        return $row;
    }

    /**
     * The fixture conversations in the order the list shows them.
     *
     * @return list<int>
     */
    private function order(string $list): array
    {
        $this->em->clear();

        $ids = $this->client->request('GET', $list)
            ->filter('li[id^="thread_"]')
            ->each(static fn (Crawler $row): string => (string) $row->attr('id'));

        $wanted = ['thread_' . $this->answered => $this->answered, 'thread_' . $this->quiet => $this->quiet];

        return array_values(array_map(
            static fn (string $id): int => $wanted[$id],
            array_filter($ids, static fn (string $id): bool => isset($wanted[$id])),
        ));
    }

    /** @param array<string, mixed> $body */
    private function star(int $thread, array $body): void
    {
        // The `ajax` token, read from the layout's meta tag as the real caller does.
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->em->clear();

        $this->client->request(
            'POST',
            sprintf('/status/thread/%d/star', $thread),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }
}
