<?php

namespace App\Controller\Admin;

use App\Entity\Order;
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
