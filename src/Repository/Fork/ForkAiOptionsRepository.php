<?php

declare(strict_types=1);

namespace App\Repository\Fork;

use App\Entity\Fork\ForkAiOptions;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * FORK (FORK.md)
 *
 * @extends ServiceEntityRepository<ForkAiOptions>
 */
final class ForkAiOptionsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForkAiOptions::class);
    }

    /** The row the admin form binds to, created on first sight. */
    public function currentOrNew(): ForkAiOptions
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? new ForkAiOptions();
    }

    /**
     * Read as a scalar, not as the entity: the workers live for hours, and an
     * entity already in their identity map would keep answering with whatever
     * it said when they started, long after an administrator changed it.
     */
    public function reasoningEnabled(): bool
    {
        $value = $this->createQueryBuilder('o')
            ->select('o.reasoningEnabled')
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return null === $value ? true : (bool) $value;
    }
}
