<?php

namespace App\Service;

use App\Entity\ProductVariant;

/**
 * One line of an order being placed, for orders that do not come from a cart (an admin placing one for a customer).
 * It offers the same two getters as CartItem, so the checkout code treats both alike.
 */
final readonly class OrderLine
{
    public function __construct(private ProductVariant $variant, private int $quantity)
    {
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
