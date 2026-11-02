<?php

namespace App\Repository;

use App\Entity\Address;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Address>
 */
class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

//    /**
//     * @return Address[] Returns an array of Address objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('a.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Address
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }

    /**
     * @return \Doctrine\ORM\Tools\Pagination\Paginator<Address>
     */
    public function findForUser(\App\Entity\User $user, int $page = 1, int $perPage = 20): \Doctrine\ORM\Tools\Pagination\Paginator
    {
        return new \Doctrine\ORM\Tools\Pagination\Paginator($this->createQueryBuilder('a')
            ->andWhere('a.owner = :user')->setParameter('user', $user)
            ->orderBy('a.isDefault', 'DESC')
            ->addOrderBy('a.id', 'ASC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }

    public function countForUser(\App\Entity\User $user): int
    {
        return $this->count(['owner' => $user]);
    }

    /**
     * Keeps a single default address per user.
     */
    public function clearDefaultExcept(\App\Entity\User $user, Address $keep): void
    {
        foreach ($this->findBy(['owner' => $user, 'isDefault' => true]) as $address) {
            if ($address !== $keep) {
                $address->setIsDefault(false);
            }
        }
    }
}
