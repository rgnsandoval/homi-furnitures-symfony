<?php

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return list<AuditLog>
     */
    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * @return Paginator<AuditLog>
     */
    public function findPage(int $page = 1, int $perPage = 20): Paginator
    {
        return new Paginator($this->createQueryBuilder('a')
            ->join('a.adminUser', 'u')->addSelect('u')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }

    /**
     * @param array<string, mixed>|null $detail
     */
    public function record(\App\Entity\User $admin, string $action, string $entityType, int|string $entityId, ?array $detail = null): void
    {
        $this->getEntityManager()->persist((new AuditLog())
            ->setAdminUser($admin)
            ->setAction($action)
            ->setEntityType($entityType)
            ->setEntityId((string) $entityId)
            ->setDetail($detail));
        $this->getEntityManager()->flush();
    }
}
