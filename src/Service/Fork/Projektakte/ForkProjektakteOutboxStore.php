<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use App\Domain\Enum\Fork\ForkPushAction;
use Doctrine\DBAL\Connection;

/**
 * FORK (FORK.md 30): the Projektakte outbox, table fork_projektakte_outbox.
 *
 * SQL rather than the ORM, for three things the ORM cannot say:
 *
 *  - queuing is one upsert, so a mail queued twice is one row holding the
 *    later state ("only the latest state counts"), without a read first that
 *    two requests could both pass;
 *  - a courier claims a row with FOR UPDATE SKIP LOCKED and a lease
 *    (locked_until), so two never send the same mail at once and a crashed one
 *    lets go after the lease, and nothing holds a row lock while a 200 MB mail
 *    is uploading — the click that queues a change must never wait for that;
 *  - a delivery finishes only the version it delivered: queued again in the
 *    meantime, the row stays for the newer state.
 *
 * Times are written from PHP, never with SQL now(), so the comparisons stay in
 * one clock and one time zone.
 */
final readonly class ForkProjektakteOutboxStore
{
    /** As long as the slowest delivery may take (a 200 MB upload), and then some. */
    public const int LEASE_SECONDS = 1800;

    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Queue a mail, or change what it waits for. A Reassign does not replace a
     * Send that has not gone yet — Projektakte does not have the mail, so it
     * still needs all of it, and the courier reads the current assignment then.
     */
    public function put(int $accountId, int $emailId, ForkPushAction $action): void
    {
        $now = self::now()->format(self::FORMAT);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fork_projektakte_outbox (account_id, email_id, action, version, attempts, available_at, locked_until, last_error, queued_at)
                VALUES (:account, :email, :action, 1, 0, :now, NULL, NULL, :now)
                ON CONFLICT (account_id, email_id) DO UPDATE SET
                    action       = CASE WHEN fork_projektakte_outbox.action = 'send' AND EXCLUDED.action = 'reassign' THEN 'send' ELSE EXCLUDED.action END,
                    version      = fork_projektakte_outbox.version + 1,
                    attempts     = 0,
                    available_at = EXCLUDED.available_at,
                    last_error   = NULL
                SQL,
            ['account' => $accountId, 'email' => $emailId, 'action' => $action->value, 'now' => $now],
        );
    }

    /**
     * A Send for every mail filed under a project, in one statement: the
     * resend button. A mail already waiting is not queued twice; it waits for
     * a Send now, whatever it waited for before.
     *
     * @return int how many mails are filed (and so queued)
     */
    public function putAllAssigned(): int
    {
        $now = self::now()->format(self::FORMAT);

        return (int) $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fork_projektakte_outbox (account_id, email_id, action, version, attempts, available_at, locked_until, last_error, queued_at)
                SELECT m.account_id, m.id, 'send', 1, 0, :now, NULL, NULL, :now
                  FROM fork_message_project p
                  JOIN message m ON m.id = p.message_id
                 WHERE p.status = 'assigned'
                ON CONFLICT (account_id, email_id) DO UPDATE SET
                    action       = 'send',
                    version      = fork_projektakte_outbox.version + 1,
                    attempts     = 0,
                    available_at = EXCLUDED.available_at,
                    last_error   = NULL
                SQL,
            ['now' => $now],
        );
    }

    /**
     * A Remove for every filed mail — of one account, or of all — before
     * their rows go wholesale (an account deleted, a data reset), which the
     * mails' own deletion would never announce. Queued in one statement while
     * the assignments are still there to say which mails were filed.
     *
     * @return int how many were queued
     */
    public function putRemovalOfFiled(?int $accountId = null): int
    {
        $now = self::now()->format(self::FORMAT);

        return (int) $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    INSERT INTO fork_projektakte_outbox (account_id, email_id, action, version, attempts, available_at, locked_until, last_error, queued_at)
                    SELECT m.account_id, m.id, 'remove', 1, 0, :now, NULL, NULL, :now
                      FROM fork_message_project p
                      JOIN message m ON m.id = p.message_id
                     WHERE p.status = 'assigned'%s
                    ON CONFLICT (account_id, email_id) DO UPDATE SET
                        action       = 'remove',
                        version      = fork_projektakte_outbox.version + 1,
                        attempts     = 0,
                        available_at = EXCLUDED.available_at,
                        last_error   = NULL
                    SQL,
                null === $accountId ? '' : ' AND m.account_id = :account',
            ),
            null === $accountId ? ['now' => $now] : ['now' => $now, 'account' => $accountId],
        );
    }

    /**
     * Take the next row that is due and nobody is on, for one lease.
     *
     * @return array{id: int, accountId: int, emailId: int, action: ForkPushAction, version: int, attempts: int}|null
     */
    public function claim(): ?array
    {
        $now = self::now();

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE fork_projektakte_outbox SET locked_until = :lease
                 WHERE id = (
                    SELECT id FROM fork_projektakte_outbox
                     WHERE available_at <= :now AND (locked_until IS NULL OR locked_until < :now)
                     ORDER BY available_at, id
                     LIMIT 1
                       FOR UPDATE SKIP LOCKED
                 )
                RETURNING id, account_id, email_id, action, version, attempts
                SQL,
            ['now' => $now->format(self::FORMAT), 'lease' => $now->modify(sprintf('+%d seconds', self::LEASE_SECONDS))->format(self::FORMAT)],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id'        => (int) $row['id'],
            'accountId' => (int) $row['account_id'],
            'emailId'   => (int) $row['email_id'],
            'action'    => ForkPushAction::tryFrom((string) $row['action']) ?? ForkPushAction::Send,
            'version'   => (int) $row['version'],
            'attempts'  => (int) $row['attempts'],
        ];
    }

    /** Delivered (or given up on): gone, unless it was queued again meanwhile. */
    public function finish(int $id, int $version): void
    {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM fork_projektakte_outbox WHERE id = :id AND version = :version',
            ['id' => $id, 'version' => $version],
        );

        if (0 === $deleted) {
            $this->release($id);
        }
    }

    /** Not delivered: try again at $at, unless it was queued again meanwhile (then at once). */
    public function retry(int $id, int $version, \DateTimeImmutable $at, string $error): void
    {
        $updated = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fork_projektakte_outbox
                   SET attempts = attempts + 1, available_at = :at, last_error = :error, locked_until = NULL
                 WHERE id = :id AND version = :version
                SQL,
            ['id' => $id, 'version' => $version, 'at' => $at->format(self::FORMAT), 'error' => mb_substr($error, 0, 2000)],
        );

        if (0 === $updated) {
            $this->release($id);
        }
    }

    /** Rows waiting, due or not. */
    public function count(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM fork_projektakte_outbox');
    }

    /** Whether anything is due that no courier is on — what the minute tick asks. */
    public function hasDue(): bool
    {
        $now = self::now()->format(self::FORMAT);

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM fork_projektakte_outbox WHERE available_at <= :now AND (locked_until IS NULL OR locked_until < :now) LIMIT 1',
            ['now' => $now],
        );
    }

    /**
     * The newest error among the rows still waiting, for the settings.
     *
     * @return array{error: string, emailId: int, attempts: int, next: \DateTimeImmutable}|null
     */
    public function newestError(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT email_id, attempts, available_at, last_error FROM fork_projektakte_outbox WHERE last_error IS NOT NULL ORDER BY available_at DESC LIMIT 1',
        );

        if (false === $row) {
            return null;
        }

        return [
            'error'    => (string) $row['last_error'],
            'emailId'  => (int) $row['email_id'],
            'attempts' => (int) $row['attempts'],
            'next'     => new \DateTimeImmutable((string) $row['available_at']),
        ];
    }

    private function release(int $id): void
    {
        $this->connection->executeStatement('UPDATE fork_projektakte_outbox SET locked_until = NULL WHERE id = :id', ['id' => $id]);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
