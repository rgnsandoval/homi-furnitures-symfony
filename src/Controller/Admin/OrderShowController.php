<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Service\OrderTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderShowController extends AbstractController
{
    #[Route('/admin/orders/{id}', name: 'app_admin_order_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(Order $order, OrderRepository $orders, OrderTransitionService $transitions): Response
    {
        return $this->render('admin/order_show/index.html.twig', [
            'order' => $order,
            'history' => $orders->statusHistory($order),
            'nextStatuses' => array_values(array_diff($transitions->allowedFrom((string) $order->getStatus()), [Order::STATUS_CANCELLED])),
            'canCancel' => in_array(Order::STATUS_CANCELLED, $transitions->allowedFrom((string) $order->getStatus()), true),
            'deleteBlocker' => match (true) {
                Order::STATUS_CANCELLED !== $order->getStatus() => 'Only cancelled orders can be deleted.',
                Payment::STATUS_PAID === $order->getPayment()?->getStatus() => 'Refund the paid payment before deleting.',
                $orders->hasReturnRequests($order) => 'A return request is on file, so this order is kept.',
                default => null,
            },
        ]);
    }

    #[Route('/admin/orders/{id}/transition', name: 'app_admin_order_transition', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function transition(Order $order, Request $request, OrderTransitionService $transitions, AuditLogRepository $audit): Response
    {
        $this->checkToken($request, $order);
        $to = $request->request->getString('to');

        try {
            $from = $order->getStatus();
            $transitions->transition($order, $to, $this->admin(), $request->request->getString('note') ?: null);
            $audit->record($this->admin(), 'order.transition', 'Order', (int) $order->getId(), ['from' => $from, 'to' => $to]);
            $this->addFlash('success', 'Order moved to '.$to.'.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/orders/{id}/cancel', name: 'app_admin_order_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(Order $order, Request $request, OrderTransitionService $transitions, AuditLogRepository $audit): Response
    {
        $this->checkToken($request, $order);
        $reason = mb_substr(trim($request->request->getString('reason')), 0, 255);

        if ('' === $reason) {
            $this->addFlash('error', 'A cancellation reason is required.');
        } else {
            try {
                $transitions->cancel($order, $reason, $this->admin());
                $audit->record($this->admin(), 'order.cancel', 'Order', (int) $order->getId(), ['reason' => $reason, 'total' => $order->getTotal()]);
                $this->addFlash('success', 'Order cancelled.');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Cancels a suspicious order in one step, putting stock back and failing an unpaid payment,
     * and can deactivate the customer so they cannot place another.
     */
    #[Route('/admin/orders/{id}/fraud', name: 'app_admin_order_fraud', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function fraud(Order $order, Request $request, OrderTransitionService $transitions, AuditLogRepository $audit, EntityManagerInterface $em): Response
    {
        $this->checkToken($request, $order);
        $note = mb_substr(trim($request->request->getString('note')), 0, 240);
        $reason = 'Fraud'.('' === $note ? '' : ': '.$note);

        try {
            $transitions->cancel($order, $reason, $this->admin());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }

        $customer = $order->getCustomer();
        $blocked = false;
        if ($request->request->getBoolean('block') && null !== $customer && $customer !== $this->admin() && !in_array('ROLE_ADMIN', $customer->getRoles(), true) && $customer->isActive()) {
            $customer->setIsActive(false);
            $em->flush();
            $blocked = true;
            $audit->record($this->admin(), 'user.deactivate', 'User', (int) $customer->getId(), ['reason' => 'Fraud on '.$order->getOrderNumber()]);
        }

        $audit->record($this->admin(), 'order.fraud', 'Order', (int) $order->getId(), ['reason' => $reason, 'total' => $order->getTotal(), 'customerBlocked' => $blocked]);
        $this->addFlash('success', 'Order flagged as fraud and cancelled'.($blocked ? '; the customer account is deactivated.' : '.'));

        return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Permanently deletes a cancelled order. Orders with money still held, or with a return on file, stay for the books.
     */
    #[Route('/admin/orders/{id}/delete', name: 'app_admin_order_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Order $order, Request $request, OrderRepository $orders, AuditLogRepository $audit): Response
    {
        $this->checkToken($request, $order);
        $number = (string) $order->getOrderNumber();

        $problem = match (true) {
            Order::STATUS_CANCELLED !== $order->getStatus() => 'Only cancelled orders can be deleted. Cancel it or flag it as fraud first.',
            Payment::STATUS_PAID === $order->getPayment()?->getStatus() => 'This order has a paid payment. Refund it before deleting the order.',
            $orders->hasReturnRequests($order) => 'This order has a return request on file and cannot be deleted.',
            trim($request->request->getString('confirm')) !== $number => 'Type the order number exactly to confirm the deletion.',
            default => null,
        };
        if (null !== $problem) {
            $this->addFlash('error', $problem);

            return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }

        $id = (int) $order->getId();
        $snapshot = [
            'orderNumber' => $number,
            'customer' => $order->getCustomer()?->getEmail(),
            'total' => $order->getTotal(),
            'createdAt' => $order->getCreatedAt()?->format(\DATE_ATOM),
            'cancelReason' => $order->getCancelReason(),
            'payment' => $order->getPayment() ? $order->getPayment()->getMethod().' / '.$order->getPayment()->getStatus() : null,
            'items' => array_map(static fn ($item) => $item->getQuantity().' x '.$item->getProductName().' - '.$item->getVariantName(), $order->getItems()->toArray()),
        ];

        $orders->deleteWithHistory($order);
        $audit->record($this->admin(), 'order.delete', 'Order', $id, $snapshot);
        $this->addFlash('success', sprintf('Order %s was deleted.', $number));

        return $this->redirectToRoute('app_admin_order', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/orders/{id}/details', name: 'app_admin_order_details', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function details(Order $order, Request $request, EntityManagerInterface $em): Response
    {
        $this->checkToken($request, $order);
        $tracking = mb_substr(trim($request->request->getString('trackingNumber')), 0, 80);
        $note = trim($request->request->getString('internalNote'));

        $order->setTrackingNumber('' === $tracking ? null : $tracking);
        $order->setInternalNote('' === $note ? null : $note);
        $em->flush();
        $this->addFlash('success', 'Order details saved.');

        return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request, Order $order): void
    {
        if (!$this->isCsrfTokenValid('admin-order'.$order->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
