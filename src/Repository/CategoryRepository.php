<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Order;
use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Top-level categories are the rooms.
     *
     * @return list<Category>
     */
    public function findActiveRoots(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.isActive = true')
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @return list<Category>
     */
    public function findChildren(Category $parent): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.parent = :parent')->setParameter('parent', $parent)
            ->andWhere('c.isActive = true')
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Revenue counts delivered orders with a paid payment only.
     *
     * @return list<array{name: string, revenue: string}>
     */
    public function revenueByCategory(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT c.name AS name, SUM(i.unitPrice * i.quantity) AS revenue
             FROM App\Entity\OrderItem i
             JOIN i.parentOrder o
             JOIN o.payment pay
             JOIN i.variant v
             JOIN v.product p
             JOIN p.category c
             WHERE o.status = :delivered AND pay.status = :paid AND o.deliveredAt BETWEEN :from AND :to
             GROUP BY c.id, c.name
             ORDER BY revenue DESC'
        )
            ->setParameter('delivered', Order::STATUS_DELIVERED)
            ->setParameter('paid', Payment::STATUS_PAID)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getArrayResult();
    }
}
