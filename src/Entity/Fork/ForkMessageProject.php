<?php

declare(strict_types=1);

namespace App\Entity\Fork;

use App\Domain\Enum\Fork\ForkProjectStatus;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Fork\ForkMessageProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * FORK (FORK.md 27): the Projektakte project a person filed one message under,
 * or that they said it belongs to none.
 *
 * One row per message at most; no row is "not decided yet", and taking an
 * assignment off deletes the row rather than marking it. Per message, not per
 * conversation: a thread about one building routinely carries a reply about
 * another.
 *
 * A table of its own rather than a column on `message`, so an update from the
 * original can never meet a column or a migration of ours on its table. It
 * hangs off the message row, not off where the message is: moving the mail to
 * another folder keeps it, and nothing of it is ever written back to the mail
 * server (no keyword, no label, no flag).
 *
 * The project's name is kept as it was when assigned, so JMAP can answer
 * without asking Projektakte, and so can the chip when Projektakte is down. So
 * is the name of whoever assigned it: the user row may go, the record that a
 * person decided it should not.
 *
 * assignedAt is updated_at: the row only ever changes by being assigned again.
 */
#[ORM\Entity(repositoryClass: ForkMessageProjectRepository::class)]
#[ORM\Table(name: 'fork_message_project')]
#[ORM\UniqueConstraint(name: 'uniq_fork_message_project_message', columns: ['message_id'])]
#[ORM\Index(name: 'idx_fork_message_project_project', columns: ['project_number'])]
#[ORM\HasLifecycleCallbacks]
class ForkMessageProject
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Message $message;

    #[ORM\Column(length: 16, enumType: ForkProjectStatus::class)]
    public private(set) ForkProjectStatus $status = ForkProjectStatus::None;

    /** Null exactly when the status is None. */
    #[ORM\Column(name: 'project_number', length: 64, nullable: true)]
    public private(set) ?string $projectNumber = null;

    /** As Projektakte named the project at the time; empty for None. */
    #[ORM\Column(name: 'project_name', type: Types::TEXT)]
    public private(set) string $projectName = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assigned_by_id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?User $assignedBy = null;

    /** Who decided, as a name that outlives the user row. */
    #[ORM\Column(name: 'assigned_by_name', type: Types::TEXT)]
    public private(set) string $assignedByName = '';

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    public function assign(string $number, string $name, User $by): void
    {
        $this->status        = ForkProjectStatus::Assigned;
        $this->projectNumber = $number;
        $this->projectName   = $name;
        $this->decidedBy($by);
    }

    public function markNone(User $by): void
    {
        $this->status        = ForkProjectStatus::None;
        $this->projectNumber = null;
        $this->projectName   = '';
        $this->decidedBy($by);
    }

    /**
     * The value of the JMAP property `plmail:projektakte`, as Projektakte reads
     * it: UTC with a Z, the way every date on an Email is.
     *
     * @return array{status: string, project: ?string, projectName: string, assignedBy: string, assignedAt: string}
     */
    public function toJmap(): array
    {
        return [
            'status'      => $this->status->value,
            'project'     => $this->projectNumber,
            'projectName' => $this->projectName,
            'assignedBy'  => $this->assignedByName,
            'assignedAt'  => $this->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * The name a person goes by here: their own, else the address they sign in
     * with. Recorded on every decision, so a later one by someone else says so.
     */
    private function decidedBy(User $by): void
    {
        $name = trim(sprintf('%s %s', $by->nameFirst ?? '', $by->nameLast ?? ''));

        $this->assignedBy     = $by;
        $this->assignedByName = '' !== $name ? $name : $by->getUserIdentifier();
    }
}
