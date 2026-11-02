<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * @return list<Payment>
     */
    public function findByMethodAndStatus(string $method, string $status): array
    {
        return $this->findBy(['method' => $method, 'status' => $status], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Bank transfers waiting for an admin to check the uploaded proof.
     *
     * @return list<Payment>
     */
    public function findPendingBankTransfers(): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.parentOrder', 'o')->addSelect('o')
            ->andWhere('p.method = :bank')->setParameter('bank', Payment::METHOD_BANK)
            ->andWhere('p.status = :pending')->setParameter('pending', Payment::STATUS_PENDING)
            ->andWhere('p.proofFile IS NOT NULL')
            ->andWhere('o.status != :cancelled')->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * COD is collected after delivery.
     *
     * @return list<Payment>
     */
    public function findCollectableCod(): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.parentOrder', 'o')->addSelect('o')
            ->andWhere('p.method = :cod')->setParameter('cod', Payment::METHOD_COD)
            ->andWhere('p.status = :pending')->setParameter('pending', Payment::STATUS_PENDING)
            ->andWhere('o.status = :delivered')->setParameter('delivered', Order::STATUS_DELIVERED)
            ->orderBy('o.deliveredAt', 'ASC')
            ->getQuery()->getResult();
    }

    public function totalPaidBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $total = $this->createQueryBuilder('p')
            ->select('SUM(p.amount)')
            ->andWhere('p.status = :paid')->setParameter('paid', Payment::STATUS_PAID)
            ->andWhere('p.paidAt BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();

        return bcadd((string) ($total ?? '0'), '0', 2);
    }

    /**
     * @return Paginator<Payment>
     */
    public function findPage(int $page = 1, int $perPage = 20): Paginator
    {
        return new Paginator($this->createQueryBuilder('p')
            ->join('p.parentOrder', 'o')->addSelect('o')
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }
}
