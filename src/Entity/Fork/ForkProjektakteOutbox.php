<?php

declare(strict_types=1);

namespace App\Entity\Fork;

use App\Domain\Enum\Fork\ForkPushAction;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * FORK (FORK.md 30): one mail waiting to be sent to, changed in or taken out
 * of Projektakte.
 *
 * One row per mail at most (account and email id, unique): queuing a mail that
 * is already waiting replaces what it waits for, so only the latest state
 * leaves plMail. A row is deleted once Projektakte has taken it.
 *
 * Plain ids rather than relations to account and message: a Remove has to
 * outlive the message it names — it is queued because the message is being
 * erased.
 *
 * Mapped so the schema check sees the table; everything that writes it is SQL
 * in ForkProjektakteOutboxStore, because the queue lives on upserts and
 * row locks the ORM has no words for.
 *
 *   version       bumped by every queuing; a delivery that finishes with an
 *                 older version leaves the row for the newer state
 *   available_at  when it may next be tried
 *   locked_until  a courier is on it until then (so two never send the same
 *                 mail, and a crashed one lets go)
 */
#[ORM\Entity]
#[ORM\Table(name: 'fork_projektakte_outbox')]
#[ORM\UniqueConstraint(name: 'uniq_fork_projektakte_outbox_mail', columns: ['account_id', 'email_id'])]
#[ORM\Index(name: 'idx_fork_projektakte_outbox_due', columns: ['available_at'])]
class ForkProjektakteOutbox
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(name: 'account_id')]
    public private(set) int $accountId = 0;

    #[ORM\Column(name: 'email_id')]
    public private(set) int $emailId = 0;

    #[ORM\Column(length: 16, enumType: ForkPushAction::class)]
    public private(set) ForkPushAction $action = ForkPushAction::Send;

    #[ORM\Column]
    public private(set) int $version = 1;

    #[ORM\Column]
    public private(set) int $attempts = 0;

    #[ORM\Column(name: 'available_at')]
    public private(set) \DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'locked_until', nullable: true)]
    public private(set) ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    public private(set) ?string $lastError = null;

    #[ORM\Column(name: 'queued_at')]
    public private(set) \DateTimeImmutable $queuedAt;

    private function __construct()
    {
        $this->availableAt = new \DateTimeImmutable();
        $this->queuedAt    = new \DateTimeImmutable();
    }
}
