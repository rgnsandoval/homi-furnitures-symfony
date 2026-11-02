<?php

namespace App\Repository;

use App\Entity\Brand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Brand>
 */
class BrandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Brand::class);
    }

//    /**
//     * @return Brand[] Returns an array of Brand objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('b')
//            ->andWhere('b.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('b.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Brand
//    {
//        return $this->createQueryBuilder('b')
//            ->andWhere('b.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }

    /**
     * @return \Doctrine\ORM\Tools\Pagination\Paginator<Brand>
     */
    public function findPage(int $page = 1, int $perPage = 20): \Doctrine\ORM\Tools\Pagination\Paginator
    {
        return new \Doctrine\ORM\Tools\Pagination\Paginator($this->createQueryBuilder('b')
            ->orderBy('b.name', 'ASC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }
}
