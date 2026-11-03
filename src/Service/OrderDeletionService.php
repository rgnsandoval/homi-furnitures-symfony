<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\DiscountCodeRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Permanently deletes an order of any status, for fraud and test orders.
 * Goods that never left the warehouse go back into stock and a claimed voucher use is released,
 * exactly as a cancel would; shipped and delivered goods are not restocked.
 */
class OrderDeletionService
{
    /** Statuses whose stock is still held by the order. Cancelled orders were already restocked. */
    private const HOLDS_STOCK = [Order::STATUS_PENDING, Order::STATUS_CONFIRMED, Order::STATUS_PACKED];

    public function __construct(
        private EntityManagerInterface $em,
        private OrderRepository $orders,
        private ProductVariantRepository $variants,
        private DiscountCodeRepository $discountCodes,
    ) {
    }

    public function restocksOnDelete(Order $order): bool
    {
        return in_array($order->getStatus(), self::HOLDS_STOCK, true);
    }

    /**
     * @return array<string, mixed> a summary of what was removed, for the audit log
     */
    public function delete(Order $order, User $admin): array
    {
        $summary = [
            'orderNumber' => $order->getOrderNumber(),
            'status' => $order->getStatus(),
            'customer' => $order->getCustomer()?->getEmail(),
            'total' => $order->getTotal(),
            'createdAt' => $order->getCreatedAt()?->format(\DATE_ATOM),
            'cancelReason' => $order->getCancelReason(),
            'payment' => $order->getPayment() ? $order->getPayment()->getMethod().' / '.$order->getPayment()->getStatus().' / '.$order->getPayment()->getAmount() : null,
            'items' => array_map(static fn ($item) => $item->getQuantity().' x '.$item->getProductName().' - '.$item->getVariantName(), $order->getItems()->toArray()),
            'restocked' => false,
        ];

        $this->em->beginTransaction();
        try {
            if ($this->restocksOnDelete($order)) {
                $ids = array_map(static fn ($item): int => (int) $item->getVariant()->getId(), $order->getItems()->toArray());
                $locked = $this->variants->lockForUpdate($ids);
                foreach ($order->getItems() as $item) {
                    $variant = $locked[$item->getVariant()->getId()];
                    $variant->setStock($variant->getStock() + (int) $item->getQuantity());
                    $this->em->persist((new StockMovement())
                        ->setVariant($variant)
                        ->setQuantityChange((int) $item->getQuantity())
                        ->setMovementReason(StockMovement::REASON_ORDER)
                        ->setNote('Order '.$order->getOrderNumber().' deleted')
                        ->setAdminUser($admin));
                }
                if (null !== $order->getDiscountCode()) {
                    $this->discountCodes->releaseUse($order->getDiscountCode());
                }
                $summary['restocked'] = true;
            }

            $this->orders->deleteWithHistory($order);
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        return $summary;
    }
}
