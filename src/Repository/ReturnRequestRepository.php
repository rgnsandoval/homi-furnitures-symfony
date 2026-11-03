<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\ReturnRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReturnRequest>
 */
class ReturnRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReturnRequest::class);
    }

    /**
     * @return Paginator<ReturnRequest>
     */
    /**
     * Every return, newest first, for the admin "All" tab.
     *
     * @return Paginator<ReturnRequest>
     */
    public function findAllForAdmin(int $page = 1, int $perPage = 20): Paginator
    {
        return new Paginator($this->createQueryBuilder('r')
            ->join('r.parentOrder', 'o')->addSelect('o')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }

    public function findOpen(int $page = 1, int $perPage = 20): Paginator
    {
        return new Paginator($this->createQueryBuilder('r')
            ->join('r.parentOrder', 'o')->addSelect('o')
            ->andWhere('r.status IN (:open)')
            ->setParameter('open', [ReturnRequest::STATUS_REQUESTED, ReturnRequest::STATUS_APPROVED])
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }

    /**
     * @return Paginator<ReturnRequest>
     */
    public function findForCustomer(User $user, int $page = 1, int $perPage = 20): Paginator
    {
        return new Paginator($this->createQueryBuilder('r')
            ->andWhere('r.customer = :user')->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }

    public function findActiveForOrder(Order $order): ?ReturnRequest
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.parentOrder = :order')->setParameter('order', $order)
            ->andWhere('r.status != :rejected')->setParameter('rejected', ReturnRequest::STATUS_REJECTED)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
