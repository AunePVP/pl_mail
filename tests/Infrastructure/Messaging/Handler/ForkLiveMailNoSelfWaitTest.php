<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Repository\Monitoring\MessengerQueueRepository;
use App\Service\Mail\PostIngest\EnrichmentRouter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * FORK (FORK.md): mail that has just arrived and is not held is asked about at
 * once, not after LiveMailPriority's 20 s ceiling.
 *
 * Unheld recent mail goes to enrich_live like held mail does. The handler took
 * every unheld message for bulk work and stood aside while enrich_live had
 * work in hand — and on a worker, the envelope it was handling is that work:
 * the doctrine transport keeps the row, delivered, until the handler returns.
 * So every such message waited for itself.
 *
 * The row the worker would hold is written by hand here, because the test
 * transport is in-memory and has no table.
 */
final class ForkLiveMailNoSelfWaitTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Never committed, so the suite leaves nothing behind.
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

    public function testUnheldMailOnTheLiveQueueDoesNotWaitForItself(): void
    {
        $this->assistantSorts();
        $id = $this->message();
        $this->envelopeInHandOn(EnrichmentRouter::LIVE);

        // Without this the test would pass on a queue nobody can read, where
        // LiveMailPriority fails open.
        self::assertGreaterThan(0, self::getContainer()->get(MessengerQueueRepository::class)->countActiveOn(EnrichmentRouter::LIVE));

        $started = microtime(true);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new ClassifyMailMessage([$id]),
            [new ReceivedStamp(EnrichmentRouter::LIVE)],
        );
        $elapsed = microtime(true) - $started;

        self::assertNotNull($this->connection->fetchOne('SELECT ai_call_ms FROM message WHERE id = :id', ['id' => $id]), 'the message must still be asked about');
        self::assertLessThan(10.0, $elapsed, 'live mail stood aside for the live queue it is on');
    }

    /** What the doctrine transport holds while a worker handles an envelope. */
    private function envelopeInHandOn(string $queue): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->connection->insert('messenger_messages', [
            'body'         => '{}',
            'headers'      => '{}',
            'queue_name'   => $queue,
            'created_at'   => $now->format('Y-m-d H:i:s'),
            'available_at' => $now->format('Y-m-d H:i:s'),
            'delivered_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function assistantSorts(): void
    {
        $settings = $this->em->getRepository(AiSettings::class)->findOneBy([]) ?? new AiSettings();

        $settings->isEnabled             = true;
        $settings->baseUrl               = 'http://model-host.invalid:11434';
        $settings->chatModel             = 'qwen3:4b-instruct';
        $settings->categorisationEnabled = true;

        $this->em->persist($settings);
        $this->em->flush();
    }

    private function message(): int
    {
        $message = new Message();
        $message->account = $this->mailbox->account;
        $message->mailbox = $this->mailbox;
        $message->subject = 'Self-wait fixture';
        $message->fromAddress = 'somebody@elsewhere.test';
        $message->receivedAt = new DateTimeImmutable();
        $message->hasAttachments = false;
        $message->messageId = sprintf('<self-wait-%s@example.test>', uniqid('', true));

        $this->em->persist($message);
        $this->em->flush();

        return (int) $message->id;
    }

    private function seed(): void
    {
        $user = new User();
        $user->email = 'self-wait-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Self';
        $user->nameLast = 'Wait';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'self-wait-fixture@example.test';
        $account->username = 'self-wait-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->mailbox = new Mailbox();
        $this->mailbox->account = $account;
        $this->mailbox->name = 'INBOX';
        $this->mailbox->fullPath = 'INBOX';
        $this->mailbox->isSyncEnabled = true;
        $this->mailbox->isIdleEnabled = false;
        $this->em->persist($this->mailbox);

        $this->em->flush();
    }
}
