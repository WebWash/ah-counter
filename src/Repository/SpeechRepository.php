<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Speech;
use App\Entity\SpeechStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * @extends ServiceEntityRepository<Speech>
 */
class SpeechRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Speech::class);
    }

    /**
     * @return list<Speech> newest first
     */
    public function findRecent(int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Moves a failed Speech back to pending in one conditional UPDATE, so two Retry clicks can
     * never both win (SPEC §5). Returns whether a row changed.
     */
    public function resetFailedForRetry(Uuid $id): bool
    {
        $changed = $this->getEntityManager()->createQueryBuilder()
            ->update(Speech::class, 's')
            ->set('s.status', ':pending')
            ->set('s.error', 'NULL')
            ->set('s.updatedAt', ':now')
            ->where('s.id = :id')
            ->andWhere('s.status = :failed')
            ->setParameter('pending', SpeechStatus::Pending)
            ->setParameter('failed', SpeechStatus::Failed)
            ->setParameter('now', now(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->execute();

        return 1 === $changed;
    }
}
