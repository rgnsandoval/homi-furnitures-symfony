<?php

namespace App\Tests;

use App\Entity\Inquiry;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\ReturnRequest;
use App\Entity\Review;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * Requests every GET route for guest, customer and admin, and fails if a new route is not covered.
 */
class SmokeTest extends BaseWebTest
{
    /** @var array<string, array<string, int|string>> */
    private array $params = [];
    private User $customer;
    private User $admin;

    private const NO_PAGE = ['app_logout', 'app_verify_email', 'app_verify_notice', 'app_payment_proof', 'app_return_photo', 'app_return_new'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSettings();

        $this->customer = $this->makeUser();
        $this->admin = $this->makeAdmin();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);
        $order = $this->makeOrder($this->customer, Order::STATUS_DELIVERED, Payment::STATUS_PAID, '2000.00', $variant);
        $this->save(
            (new Review())->setRating(5)->setBody('Nice')->setAuthor($this->makeUser())->setProduct($product),
            (new Inquiry())->setName('A')->setEmail('a@homi.test')->setSubject('S')->setMessage('M'),
            (new ReturnRequest())->setReturnReason('R')->setParentOrder($order)->setCustomer($this->customer),
        );

        $brand = $this->makeBrand();
        $code = (new \App\Entity\DiscountCode())->setCode('SMOKE')->setDiscountType('fixed')->setDiscountValue('1.00');
        $this->save($code);
        $setting = $this->em->getRepository(\App\Entity\PlatformSetting::class)->findOneBy([]);

        $this->params = [
            'app_product' => ['slug' => (string) $product->getSlug()],
            'app_address_show' => ['id' => (int) $order->getShippingAddress()->getId()],
            'app_address_edit' => ['id' => (int) $order->getShippingAddress()->getId()],
            'app_order_detail' => ['id' => (int) $order->getId()],
            'app_invoice' => ['id' => (int) $order->getId()],
            'app_admin_order_show' => ['id' => (int) $order->getId()],
            'app_admin_stock' => ['id' => (int) $variant->getId()],
            'app_admin_category_show' => ['id' => (int) $product->getCategory()->getId()],
            'app_admin_category_edit' => ['id' => (int) $product->getCategory()->getId()],
            'app_admin_brand_show' => ['id' => (int) $brand->getId()],
            'app_admin_brand_edit' => ['id' => (int) $brand->getId()],
            'app_admin_product_show' => ['id' => (int) $product->getId()],
            'app_admin_product_edit' => ['id' => (int) $product->getId()],
            'app_admin_product_variant_show' => ['id' => (int) $variant->getId()],
            'app_admin_product_variant_edit' => ['id' => (int) $variant->getId()],
            'app_admin_discount_code_show' => ['id' => (int) $code->getId()],
            'app_admin_discount_code_edit' => ['id' => (int) $code->getId()],
            'app_admin_platform_setting_show' => ['id' => (int) $setting->getId()],
            'app_admin_platform_setting_edit' => ['id' => (int) $setting->getId()],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pageRoutes(): array
    {
        $routes = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, '_') || in_array($name, self::NO_PAGE, true)) {
                continue;
            }
            $methods = $route->getMethods();
            if ([] !== $methods && !in_array('GET', $methods, true)) {
                continue;
            }
            $routes[$name] = $route->getPath();
        }

        return $routes;
    }

    private function url(string $name): string
    {
        return static::getContainer()->get(RouterInterface::class)->generate($name, $this->params[$name] ?? []);
    }

    private function isAdminRoute(string $path): bool
    {
        return str_starts_with($path, '/admin');
    }

    private function isCustomerRoute(string $path): bool
    {
        return 1 === preg_match('#^/(account|cart|wishlist|checkout|orders)#', $path);
    }

    #[DataProvider('roles')]
    public function testEveryPageGivesExpectedStatus(string $role): void
    {
        if ('customer' === $role) {
            $this->client->loginUser($this->customer);
        } elseif ('admin' === $role) {
            $this->client->loginUser($this->admin);
        }

        $routes = $this->pageRoutes();
        $this->assertGreaterThan(30, count($routes));

        foreach ($routes as $name => $path) {
            $this->client->request('GET', $this->url($name));
            $status = $this->client->getResponse()->getStatusCode();

            $allowed = match (true) {
                'guest' === $role && ($this->isAdminRoute($path) || $this->isCustomerRoute($path)) => [302],
                'customer' === $role && $this->isAdminRoute($path) => [403],
                // The admin does not own the customer's address or order.
                'admin' === $role && in_array($name, ['app_address_show', 'app_address_edit', 'app_order_detail'], true) => [404],
                // Checkout redirects when the cart is empty.
                'app_checkout' === $name && 'guest' !== $role => [302],
                in_array($name, ['app_login', 'app_register'], true) => [200, 302],
                default => [200],
            };

            $this->assertContains($status, $allowed, sprintf('%s (%s) as %s returned %d', $name, $path, $role, $status));
        }
    }

    public static function roles(): array
    {
        return ['guest' => ['guest'], 'customer' => ['customer'], 'admin' => ['admin']];
    }

    public function testPublicUploadsNeverIncludePrivateFolders(): void
    {
        $this->client->request('GET', '/var/uploads/proofs/anything.png');
        $this->assertResponseStatusCodeSame(404);
    }
}
