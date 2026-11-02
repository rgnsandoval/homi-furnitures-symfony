<?php

namespace App\Repository;

use App\Entity\PlatformSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlatformSetting>
 */
class PlatformSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformSetting::class);
    }

    public function getValue(string $key, ?string $default = null): ?string
    {
        return $this->findOneBy(['settingKey' => $key])?->getSettingValue() ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->getValue($key) ?? $default);
    }

    public function getDecimal(string $key, string $default = '0.00'): string
    {
        return bcadd($this->getValue($key) ?? $default, '0', 2);
    }

    /**
     * @return \Doctrine\ORM\Tools\Pagination\Paginator<PlatformSetting>
     */
    public function findPage(int $page = 1, int $perPage = 20): \Doctrine\ORM\Tools\Pagination\Paginator
    {
        return new \Doctrine\ORM\Tools\Pagination\Paginator($this->createQueryBuilder('s')
            ->orderBy('s.settingKey', 'ASC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage), false);
    }
}
