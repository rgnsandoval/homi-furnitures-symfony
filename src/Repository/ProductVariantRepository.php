<?php

namespace App\Repository;

use App\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductVariant>
 */
class ProductVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVariant::class);
    }

    /**
     * @return list<ProductVariant>
     */
    public function findLowStock(): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.isActive = true')
            ->andWhere('v.stock > 0')
            ->andWhere('v.stock <= v.lowStockThreshold')
            ->orderBy('v.stock', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @return list<ProductVariant>
     */
    public function findOutOfStock(): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.isActive = true')
            ->andWhere('v.stock <= 0')
            ->orderBy('v.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Must run inside a transaction. Rows lock in id order so concurrent checkouts cannot deadlock.
     *
     * @param list<int> $ids
     *
     * @return array<int, ProductVariant> keyed by variant id
     */
    public function lockForUpdate(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $variants = $this->createQueryBuilder('v')
            ->andWhere('v.id IN (:ids)')->setParameter('ids', $ids)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        $locked = [];
        foreach ($variants as $variant) {
            $this->getEntityManager()->refresh($variant);
            $locked[$variant->getId()] = $variant;
        }

        return $locked;
    }
}
