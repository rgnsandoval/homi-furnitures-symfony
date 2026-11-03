<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Service\OrderDeletionService;
use App\Service\OrderTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderShowController extends AbstractController
{
    #[Route('/admin/orders/{id}', name: 'app_admin_order_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(Order $order, OrderRepository $orders, OrderTransitionService $transitions, OrderDeletionService $deletion, AddressRepository $addresses): Response
    {
        return $this->render('admin/order_show/index.html.twig', [
            'order' => $order,
            'customerAddresses' => $addresses->findBy(['owner' => $order->getCustomer()], ['isDefault' => 'DESC', 'id' => 'ASC']),
            'history' => $orders->statusHistory($order),
            'nextStatuses' => array_values(array_diff($transitions->allowedFrom((string) $order->getStatus()), [Order::STATUS_CANCELLED])),
            'canCancel' => in_array(Order::STATUS_CANCELLED, $transitions->allowedFrom((string) $order->getStatus()), true),
            'deleteEffects' => array_values(array_filter([
                $deletion->restocksOnDelete($order) ? 'The items go back into stock and any voucher use is released.' : null,
                in_array($order->getStatus(), [Order::STATUS_SHIPPED, Order::STATUS_DELIVERED], true) ? 'The goods have already left, so nothing is restocked.' : null,
                Payment::STATUS_PAID === $order->getPayment()?->getStatus() ? sprintf('Its paid payment of PHP %s is removed and will no longer count as revenue. Refund the customer separately if the money was real.', $order->getPayment()->getAmount()) : null,
                $orders->hasReturnRequests($order) ? 'Its return request is deleted too.' : null,
            ])),
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
     * Permanently deletes an order of any status (fraud, test or duplicate orders). Stock the order still held goes back;
     * the audit log keeps a summary of what was removed.
     */
    #[Route('/admin/orders/{id}/delete', name: 'app_admin_order_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Order $order, Request $request, OrderDeletionService $deletion, AuditLogRepository $audit): Response
    {
        $this->checkToken($request, $order);
        $number = (string) $order->getOrderNumber();

        if (trim($request->request->getString('confirm')) !== $number) {
            $this->addFlash('error', 'Type the order number exactly to confirm the deletion.');

            return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }

        $id = (int) $order->getId();
        $summary = $deletion->delete($order, $this->admin());
        $audit->record($this->admin(), 'order.delete', 'Order', $id, $summary);
        $this->addFlash('success', sprintf('Order %s was deleted%s.', $number, $summary['restocked'] ? ' and its items are back in stock' : ''));

        return $this->redirectToRoute('app_admin_order', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/orders/{id}/details', name: 'app_admin_order_details', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function details(Order $order, Request $request, EntityManagerInterface $em, AddressRepository $addresses, AuditLogRepository $audit): Response
    {
        $this->checkToken($request, $order);
        $tracking = mb_substr(trim($request->request->getString('trackingNumber')), 0, 80);
        $note = trim($request->request->getString('internalNote'));

        $order->setTrackingNumber('' === $tracking ? null : $tracking);
        $order->setInternalNote('' === $note ? null : $note);

        if ($request->request->has('notes')) {
            $notes = mb_substr(trim($request->request->getString('notes')), 0, 500);
            $order->setNotes('' === $notes ? null : $notes);
        }

        // The address must be one of this customer's own.
        if (0 !== $addressId = $request->request->getInt('address')) {
            $address = $addresses->find($addressId);
            if (null !== $address && $address->getOwner() === $order->getCustomer()) {
                $order->setShippingAddress($address);
            }
        }

        $date = $request->request->getString('estimatedDeliveryDate');
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && false !== $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) {
            $order->setEstimatedDeliveryDate($parsed);
        }

        $em->flush();
        $audit->record($this->admin(), 'order.update', 'Order', (int) $order->getId(), ['tracking' => $order->getTrackingNumber()]);
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
