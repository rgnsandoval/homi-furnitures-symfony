<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Delivered orders with a paid payment only, dated by delivery.
     */
    public function totalRevenueBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $total = $this->createQueryBuilder('o')
            ->select('SUM(o.total)')
            ->join('o.payment', 'p')
            ->andWhere('o.status = :delivered')->setParameter('delivered', Order::STATUS_DELIVERED)
            ->andWhere('p.status = :paid')->setParameter('paid', Payment::STATUS_PAID)
            ->andWhere('o.deliveredAt BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();

        return bcadd((string) ($total ?? '0'), '0', 2);
    }

    public function countBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Average of delivered, paid orders.
     */
    public function averageOrderValue(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $row = $this->createQueryBuilder('o')
            ->select('SUM(o.total) AS total', 'COUNT(o.id) AS orders')
            ->join('o.payment', 'p')
            ->andWhere('o.status = :delivered')->setParameter('delivered', Order::STATUS_DELIVERED)
            ->andWhere('p.status = :paid')->setParameter('paid', Payment::STATUS_PAID)
            ->andWhere('o.deliveredAt BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleResult();

        return (int) $row['orders'] > 0 ? bcdiv((string) $row['total'], (string) $row['orders'], 2) : '0.00';
    }

    /**
     * @return array<string, int> every status, including those with no orders
     */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(Order::STATUSES, 0);
        $rows = $this->createQueryBuilder('o')
            ->select('o.status AS status', 'COUNT(o.id) AS total')
            ->groupBy('o.status')
            ->getQuery()->getArrayResult();

        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return list<Order>
     */
    public function findPendingOlderThan(int $hours): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.status = :pending')->setParameter('pending', Order::STATUS_PENDING)
            ->andWhere('o.createdAt < :before')->setParameter('before', new \DateTimeImmutable("-$hours hours"))
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @return Paginator<Order>
     */
    public function findForCustomer(User $user, ?string $status = null, int $page = 1, int $perPage = 20): Paginator
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.customer = :user')->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $status && '' !== $status) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        return new Paginator($qb, false);
    }

    /**
     * @param array<string, mixed> $filters status, q, from, to
     *
     * @return Paginator<Order>
     */
    public function findForAdmin(array $filters = [], int $page = 1, int $perPage = 20): Paginator
    {
        $qb = $this->createQueryBuilder('o')
            ->join('o.customer', 'u')->addSelect('u')
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (!empty($filters['status'])) {
            $qb->andWhere('o.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['q'])) {
            $qb->andWhere('o.orderNumber LIKE :q OR u.email LIKE :q OR u.fullName LIKE :q')
                ->setParameter('q', '%'.addcslashes((string) $filters['q'], '%_').'%');
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('o.createdAt >= :from')->setParameter('from', new \DateTimeImmutable($filters['from'].' 00:00:00'));
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('o.createdAt <= :to')->setParameter('to', new \DateTimeImmutable($filters['to'].' 23:59:59'));
        }

        return new Paginator($qb, false);
    }

    /**
     * @return list<array{day: string, revenue: string, orders: int|string}>
     */
    public function salesByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DATE(o.delivered_at) AS day, SUM(o.total) AS revenue, COUNT(o.id) AS orders
             FROM orders o
             JOIN payment p ON p.parent_order_id = o.id
             WHERE o.status = :delivered AND p.status = :paid AND o.delivered_at BETWEEN :from AND :to
             GROUP BY DATE(o.delivered_at)
             ORDER BY day ASC',
            [
                'delivered' => Order::STATUS_DELIVERED,
                'paid' => Payment::STATUS_PAID,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Cancelled orders as a percentage of all orders placed in the period.
     */
    public function cancellationRate(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $all = $this->countBetween($from, $to);
        if (0 === $all) {
            return '0.00';
        }

        $cancelled = (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.status = :cancelled')->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->andWhere('o.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();

        return bcdiv(bcmul((string) $cancelled, '100', 4), (string) $all, 2);
    }

    public function averageFulfillmentHours(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $minutes = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, delivered_at)) FROM orders
             WHERE status = :delivered AND delivered_at BETWEEN :from AND :to',
            ['delivered' => Order::STATUS_DELIVERED, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]
        );

        return bcdiv((string) ($minutes ?? '0'), '60', 2);
    }

    public function findByIdempotencyKey(string $key): ?Order
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }

    /**
     * @return list<OrderStatusHistory>
     */
    public function statusHistory(Order $order): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('h')
            ->from(OrderStatusHistory::class, 'h')
            ->andWhere('h.parentOrder = :order')->setParameter('order', $order)
            ->orderBy('h.createdAt', 'ASC')
            ->addOrderBy('h.id', 'ASC')
            ->getQuery()->getResult();
    }
}
