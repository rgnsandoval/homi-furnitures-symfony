<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\User;
use App\Entity\WishlistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WishlistItem>
 */
class WishlistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistItem::class);
    }

    /**
     * @return Paginator<WishlistItem>
     */
    public function findForUser(User $user, int $page = 1, int $perPage = 20): Paginator
    {
        return new Paginator($this->createQueryBuilder('w')
            ->join('w.product', 'p')->addSelect('p')
            ->andWhere('w.owner = :user')->setParameter('user', $user)
            ->orderBy('w.createdAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }

    /**
     * @return list<int>
     */
    public function findProductIdsForUser(User $user): array
    {
        return array_map('intval', $this->createQueryBuilder('w')
            ->select('IDENTITY(w.product)')
            ->andWhere('w.owner = :user')->setParameter('user', $user)
            ->getQuery()->getSingleColumnResult());
    }

    public function findOneForUserAndProduct(User $user, Product $product): ?WishlistItem
    {
        return $this->findOneBy(['owner' => $user, 'product' => $product]);
    }
}
