<?php

namespace App\Twig;

use App\Entity\Product;
use App\Entity\User;
use App\Repository\CartItemRepository;
use App\Repository\PlatformSettingRepository;
use App\Repository\WishlistItemRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * What the layout and the product card need on every page.
 */
final class ShopExtension
{
    private const FREE_SHIPPING_DEFAULT = '30000';

    /** Display colours for the catalog's colour names. */
    private const SWATCHES = [
        'Beige' => '#d9c8a9', 'Black' => '#1c1c1c', 'Blue' => '#3f6ea5', 'Blush' => '#e6c3bd', 'Brown' => '#6b4a32',
        'Burgundy' => '#6d1f2c', 'Camel' => '#b98d58', 'Charcoal' => '#36393d', 'Cream' => '#f2ebd9', 'Emerald' => '#1f6b4f',
        'Green' => '#3e7a4b', 'Grey' => '#8a8d91', 'Ivory' => '#f4efe2', 'Light Oak' => '#d2b48c', 'Mustard' => '#c99a2e',
        'Natural' => '#c9a777', 'Navy' => '#1f2f4d', 'Oat' => '#d8cdb5', 'Pink' => '#e7a6b8', 'Red' => '#a8322d',
        'Rust' => '#a4502a', 'Sage' => '#a3b18a', 'Sage Green' => '#a3b18a', 'Tan' => '#c19a6b', 'Teal' => '#20707a',
        'Terracotta' => '#b5543a', 'Walnut' => '#5c4033', 'Warm White' => '#f6efe0', 'White' => '#f7f7f5',
    ];

    private ?int $cartCount = null;

    /** @var array<int, true>|null */
    private ?array $wishlistIds = null;

    public function __construct(
        private Security $security,
        private CartItemRepository $cart,
        private WishlistItemRepository $wishlist,
        private PlatformSettingRepository $settings,
    ) {
    }

    #[AsTwigFunction('cart_count')]
    public function cartCount(): int
    {
        $user = $this->user();

        return $this->cartCount ??= null === $user ? 0 : $this->cart->countUnits($user);
    }

    #[AsTwigFunction('wishlist_count')]
    public function wishlistCount(): int
    {
        return count($this->wishlistIds());
    }

    #[AsTwigFunction('wishlist_has')]
    public function wishlistHas(Product $product): bool
    {
        return isset($this->wishlistIds()[$product->getId()]);
    }

    #[AsTwigFunction('shop_setting')]
    public function setting(string $key): string
    {
        return $this->settings->getValue($key) ?? ('free_shipping_threshold' === $key ? self::FREE_SHIPPING_DEFAULT : '');
    }

    #[AsTwigFunction('shop_saving')]
    public function saving(Product $product): string
    {
        return bcsub($product->getPrice(), $product->getEffectivePrice(), 2);
    }

    /**
     * @return list<array{name: string, hex: string}> the colours of a product's active variants that have a known swatch
     */
    #[AsTwigFunction('shop_colors')]
    public function colors(Product $product): array
    {
        $colors = [];
        foreach ($product->getVariants() as $variant) {
            $name = $variant->getColor();
            if ($variant->isActive() && isset(self::SWATCHES[$name])) {
                $colors[$name] = ['name' => $name, 'hex' => self::SWATCHES[$name]];
            }
        }

        return array_values($colors);
    }

    /**
     * @return array<int, true>
     */
    private function wishlistIds(): array
    {
        $user = $this->user();

        return $this->wishlistIds ??= null === $user ? [] : array_fill_keys($this->wishlist->findProductIdsForUser($user), true);
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
