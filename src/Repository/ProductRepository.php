<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public const SORTS = ['newest', 'price_asc', 'price_desc', 'name'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @param array<string, mixed> $filters q, room, category, brand, material, color, minPrice, maxPrice, maxWidth, maxDepth, maxHeight, inStock
     *
     * @return Paginator<Product>
     */
    public function search(array $filters = [], string $sort = 'newest', int $page = 1, int $perPage = 20): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.category', 'c')
            ->leftJoin('c.parent', 'room')
            ->leftJoin('p.brand', 'b')
            ->addSelect('c', 'b')
            ->andWhere('p.status = :active')
            ->setParameter('active', Product::STATUS_ACTIVE)
            ->andWhere('c.isActive = true');

        $this->applyFilters($qb, $filters);

        match ($sort) {
            'price_asc' => $qb->addSelect('COALESCE(p.salePrice, p.price) AS HIDDEN effectivePrice')->orderBy('effectivePrice', 'ASC'),
            'price_desc' => $qb->addSelect('COALESCE(p.salePrice, p.price) AS HIDDEN effectivePrice')->orderBy('effectivePrice', 'DESC'),
            'name' => $qb->orderBy('p.name', 'ASC'),
            default => $qb->orderBy('p.createdAt', 'DESC'),
        };
        $qb->addOrderBy('p.id', 'DESC');

        $qb->setFirstResult(max(0, $page - 1) * $perPage)->setMaxResults($perPage);

        return new Paginator($qb, false);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        $present = static fn (string $key): bool => isset($filters[$key]) && '' !== $filters[$key];

        if ($present('q')) {
            $qb->andWhere('p.name LIKE :q OR p.description LIKE :q OR p.sku LIKE :q')
                ->setParameter('q', '%'.addcslashes((string) $filters['q'], '%_').'%');
        }
        if ($present('room')) {
            $qb->andWhere('c.slug = :room OR room.slug = :room')->setParameter('room', $filters['room']);
        }
        if ($present('category')) {
            $qb->andWhere('c.slug = :category')->setParameter('category', $filters['category']);
        }
        if ($present('brand')) {
            $qb->andWhere('b.id = :brand')->setParameter('brand', (int) $filters['brand']);
        }
        if ($present('material')) {
            $qb->andWhere('p.material = :material')->setParameter('material', $filters['material']);
        }
        if ($present('color')) {
            $qb->andWhere('p.defaultColor = :color OR EXISTS (SELECT cv.id FROM App\Entity\ProductVariant cv WHERE cv.product = p AND cv.color = :color)')
                ->setParameter('color', $filters['color']);
        }
        if ($present('minPrice')) {
            $qb->andWhere('COALESCE(p.salePrice, p.price) >= :minPrice')->setParameter('minPrice', (string) $filters['minPrice']);
        }
        if ($present('maxPrice')) {
            $qb->andWhere('COALESCE(p.salePrice, p.price) <= :maxPrice')->setParameter('maxPrice', (string) $filters['maxPrice']);
        }
        foreach (['maxWidth' => 'widthCm', 'maxDepth' => 'depthCm', 'maxHeight' => 'heightCm'] as $key => $field) {
            if ($present($key)) {
                $qb->andWhere("p.$field <= :$key")->setParameter($key, (string) $filters[$key]);
            }
        }
        if (!empty($filters['inStock'])) {
            $qb->andWhere('EXISTS (SELECT sv.id FROM App\Entity\ProductVariant sv WHERE sv.product = p AND sv.isActive = true AND sv.stock > 0)');
        }
    }

    /**
     * @return list<string>
     */
    public function findDistinctMaterials(): array
    {
        return array_column($this->createQueryBuilder('p')
            ->select('DISTINCT p.material')
            ->andWhere('p.status = :active')->setParameter('active', Product::STATUS_ACTIVE)
            ->orderBy('p.material', 'ASC')
            ->getQuery()->getArrayResult(), 'material');
    }

    public function findActiveBySlug(string $slug): ?Product
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.slug = :slug')->setParameter('slug', $slug)
            ->andWhere('p.status = :active')->setParameter('active', Product::STATUS_ACTIVE)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * @return list<Product>
     */
    /**
     * Loads the variants of a page of products in one query, so a product grid does not run one query per card.
     *
     * @param iterable<Product> $products
     */
    public function loadVariants(iterable $products): void
    {
        $ids = [];
        foreach ($products as $product) {
            $ids[] = $product->getId();
        }

        if ([] !== $ids) {
            $this->createQueryBuilder('p')
                ->leftJoin('p.variants', 'v')->addSelect('v')
                ->andWhere('p.id IN (:ids)')->setParameter('ids', $ids)
                ->getQuery()->getResult();
        }
    }

    /**
     * Active products in a category or, for a room, in any of its subcategories.
     */
    public function countActiveInCategory(string $slug): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->join('p.category', 'c')
            ->leftJoin('c.parent', 'room')
            ->andWhere('c.slug = :slug OR room.slug = :slug')->setParameter('slug', $slug)
            ->andWhere('p.status = :active')->setParameter('active', Product::STATUS_ACTIVE)
            ->getQuery()->getSingleScalarResult();
    }

    public function findFeatured(int $limit = 8): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.isFeatured = true')
            ->andWhere('p.status = :active')->setParameter('active', Product::STATUS_ACTIVE)
            // Catalog order, so the owner decides what leads the home page.
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * @return list<Product>
     */
    public function findOnSale(int $limit = 8): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.salePrice IS NOT NULL')
            ->andWhere('p.salePrice < p.price')
            ->andWhere('p.status = :active')->setParameter('active', Product::STATUS_ACTIVE)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * @return list<Product>
     */
    public function findRelated(Product $product, int $limit = 4): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.category = :category')->setParameter('category', $product->getCategory())
            ->andWhere('p != :product')->setParameter('product', $product)
            ->andWhere('p.status = :active')->setParameter('active', Product::STATUS_ACTIVE)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * @return list<array{product: Product, sold: string}>
     */
    public function findBestSellers(int $limit = 10): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT p AS product, SUM(i.quantity) AS sold
             FROM App\Entity\Product p
             JOIN p.variants v
             JOIN App\Entity\OrderItem i WITH i.variant = v
             JOIN i.parentOrder o
             WHERE o.status != :cancelled
             GROUP BY p
             ORDER BY sold DESC, p.id ASC'
        )->setParameter('cancelled', Order::STATUS_CANCELLED)->setMaxResults($limit)->getResult();

        return array_map(static fn (array $row): array => ['product' => $row['product'], 'sold' => (string) $row['sold']], $rows);
    }

    /**
     * Active products with no sale in the last $days days.
     *
     * @return list<Product>
     */
    public function findSlowMoving(int $days): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT p FROM App\Entity\Product p
             WHERE p.status = :active AND NOT EXISTS (
                 SELECT i.id FROM '.OrderItem::class.' i
                 JOIN i.variant v JOIN i.parentOrder o
                 WHERE v.product = p AND o.status != :cancelled AND o.createdAt >= :since
             )
             ORDER BY p.createdAt ASC'
        )
            ->setParameter('active', Product::STATUS_ACTIVE)
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->setParameter('since', new \DateTimeImmutable("-$days days"))
            ->getResult();
    }

    /**
     * Stock on hand valued at cost.
     */
    public function inventoryValue(): string
    {
        $value = $this->getEntityManager()->createQuery(
            'SELECT SUM(v.stock * p.costPrice) FROM App\Entity\ProductVariant v JOIN v.product p WHERE p.status != :archived'
        )->setParameter('archived', Product::STATUS_ARCHIVED)->getSingleScalarResult();

        return bcadd((string) ($value ?? '0'), '0', 2);
    }

    /**
     * @return Paginator<Product>
     */
    public function findAdminPage(?string $q = null, int $page = 1, int $perPage = 20): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $q && '' !== $q) {
            $qb->andWhere('p.name LIKE :q OR p.sku LIKE :q')->setParameter('q', '%'.addcslashes($q, '%_').'%');
        }

        return new Paginator($qb, false);
    }
}
