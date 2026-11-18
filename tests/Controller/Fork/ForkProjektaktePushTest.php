<?php

declare(strict_types=1);

namespace App\Tests\Controller\Fork;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\RawMessageStorage;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Method\Mail\EmailGetMethod;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\Session\SessionBuilder;
use App\Service\Fork\Projektakte\ForkEmlHeaders;
use App\Service\Fork\Projektakte\ForkProjektakteCourier;
use App\Service\Fork\Projektakte\ForkProjektaktePushMessage;
use App\Service\Fork\Projektakte\ForkProjektaktePushTick;
use App\Service\Mail\MessageEraser;
use App\Service\Maintenance\DataResetter;
use App\Service\Maintenance\ResetScope;
use App\Tests\Support\Fork\FakeProjektakte;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * FORK (FORK.md 30): filed mail pushed to Projektakte — through the outbox,
 * delivered in the background, only the latest state of each mail, nothing
 * that is not filed. Projektakte is a fake answering its contract
 * (FakeProjektakte, docs/plmail-push.md over there).
 */
final class ForkProjektaktePushTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private FakeProjektakte $projektakte;

    /** @var list<string> raw files written by a test, removed after it */
    private array $files = [];

    protected function setUp(): void
    {
        $this->projektakte = new FakeProjektakte();
        // A name beyond ASCII, so the header has something to escape.
        $this->projektakte->projects[1]['name'] = 'Rathaus Überlingen';
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

        foreach ($this->files as $file) {
            @unlink($file);
        }

        FakeProjektakte::configure(null);

        parent::tearDown();
    }

    public function testFilingSendsTheMailOnceWithItsAssignment(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->pushes(), 'nothing is sent while the click waits');
        self::assertSame([['send', 0]], $this->outbox());

        $this->deliver();

        $pushes = $this->pushes();
        self::assertCount(1, $pushes);
        self::assertSame('PUT', $pushes[0]['method']);
        self::assertSame(sprintf('/api/integration/mails/%d/%d', $this->account->id, $message->id), $pushes[0]['path']);
        self::assertSame('Authorization: Bearer ' . FakeProjektakte::TOKEN, $pushes[0]['auth']);
        self::assertSame('Content-Type: message/rfc822', $pushes[0]['headers']['content-type'][0] ?? null);
        // No raw source stored: the reconstruction the JMAP blob download
        // falls back to (MessageSourceBuilder), from what the row holds.
        self::assertStringContainsString('Anbei das Angebot zum Rathaus.', $pushes[0]['raw']);

        $header = (string) ($pushes[0]['headers']['x-projektakte-zuordnung'][0] ?? '');
        self::assertSame(1, preg_match('/^[\x20-\x7e]+$/', $header), 'the header is ASCII');
        self::assertStringContainsString('\u00dcberlingen', $header);

        $held = $this->projektakte->mails[$this->key($message)] ?? null;
        self::assertNotNull($held);
        self::assertSame('assigned', $held['assignment']['status']);
        self::assertSame('BS-0000-0001', $held['assignment']['project']);
        self::assertSame('Rathaus Überlingen', $held['assignment']['projectName']);
        self::assertSame('Marker Fixture', $held['assignment']['assignedBy']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $held['assignment']['assignedAt']);

        self::assertSame([], $this->outbox(), 'delivered, so no longer waiting');

        $this->deliver();
        self::assertCount(1, $this->pushes(), 'and sent once');
    }

    /** The genuine bytes when plMail has them, not a reconstruction — and nothing added when nothing is missing. */
    public function testTheRawSourceGoesOutAsItIs(): void
    {
        $message = $this->seedMessage();
        $raw     = "Subject: Angebot BS-0000-0001\r\nFrom: Planer <planer@example.test>\r\nTo: buero@example.test\r\n"
            . "Date: Tue, 17 Nov 2026 09:00:00 +0100\r\nMessage-ID: <original@example.test>\r\nX-Original: yes\r\n\r\nGenau diese Bytes.\r\n";
        $storage = self::getContainer()->get(RawMessageStorage::class);

        $message->rawPath = $storage->store((int) $this->account->id, (int) $message->id, $raw);
        $this->files[]    = $storage->getAbsolutePath($message->rawPath);
        $this->em->flush();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();

        self::assertSame($raw, $this->pushes()[0]['raw'] ?? null);
        self::assertSame('Content-Length: ' . strlen($raw), $this->pushes()[0]['headers']['content-length'][0] ?? null);
    }

    /**
     * Projektakte reads subject, sender and date from the headers. A mail
     * whose source lacks them (here: the reconstruction, from a row that kept
     * no headers) gets them from plMail's own fields.
     */
    public function testEveryHeaderProjektakteReadsIsThere(): void
    {
        $message               = $this->seedMessage();
        $message->subject      = 'Prüfbericht Brandschutz';
        $message->toAddresses  = [['address' => 'buero@example.test', 'name' => 'Büro']];
        $message->ccAddresses  = [['address' => 'statik@example.test', 'name' => null], ['email' => 'kaputt', 'name' => 'x']];
        $message->messageId    = '<pruef-1@example.test>';
        $message->sentAt       = new DateTimeImmutable('2026-11-17 08:30:00', new \DateTimeZone('UTC'));
        $this->em->flush();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();

        $sent    = (string) ($this->pushes()[0]['raw'] ?? '');
        $headers = $this->headersOf($sent);

        self::assertSame('Prüfbericht Brandschutz', $headers['subject'] ?? null);
        self::assertSame('Planer <planer@example.test>', $headers['from'] ?? null);
        self::assertSame('Büro <buero@example.test>', $headers['to'] ?? null);
        self::assertSame('statik@example.test', $headers['cc'] ?? null, 'an address that is not one is left out, the rest goes');
        self::assertSame('Tue, 17 Nov 2026 08:30:00 +0000', $headers['date'] ?? null, 'plMail keeps times in UTC');
        self::assertSame('<pruef-1@example.test>', $headers['message-id'] ?? null);
        self::assertStringContainsString('Anbei das Angebot zum Rathaus.', $sent);
        self::assertSame('Content-Length: ' . strlen($sent), $this->pushes()[0]['headers']['content-length'][0] ?? null);
    }

    /** Only what is missing is added; the source's own lines and bytes stay as they were. */
    public function testAStoredSourceGetsOnlyTheHeadersItLacks(): void
    {
        $message              = $this->seedMessage();
        $message->ccAddresses = [['address' => 'statik@example.test', 'name' => 'Statik']];
        $message->messageId   = null;
        $raw                  = "Subject: Original-Betreff\nFrom: jemand@example.test\nTo: buero@example.test\nDate: Tue, 17 Nov 2026 09:00:00 +0100\n\nText.\n";
        $storage              = self::getContainer()->get(RawMessageStorage::class);

        $message->rawPath = $storage->store((int) $this->account->id, (int) $message->id, $raw);
        $this->files[]    = $storage->getAbsolutePath($message->rawPath);
        $this->em->flush();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();

        $sent = (string) ($this->pushes()[0]['raw'] ?? '');
        self::assertStringEndsWith($raw, $sent, 'the source follows untouched');

        $added = substr($sent, 0, -strlen($raw));
        self::assertSame(['Cc', 'Message-ID'], array_map(static fn (string $line): string => strstr($line, ':', true), array_filter(explode("\n", $added))));
        self::assertStringNotContainsString("\r", $added, 'in the source\'s own line ending');

        $headers = $this->headersOf($sent);
        self::assertSame('Original-Betreff', $headers['subject'] ?? null, 'the source\'s subject, not the row\'s');
        self::assertSame('Statik <statik@example.test>', $headers['cc'] ?? null);
        self::assertSame(sprintf('<plmail.%d.%d@projektakte.invalid>', $this->account->id, $message->id), $headers['message-id'] ?? null, 'made up, the same each time');
    }

    /** The keys Projektakte already holds mails under, from its JMAP days: anything else would be a second copy. */
    public function testTheIdsAreTheJmapIds(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();

        $user      = $this->em->find(User::class, $this->user->id);
        $session   = self::getContainer()->get(SessionBuilder::class)->build($user);
        $accountId = (string) $this->account->id;
        self::assertArrayHasKey($accountId, $session['accounts']);

        $emails = self::getContainer()->get(EmailGetMethod::class)->handle(
            ['accountId' => $accountId, 'ids' => [(string) $message->id], 'properties' => ['id']],
            new JmapContext($user),
        );
        $emailId = $emails['list'][0]['id'] ?? null;
        self::assertSame((string) $message->id, $emailId);

        self::assertSame(sprintf('/api/integration/mails/%s/%s', $accountId, $emailId), $this->pushes()[0]['path']);
    }

    /** The account goes, its messages with it by cascade; Projektakte hears of every filed one. */
    public function testDeletingTheAccountTakesItsFiledMailOut(): void
    {
        $first  = $this->seedMessage();
        $second = $this->seedMessage();
        $this->seedMessage();

        $this->choose($first, ['number' => 'BS-0000-0001']);
        $this->choose($second, ['number' => 'BS-0000-0002']);
        $this->deliver();
        self::assertCount(2, $this->projektakte->mails);

        $this->em->remove($this->em->find(Account::class, $this->account->id));
        $this->em->flush();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM message WHERE account_id = ?', [$this->account->id]));
        self::assertSame([['remove', 0], ['remove', 0]], $this->outbox(), 'queued, and still there with the account gone');

        self::getContainer()->get(ForkProjektakteCourier::class)->deliverDue(30.0);

        self::assertSame(['PUT /mails', 'PUT /mails', 'DELETE /mails', 'DELETE /mails'], $this->shape());
        self::assertSame([], $this->projektakte->mails);
        self::assertSame([], $this->outbox());
    }

    /** A data reset empties `message` by TRUNCATE, past the ORM; it queues the DELETEs first itself. */
    public function testADataResetTakesFiledMailOut(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();

        self::getContainer()->get(DataResetter::class)->reset(new ResetScope());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM message'));
        self::assertSame([['remove', 0]], $this->outbox());

        $this->em->clear();
        self::getContainer()->get(ForkProjektakteCourier::class)->deliverDue(30.0);

        self::assertSame(['PUT /mails', 'DELETE /mails'], $this->shape());
        self::assertSame([], $this->projektakte->mails);
    }

    public function testMovingToAnotherProjectSendsOnlyTheNewAssignment(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();
        $this->choose($message, ['number' => 'BS-0000-0002']);

        self::assertSame([['reassign', 0]], $this->outbox());

        $this->deliver();

        $pushes = $this->pushes();
        self::assertCount(2, $pushes);
        self::assertSame('PUT', $pushes[1]['method']);
        self::assertSame(sprintf('/api/integration/mails/%d/%d/zuordnung', $this->account->id, $message->id), $pushes[1]['path']);
        self::assertSame('BS-0000-0002', $pushes[1]['body']['project']);
        self::assertSame('Schule Nord', $this->projektakte->mails[$this->key($message)]['assignment']['projectName']);
    }

    /** 404 on …/zuordnung: Projektakte lost it, or never had it — send all of it. */
    public function testAMailProjektakteDoesNotHaveIsSentWhole(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();
        $this->projektakte->mails = [];
        $this->choose($message, ['number' => 'BS-0000-0002']);
        $this->deliver();

        self::assertSame(['PUT /mails', 'PUT /zuordnung', 'PUT /mails'], $this->shape());
        self::assertSame('BS-0000-0002', $this->projektakte->mails[$this->key($message)]['assignment']['project']);
        self::assertSame([], $this->outbox());
    }

    public function testNotAboutAProjectTakesItOut(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();
        $this->choose($message, ['none' => true]);
        $this->deliver();

        self::assertSame(['PUT /mails', 'DELETE /mails'], $this->shape());
        self::assertArrayNotHasKey($this->key($message), $this->projektakte->mails);
    }

    public function testTakingTheAssignmentBackTakesItOut(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();
        $this->choose($message, null, 'DELETE');
        $this->deliver();

        self::assertSame(['PUT /mails', 'DELETE /mails'], $this->shape());
        self::assertArrayNotHasKey($this->key($message), $this->projektakte->mails);
    }

    public function testErasingAFiledMailTakesItOut(): void
    {
        $message = $this->seedMessage();
        $other   = $this->seedMessage();
        $key     = $this->key($message);

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->deliver();

        $eraser = self::getContainer()->get(MessageEraser::class);
        $eraser->erase($this->em->find(Message::class, $message->id));
        $eraser->erase($this->em->find(Message::class, $other->id));
        $this->em->flush();

        self::assertSame([['remove', 0]], $this->outbox(), 'only the filed one');

        $this->deliver();

        self::assertSame(['PUT /mails', 'DELETE /mails'], $this->shape());
        self::assertArrayNotHasKey($key, $this->projektakte->mails);
    }

    public function testMailNobodyFiledIsNeverSent(): void
    {
        $this->seedMessage();
        $none = $this->seedMessage();

        $this->choose($none, ['none' => true]);
        $this->client->request('GET', sprintf('/mail/message/%d/fork/project', $none->id));
        $this->deliver();

        // "Not about a project" says so to Projektakte by id (it may hold an
        // older copy); nothing of either mail is sent.
        self::assertSame(['DELETE /mails'], $this->shape());
        self::assertSame([], array_filter($this->projektakte->calls, static fn (array $call): bool => '' !== $call['raw'] && 'PUT' === $call['method']));
    }

    public function testFiledAndTakenBackBeforeDeliveryIsOneDelete(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->choose($message, null, 'DELETE');

        self::assertSame([['remove', 0]], $this->outbox(), 'one row, the later state');

        $this->deliver();

        self::assertSame(['DELETE /mails'], $this->shape());
    }

    /** Filed, moved, before anything went out: still the whole mail, with the new project. */
    public function testMovedBeforeDeliveryIsStillTheWholeMail(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->choose($message, ['number' => 'BS-0000-0002']);
        $this->deliver();

        self::assertSame(['PUT /mails'], $this->shape());
        self::assertSame('BS-0000-0002', $this->projektakte->mails[$this->key($message)]['assignment']['project']);
    }

    public function testAnOutageKeepsTheMailQueuedUntilProjektakteIsBack(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->projektakte->down = true;
        $this->deliver();

        self::assertSame([['send', 1]], $this->outbox());
        self::assertStringStartsWith('no connection', (string) $this->connection->fetchOne('SELECT last_error FROM fork_projektakte_outbox'));
        $wait = $this->secondsUntilDue();
        self::assertGreaterThan(50, $wait, 'a minute after the first failure');
        self::assertLessThan(70, $wait);

        $calls = count($this->projektakte->calls);
        $this->deliver();
        self::assertCount($calls, $this->projektakte->calls, 'not tried again before its time');

        $this->due();
        $this->projektakte->down      = false;
        $this->projektakte->pushFails = true;
        $this->deliver();

        self::assertSame([['send', 2]], $this->outbox(), '5xx is tried again too');
        self::assertGreaterThan(110, $this->secondsUntilDue(), 'and the wait grows');

        // A restart: nothing in memory survives, the row does. The minute
        // tick finds it and wakes a courier.
        $this->em->clear();
        $this->due();
        $this->projektakte->pushFails = false;

        $transport = self::getContainer()->get('messenger.transport.maintenance');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        self::getContainer()->get(ForkProjektaktePushTick::class)->tick();
        self::assertCount(1, $transport->getSent());
        self::assertInstanceOf(ForkProjektaktePushMessage::class, $transport->getSent()[0]->getMessage());

        $this->deliver();

        self::assertSame([], $this->outbox());
        self::assertSame('BS-0000-0001', $this->projektakte->mails[$this->key($message)]['assignment']['project'] ?? null);
        self::assertSame('HTTP 500: boom', self::getContainer()->get(ForkProjektakteCourier::class)->lastError()['error'] ?? null);
    }

    public function testARefusalIsNotRepeated(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->projektakte->pushStatus = 400;
        $this->deliver();

        self::assertSame([], $this->outbox(), 'dropped, not tried again');
        self::assertSame('HTTP 400: refused', self::getContainer()->get(ForkProjektakteCourier::class)->lastError()['error'] ?? null);
    }

    public function testATokenProjektakteRefusesIsTriedAgain(): void
    {
        $message = $this->seedMessage();

        $this->choose($message, ['number' => 'BS-0000-0001']);
        $this->projektakte->pushStatus = 401;
        $this->deliver();

        self::assertSame([['send', 1]], $this->outbox(), 'someone can fix a token; the mail should still arrive then');
    }

    public function testSendAllSendsEveryFiledMailExactlyOnce(): void
    {
        $first  = $this->seedMessage();
        $second = $this->seedMessage();
        $none   = $this->seedMessage();
        $this->seedMessage();

        $this->choose($first, ['number' => 'BS-0000-0001']);
        $this->choose($second, ['number' => 'BS-0000-0002']);
        $this->choose($none, ['none' => true]);
        $this->deliver();
        $this->projektakte->calls = [];
        $this->projektakte->mails = [];

        $this->becomeAdmin();
        $crawler = $this->client->request('GET', '/admin/fork/projektakte');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-fork-projektakte-reachable="yes"]');
        self::assertSelectorExists('[data-fork-projektakte-waiting="0"]');

        $form = $crawler->filter('[data-fork-projektakte-resend-button]')->form();
        $this->client->submit($form);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/fork/projektakte');
        self::assertSame([['send', 0], ['send', 0]], $this->outbox(), 'one row each, however often the button is pressed');

        $this->client->request('GET', '/admin/fork/projektakte');
        self::assertSelectorExists('[data-fork-projektakte-waiting="2"]');
        self::assertSelectorExists('[data-controller="fork--frame-refresh"]');
        self::assertSelectorTextContains('[data-fork-projektakte-resend]', '0 of 2');

        $this->deliver();

        $paths = array_column($this->pushes(), 'path');
        sort($paths);
        self::assertSame([
            sprintf('/api/integration/mails/%d/%d', $this->account->id, $first->id),
            sprintf('/api/integration/mails/%d/%d', $this->account->id, $second->id),
        ], $paths);

        $this->client->request('GET', '/admin/fork/projektakte');
        self::assertSelectorExists('[data-fork-projektakte-waiting="0"]');
        self::assertSelectorNotExists('[data-controller="fork--frame-refresh"]');
        self::assertSelectorTextContains('[data-fork-projektakte-resend]', 'all 2 delivered');
    }

    public function testTheSectionSaysWhenProjektakteIsDown(): void
    {
        $this->becomeAdmin();
        $this->projektakte->down = true;

        $this->client->request('GET', '/admin/fork/projektakte');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-fork-projektakte-reachable="no"]');
    }

    public function testTheSectionIsForAdminsOnly(): void
    {
        $this->client->request('GET', '/admin/fork/projektakte');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheIntegrationsPageCarriesTheSection(): void
    {
        $this->becomeAdmin();

        $this->client->request('GET', '/admin/integrations');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#fork-projektakte-admin[src="/admin/fork/projektakte"]');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @param array<string, mixed>|null $body */
    private function choose(Message $message, ?array $body, string $method = 'POST'): void
    {
        $this->client->request(
            $method,
            sprintf('/mail/message/%d/fork/project', $message->id),
            server: ['HTTP_X_CSRF_TOKEN' => $this->token(), 'CONTENT_TYPE' => 'application/json'],
            content: null === $body ? null : (string) json_encode($body),
        );

        self::assertResponseIsSuccessful();
    }

    /** What the background does, now; the fixtures are fetched again after it, because it clears the entity manager. */
    private function deliver(): void
    {
        self::getContainer()->get(ForkProjektakteCourier::class)->deliverDue(30.0);

        $this->user    = $this->em->find(User::class, $this->user->id);
        $this->account = $this->em->find(Account::class, $this->account->id);
        $this->inbox   = $this->em->find(Label::class, $this->inbox->id);
    }

    /** As if the time had come. */
    private function due(): void
    {
        $this->connection->executeStatement("UPDATE fork_projektakte_outbox SET available_at = available_at - interval '2 hours'");
    }

    private function secondsUntilDue(): int
    {
        return (int) (new DateTimeImmutable((string) $this->connection->fetchOne('SELECT available_at FROM fork_projektakte_outbox'))->getTimestamp() - time());
    }

    /** @return list<array{string, int}> action and attempts of each row */
    private function outbox(): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['action'], (int) $row['attempts']],
            $this->connection->fetchAllAssociative('SELECT action, attempts FROM fork_projektakte_outbox ORDER BY id'),
        );
    }

    /** @return list<array<string, mixed>> the calls to the push endpoints */
    private function pushes(): array
    {
        return array_values(array_filter(
            $this->projektakte->calls,
            static fn (array $call): bool => str_starts_with($call['path'], '/api/integration/mails/'),
        ));
    }

    /** @return list<string> e.g. "PUT /mails", "PUT /zuordnung", "DELETE /mails" */
    private function shape(): array
    {
        return array_map(
            static fn (array $call): string => $call['method'] . (str_ends_with($call['path'], '/zuordnung') ? ' /zuordnung' : ' /mails'),
            $this->pushes(),
        );
    }

    /** @return array<string, string> the header block, unfolded and decoded, by lower-case name */
    private function headersOf(string $eml): array
    {
        $decoded = iconv_mime_decode_headers(ForkEmlHeaders::headerBlock($eml), \ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        self::assertIsArray($decoded);

        $headers = [];

        foreach ($decoded as $name => $value) {
            $headers[strtolower((string) $name)] = true === is_array($value) ? implode(', ', $value) : $value;
        }

        return $headers;
    }

    private function key(Message $message): string
    {
        return sprintf('%d/%d', $this->account->id, $message->id);
    }

    private function becomeAdmin(): void
    {
        $user        = $this->em->find(User::class, $this->user->id);
        $user->roles = ['ROLE_USER', 'ROLE_ADMIN'];
        $this->em->flush();

        $this->client->loginUser($user);
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
