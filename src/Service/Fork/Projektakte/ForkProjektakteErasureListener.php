<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use App\Domain\Enum\Fork\ForkPushAction;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * FORK (FORK.md 30): a filed mail erased for good, or the account it is in,
 * is taken out of Projektakte.
 *
 * Every way a message row leaves the database one at a time goes through the
 * ORM (MessageEraser: deleted for good, gone from the server, a duplicate
 * folded into its twin), and so does deleting an account (Settings →
 * Accounts; and with a user, by orphanRemoval), whose messages the database
 * takes by ON DELETE CASCADE. The assignment rows go the same way, which the
 * ORM never sees. So this watches the flush instead of any one caller, and
 * the original's code needs no line: in onFlush, while the assignments are
 * still in the table, it asks which of the messages being removed — or of the
 * accounts being removed — were filed; after the flush has committed
 * (postFlush) it queues a Remove for those. A flush that fails queues
 * nothing. The outbox has no foreign key to account or message, so the rows
 * outlive both until delivered.
 *
 * A data reset empties the tables with TRUNCATE, past the ORM; it calls
 * ForkProjektaktePush::queueRemovalOfFiled() itself (DataResetter).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ForkProjektakteErasureListener implements ResetInterface
{
    /** @var list<array{int, int}> account id and email id, waiting for the commit */
    private array $erased = [];

    public function __construct(
        private readonly ForkProjektakteClient $projektakte,
        private readonly ForkProjektaktePush   $push,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->erased = [];

        if (false === $this->projektakte->isAvailable()) {
            return;
        }

        $em = $args->getObjectManager();

        $messages = [];
        $accounts = [];

        foreach ($em->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Message && null !== $entity->id) {
                $messages[] = $entity->id;
            } elseif ($entity instanceof Account && null !== $entity->id) {
                $accounts[] = $entity->id;
            }
        }

        if ([] === $messages && [] === $accounts) {
            return;
        }

        $filed = $em->getConnection()->fetchAllNumeric(
            <<<'SQL'
                SELECT m.account_id, m.id
                  FROM fork_message_project p
                  JOIN message m ON m.id = p.message_id
                 WHERE p.status = 'assigned'
                   AND (m.id IN (:messages) OR m.account_id IN (:accounts))
                SQL,
            // IN () is not SQL; 0 is no id.
            ['messages' => [] === $messages ? [0] : $messages, 'accounts' => [] === $accounts ? [0] : $accounts],
            ['messages' => ArrayParameterType::INTEGER, 'accounts' => ArrayParameterType::INTEGER],
        );

        foreach ($filed as [$accountId, $messageId]) {
            $this->erased[] = [(int) $accountId, (int) $messageId];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->erased) {
            return;
        }

        $erased       = $this->erased;
        $this->erased = [];

        $this->push->queueIds($erased, ForkPushAction::Remove);
    }

    public function reset(): void
    {
        $this->erased = [];
    }
}
