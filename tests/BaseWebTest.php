<?php

namespace App\Tests;

use App\Entity\Address;
use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\PlatformSetting;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Shared client and entity builders for the test suite.
 */
abstract class BaseWebTest extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    private static int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function next(): int
    {
        return ++self::$sequence;
    }

    protected function save(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    protected function makeUser(array $roles = [], bool $verified = true, ?string $email = null): User
    {
        $user = (new User())
            ->setEmail($email ?? 'user'.$this->next().'@homi.test')
            ->setFullName('Test User')
            ->setRoles($roles)
            ->setIsVerified($verified)
            ->setPassword('not-a-real-hash');
        $this->save($user);

        return $user;
    }

    protected function makeAdmin(): User
    {
        return $this->makeUser(['ROLE_ADMIN']);
    }

    protected function makeCategory(?string $slug = null, ?Category $parent = null): Category
    {
        $slug ??= 'category-'.$this->next();
        $category = (new Category())->setName(ucfirst($slug))->setSlug($slug)->setParent($parent);
        $this->save($category);

        return $category;
    }

    protected function makeBrand(): Brand
    {
        $brand = (new Brand())->setName('Brand '.$this->next());
        $this->save($brand);

        return $brand;
    }

    protected function makeProduct(?Category $category = null, string $price = '1000.00', ?string $salePrice = null, array $set = []): Product
    {
        $n = $this->next();
        $product = (new Product())
            ->setName('Product '.$n)
            ->setSku('SKU-'.$n)
            ->setSlug('product-'.$n)
            ->setDescription('A sturdy piece of furniture.')
            ->setPrice($price)
            ->setCostPrice('400.00')
            ->setSalePrice($salePrice)
            ->setStatus(Product::STATUS_ACTIVE)
            ->setMaterial('Oak')
            ->setLeadTimeDays(3)
            ->setCategory($category ?? $this->makeCategory());

        foreach ($set as $setter => $value) {
            $product->{'set'.ucfirst($setter)}($value);
        }
        $this->save($product);

        return $product;
    }

    protected function makeVariant(?Product $product = null, int $stock = 10, string $adjustment = '0.00', array $set = []): ProductVariant
    {
        $n = $this->next();
        $variant = (new ProductVariant())
            ->setSku('VAR-'.$n)
            ->setName('Variant '.$n)
            ->setPriceAdjustment($adjustment)
            ->setStock($stock)
            ->setProduct($product ?? $this->makeProduct());

        foreach ($set as $setter => $value) {
            $variant->{'set'.ucfirst($setter)}($value);
        }
        $this->save($variant);

        return $variant;
    }

    protected function makeAddress(User $owner, bool $default = true): Address
    {
        $address = (new Address())
            ->setLabel('Home')
            ->setRecipient($owner->getFullName())
            ->setPhone('09170000000')
            ->setLine1('1 Mabini St')
            ->setCity('Manila')
            ->setProvince('Metro Manila')
            ->setPostalCode('1000')
            ->setIsDefault($default)
            ->setOwner($owner);
        $this->save($address);

        return $address;
    }

    /**
     * Builds rows directly, for read-side tests only. State changes go through the services.
     */
    protected function makeOrder(
        User $customer,
        string $status = Order::STATUS_PENDING,
        string $paymentStatus = Payment::STATUS_PENDING,
        string $total = '1000.00',
        ?ProductVariant $variant = null,
        int $quantity = 1,
        string $method = Payment::METHOD_COD,
    ): Order {
        $variant ??= $this->makeVariant();
        $order = (new Order())
            ->setOrderNumber('HF-T'.$this->next())
            ->setStatus($status)
            ->setShippingMethod(Order::SHIPPING_STANDARD)
            ->setSubtotal($total)
            ->setTotal($total)
            ->setIdempotencyKey(Uuid::v4()->toRfc4122())
            ->setCustomer($customer)
            ->setShippingAddress($this->makeAddress($customer));

        if (Order::STATUS_DELIVERED === $status) {
            $order->setDeliveredAt(new \DateTimeImmutable());
        }

        $item = (new OrderItem())
            ->setQuantity($quantity)
            ->setUnitPrice($total)
            ->setProductName((string) $variant->getProduct()->getName())
            ->setVariantName((string) $variant->getName())
            ->setVariant($variant);
        $order->addItem($item);

        $payment = (new Payment())->setMethod($method)->setStatus($paymentStatus)->setAmount($total);
        $order->setPayment($payment);
        if (Payment::STATUS_PAID === $paymentStatus) {
            $payment->setPaidAt(new \DateTimeImmutable());
        }

        $this->save($order, $item, $payment);

        return $order;
    }

    protected function setting(string $key, string $value): void
    {
        $repository = $this->em->getRepository(PlatformSetting::class);
        $setting = $repository->findOneBy(['settingKey' => $key]) ?? (new PlatformSetting())->setSettingKey($key);
        $setting->setSettingValue($value);
        $this->save($setting);
    }

    /**
     * The settings the checkout reads, at the values seeded in production.
     */
    protected function seedSettings(): void
    {
        foreach ([
            'min_order_amount' => '1000',
            'shipping_fee' => '500',
            'express_shipping_fee' => '1200',
            'free_shipping_threshold' => '30000',
            'assembly_fee' => '800',
            'return_window_days' => '7',
            'pending_order_escalation_hours' => '24',
            'shop_open' => '1',
        ] as $key => $value) {
            $this->setting($key, $value);
        }
    }
}
