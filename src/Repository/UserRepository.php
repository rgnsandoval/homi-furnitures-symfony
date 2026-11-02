<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function countNewBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<array{user: User, spent: string, orders: int|string}>
     */
    public function topBuyers(int $limit = 10): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT u AS user, SUM(o.total) AS spent, COUNT(o.id) AS orders
             FROM App\Entity\User u
             JOIN App\Entity\Order o WITH o.customer = u
             WHERE o.status != :cancelled
             GROUP BY u
             ORDER BY spent DESC, u.id ASC'
        )->setParameter('cancelled', Order::STATUS_CANCELLED)->setMaxResults($limit)->getResult();

        return array_map(static fn (array $row): array => ['user' => $row['user'], 'spent' => (string) $row['spent'], 'orders' => $row['orders']], $rows);
    }

    /**
     * Customers with two or more non-cancelled orders, as a percentage of customers with at least one.
     */
    public function repeatCustomerRate(): string
    {
        $counts = $this->getEntityManager()->createQuery(
            'SELECT COUNT(o.id) AS orders FROM App\Entity\Order o WHERE o.status != :cancelled GROUP BY o.customer'
        )->setParameter('cancelled', Order::STATUS_CANCELLED)->getScalarResult();

        if ([] === $counts) {
            return '0.00';
        }

        $repeat = count(array_filter($counts, static fn (array $row): bool => (int) $row['orders'] >= 2));

        return bcdiv(bcmul((string) $repeat, '100', 4), (string) count($counts), 2);
    }

    /**
     * Customers who placed an order in the last $days days.
     *
     * @return list<User>
     */
    public function findActiveCustomers(int $days): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT DISTINCT u FROM App\Entity\User u
             JOIN App\Entity\Order o WITH o.customer = u
             WHERE o.createdAt >= :since
             ORDER BY u.id ASC'
        )->setParameter('since', new \DateTimeImmutable("-$days days"))->getResult();
    }

    /**
     * @return Paginator<User>
     */
    public function findPage(?string $q = null, int $page = 1, int $perPage = 20): Paginator
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $q && '' !== $q) {
            $qb->andWhere('u.email LIKE :q OR u.fullName LIKE :q')->setParameter('q', '%'.addcslashes($q, '%_').'%');
        }

        return new Paginator($qb, false);
    }
}
