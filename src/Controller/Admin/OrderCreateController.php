<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\UserRepository;
use App\Service\CheckoutService;
use App\Service\OrderLine;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Lets an admin place an order for a customer (phone or showroom sales). Pricing, stock, vouchers and history
 * go through the same CheckoutService as the storefront; only the "shop open" and "email verified" gates are skipped.
 */
final class OrderCreateController extends AbstractController
{
    private const LINES = 6;

    #[Route('/admin/orders/new', name: 'app_admin_order_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserRepository $users,
        AddressRepository $addresses,
        ProductVariantRepository $variants,
        OrderRepository $orders,
        CheckoutService $checkout,
        AuditLogRepository $audit,
    ): Response {
        $customer = $users->find($request->query->getInt('customer') ?: $request->request->getInt('customer'));
        $values = ['lines' => [], 'input' => ['address' => 0, 'shippingMethod' => Order::SHIPPING_STANDARD, 'paymentMethod' => Payment::METHOD_COD, 'voucher' => '', 'assembly' => false, 'notes' => '']];
        $key = Uuid::v4()->toRfc4122();

        if (null !== $customer && $request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin-order-new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $key = Uuid::isValid($request->request->getString('idempotencyKey')) ? $request->request->getString('idempotencyKey') : $key;
            if (null !== $existing = $orders->findByIdempotencyKey($key)) {
                return $this->redirectToRoute('app_admin_order_show', ['id' => $existing->getId()], Response::HTTP_SEE_OTHER);
            }

            $input = $checkout->input($request->request->all());
            [$lines, $rows, $errors] = $this->lines($request, $variants);
            $values = ['lines' => $rows, 'input' => $input];

            if ([] === $errors) {
                /** @var User $admin */
                $admin = $this->getUser();
                $result = $checkout->place($customer, $lines, $input, $key, $admin, staff: true);
                $errors = $result['errors'];

                if (null !== $order = $result['order']) {
                    $audit->record($admin, 'order.create', 'Order', (int) $order->getId(), [
                        'orderNumber' => $order->getOrderNumber(),
                        'customer' => $customer->getEmail(),
                        'total' => $order->getTotal(),
                    ]);
                    $this->addFlash('success', sprintf('Order %s was created for %s.', $order->getOrderNumber(), $customer->getEmail()));

                    return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
                }
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        $customerAddresses = null === $customer ? [] : $addresses->findBy(['owner' => $customer], ['isDefault' => 'DESC', 'id' => 'ASC']);
        if (0 === $values['input']['address'] && [] !== $customerAddresses) {
            $values['input']['address'] = (int) $customerAddresses[0]->getId();
        }

        return $this->render('admin/order_new/index.html.twig', [
            'customer' => $customer,
            'customers' => null === $customer ? $users->findActiveForPicker() : [],
            'addresses' => $customerAddresses,
            'variants' => null === $customer ? [] : $variants->findSellable(),
            'values' => $values,
            'lineCount' => self::LINES,
            'shippingMethods' => Order::SHIPPING_METHODS,
            'paymentMethods' => Payment::METHODS,
            'idempotencyKey' => $key,
        ], new Response(null, $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Reads the SKU/quantity rows, merging repeats of the same SKU.
     *
     * @return array{0: list<OrderLine>, 1: list<array{sku: string, quantity: int}>, 2: list<string>}
     */
    private function lines(Request $request, ProductVariantRepository $variants): array
    {
        $skus = $request->request->all('sku');
        $quantities = $request->request->all('quantity');
        $wanted = [];
        $rows = [];
        $errors = [];

        foreach ($skus as $i => $sku) {
            $sku = trim((string) (is_scalar($sku) ? $sku : ''));
            // The picker shows "SKU · name"; only the SKU part identifies the variant.
            $sku = trim(explode('·', $sku)[0]);
            $quantity = (int) (is_scalar($quantities[$i] ?? null) ? $quantities[$i] : 0);
            if ('' === $sku) {
                continue;
            }
            $rows[] = ['sku' => $sku, 'quantity' => $quantity];
            if ($quantity < 1) {
                $errors[] = sprintf('Enter a quantity of at least 1 for %s.', $sku);
                continue;
            }
            $wanted[$sku] = ($wanted[$sku] ?? 0) + $quantity;
        }

        $found = $variants->findBySkus(array_map('strval', array_keys($wanted)));
        $lines = [];
        foreach ($wanted as $sku => $quantity) {
            if (!isset($found[(string) $sku])) {
                $errors[] = sprintf('No product variant has the SKU %s.', $sku);
                continue;
            }
            $lines[] = new OrderLine($found[(string) $sku], $quantity);
        }

        if ([] === $rows) {
            $errors[] = 'Add at least one item.';
        }

        return [$lines, $rows, $errors];
    }
}
