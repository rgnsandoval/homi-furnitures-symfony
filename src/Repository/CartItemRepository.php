<?php

namespace App\Repository;

use App\Entity\CartItem;
use App\Entity\ProductVariant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CartItem>
 */
class CartItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CartItem::class);
    }

    /**
     * @return list<CartItem>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.variant', 'v')->addSelect('v')
            ->join('v.product', 'p')->addSelect('p')
            ->andWhere('i.owner = :user')->setParameter('user', $user)
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function findOneForUserAndVariant(User $user, ProductVariant $variant): ?CartItem
    {
        return $this->findOneBy(['owner' => $user, 'variant' => $variant]);
    }

    public function countUnits(User $user): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.quantity), 0)')
            ->andWhere('i.owner = :user')->setParameter('user', $user)
            ->getQuery()->getSingleScalarResult();
    }

    public function clearForUser(User $user): int
    {
        return $this->createQueryBuilder('i')
            ->delete()
            ->andWhere('i.owner = :user')->setParameter('user', $user)
            ->getQuery()->execute();
    }
}
