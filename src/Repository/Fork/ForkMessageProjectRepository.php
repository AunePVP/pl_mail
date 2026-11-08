<?php

declare(strict_types=1);

namespace App\Repository\Fork;

use App\Entity\Fork\ForkMessageProject;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * FORK (FORK.md 27)
 *
 * Every read is by a set of messages in one query, keyed by message id: JMAP
 * asks for up to 500 mails at a time, and a conversation draws a chip per
 * message.
 *
 * @extends ServiceEntityRepository<ForkMessageProject>
 */
final class ForkMessageProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForkMessageProject::class);
    }

    public function forMessage(Message $message): ?ForkMessageProject
    {
        return $this->findOneBy(['message' => $message]);
    }

    /**
     * @param list<int> $messageIds
     *
     * @return array<int, ForkMessageProject> by message id; a message without a row is absent
     */
    public function findByMessageIds(array $messageIds): array
    {
        if ([] === $messageIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.message) IN (:ids)')
            ->setParameter('ids', array_values(array_unique($messageIds)))
            ->getQuery()
            ->getResult();

        return self::byMessage($rows);
    }

    /**
     * Every assignment in one conversation, for the chips of all its messages.
     *
     * @return array<int, ForkMessageProject> by message id
     */
    public function findByThread(MessageThread $thread): array
    {
        $rows = $this->createQueryBuilder('p')
            ->join('p.message', 'm')
            ->andWhere('m.thread = :thread')
            ->setParameter('thread', $thread)
            ->getQuery()
            ->getResult();

        return self::byMessage($rows);
    }

    /**
     * @param iterable<ForkMessageProject> $rows
     *
     * @return array<int, ForkMessageProject>
     */
    private static function byMessage(iterable $rows): array
    {
        $byMessage = [];

        foreach ($rows as $row) {
            $byMessage[(int) $row->message->id] = $row;
        }

        return $byMessage;
    }
}
