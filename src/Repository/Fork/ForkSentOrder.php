<?php

declare(strict_types=1);

namespace App\Repository\Fork;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListSortOrder;
use App\Entity\Mail\Message;
use Doctrine\ORM\QueryBuilder;

/**
 * FORK (FORK.md 29): the Sent list in the order you sent, not the order replies came.
 *
 * Every list sorts on `MessageThread::$lastMessageAt`, which a reply moves. In
 * Sent that put a conversation you wrote to a month ago on top the morning
 * somebody answered, beside rows from today. Here the key is the newest
 * message of the conversation that carries Sent, dated as its row shows it
 * (ThreadRowMessage::effectiveAt(): arrival, else sending, else creation).
 *
 * "Unread first" ranks nothing here. The row is drawn from your own mail
 * (ForkSentView), and your own mail is filed read — what was unread in a Sent
 * conversation was always somebody's reply, the very thing this keeps off the
 * row. So in Sent it orders like "Newest first", which also spares the list a
 * second correlated sub-query.
 *
 * A HIDDEN select for the reason ListSortOrder::applyTo() gives: the list
 * queries are DISTINCT, and PostgreSQL wants every ORDER BY expression of a
 * DISTINCT query in its select list. The id tiebreaker is kept.
 */
final class ForkSentOrder
{
    public static function applyTo(QueryBuilder $qb, ListSortOrder $sort, string $alias = 't'): QueryBuilder
    {
        $direction = ListSortOrder::Oldest === $sort ? 'ASC' : 'DESC';

        return $qb
            ->addSelect(sprintf(
                '(SELECT MAX(COALESCE(forkSent.receivedAt, forkSent.sentAt, forkSent.createdAt))
                    FROM %s forkSent JOIN forkSent.labels forkSentLabel
                    WHERE forkSent.thread = %s AND forkSentLabel.role = :forkSentRole) AS HIDDEN forkLastSentAt',
                Message::class,
                $alias,
            ))
            ->setParameter('forkSentRole', LabelRole::Sent)
            ->orderBy('forkLastSentAt', $direction)
            ->addOrderBy($alias . '.id', $direction);
    }
}
