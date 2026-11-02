<?php

namespace App\Repository;

use App\Entity\DiscountCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscountCode>
 */
class DiscountCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscountCode::class);
    }

    /**
     * Active, not expired, and under its usage limit. Minimum spend is checked by the caller.
     */
    public function findValidByCode(string $code): ?DiscountCode
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.code = :code')->setParameter('code', strtoupper(trim($code)))
            ->andWhere('d.isActive = true')
            ->andWhere('d.expiresAt IS NULL OR d.expiresAt > :now')->setParameter('now', new \DateTimeImmutable())
            ->andWhere('d.usageLimit IS NULL OR d.timesUsed < d.usageLimit')
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Atomic, so two buyers cannot both take the last use. Returns false when none are left.
     */
    public function claimUse(DiscountCode $code): bool
    {
        $claimed = $this->createQueryBuilder('d')
            ->update()
            ->set('d.timesUsed', 'd.timesUsed + 1')
            ->andWhere('d.id = :id')->setParameter('id', $code->getId())
            ->andWhere('d.usageLimit IS NULL OR d.timesUsed < d.usageLimit')
            ->getQuery()->execute();

        if (1 === $claimed) {
            $this->getEntityManager()->refresh($code);
        }

        return 1 === $claimed;
    }

    public function releaseUse(DiscountCode $code): void
    {
        $this->createQueryBuilder('d')
            ->update()
            ->set('d.timesUsed', 'd.timesUsed - 1')
            ->andWhere('d.id = :id')->setParameter('id', $code->getId())
            ->andWhere('d.timesUsed > 0')
            ->getQuery()->execute();
        $this->getEntityManager()->refresh($code);
    }
}
