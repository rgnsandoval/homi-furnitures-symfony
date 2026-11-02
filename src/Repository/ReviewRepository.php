<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * @return list<Review>
     */
    public function findApprovedForProduct(Product $product): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.author', 'a')->addSelect('a')
            ->andWhere('r.product = :product')->setParameter('product', $product)
            ->andWhere('r.isApproved = true')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * @return Paginator<Review>
     */
    public function findPending(int $page = 1, int $perPage = 20): Paginator
    {
        return new Paginator($this->createQueryBuilder('r')
            ->andWhere('r.isApproved = false')
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }

    /**
     * Mean rating of approved reviews to two decimals, or null when there are none.
     */
    public function averageRating(Product $product): ?string
    {
        $row = $this->createQueryBuilder('r')
            ->select('SUM(r.rating) AS total', 'COUNT(r.id) AS reviews')
            ->andWhere('r.product = :product')->setParameter('product', $product)
            ->andWhere('r.isApproved = true')
            ->getQuery()->getSingleResult();

        return (int) $row['reviews'] > 0 ? bcdiv((string) $row['total'], (string) $row['reviews'], 2) : null;
    }

    public function findOneForAuthorAndProduct(User $author, Product $product): ?Review
    {
        return $this->findOneBy(['author' => $author, 'product' => $product]);
    }
}
