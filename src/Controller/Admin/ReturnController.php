<?php

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Entity\ReturnRequest;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\ReturnRequestRepository;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReturnController extends AbstractController
{
    #[Route('/admin/returns', name: 'app_admin_return', methods: ['GET'])]
    public function index(Request $request, ReturnRequestRepository $returns): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('admin/return/index.html.twig', [
            'returns' => $returns->findOpen($page),
            'page' => $page,
        ]);
    }

    #[Route('/admin/returns/{id}/approve', name: 'app_admin_return_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(ReturnRequest $return, Request $request, EntityManagerInterface $em, AuditLogRepository $audit): Response
    {
        return $this->decide($return, $request, $em, $audit, ReturnRequest::STATUS_APPROVED, 'return.approve');
    }

    #[Route('/admin/returns/{id}/reject', name: 'app_admin_return_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(ReturnRequest $return, Request $request, EntityManagerInterface $em, AuditLogRepository $audit): Response
    {
        return $this->decide($return, $request, $em, $audit, ReturnRequest::STATUS_REJECTED, 'return.reject');
    }

    /**
     * Refunds the payment and puts the returned units back in stock, all in one transaction.
     */
    #[Route('/admin/returns/{id}/refund', name: 'app_admin_return_refund', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refund(
        ReturnRequest $return,
        Request $request,
        EntityManagerInterface $em,
        PaymentTransitionService $payments,
        ProductVariantRepository $variants,
        AuditLogRepository $audit,
    ): Response {
        $this->checkToken($request, $return);
        /** @var User $admin */
        $admin = $this->getUser();
        $order = $return->getParentOrder();
        $payment = $order->getPayment();

        if (ReturnRequest::STATUS_APPROVED !== $return->getStatus()) {
            $this->addFlash('error', 'Only approved returns can be refunded.');

            return $this->redirectToRoute('app_admin_return', [], Response::HTTP_SEE_OTHER);
        }
        if (Payment::STATUS_PAID !== $payment->getStatus()) {
            $this->addFlash('error', 'This order has no paid payment to refund.');

            return $this->redirectToRoute('app_admin_return', [], Response::HTTP_SEE_OTHER);
        }

        $em->beginTransaction();
        try {
            $payments->apply($payment, Payment::STATUS_REFUNDED, $admin, 'Return refunded');

            $ids = [];
            foreach ($order->getItems() as $item) {
                $ids[] = (int) $item->getVariant()->getId();
            }
            $locked = $variants->lockForUpdate($ids);
            foreach ($order->getItems() as $item) {
                $variant = $locked[$item->getVariant()->getId()];
                $variant->setStock($variant->getStock() + (int) $item->getQuantity());
                $em->persist((new StockMovement())
                    ->setVariant($variant)
                    ->setQuantityChange((int) $item->getQuantity())
                    ->setMovementReason(StockMovement::REASON_RETURN)
                    ->setNote('Return for order '.$order->getOrderNumber())
                    ->setAdminUser($admin));
            }

            $return->setStatus(ReturnRequest::STATUS_REFUNDED);
            $note = trim($request->request->getString('adminNote'));
            if ('' !== $note) {
                $return->setAdminNote($note);
            }

            $payments->flush();
            $em->commit();
        } catch (\Throwable $e) {
            $em->rollback();
            throw $e;
        }

        $payments->dispatchChanged($payment, Payment::STATUS_PAID, Payment::STATUS_REFUNDED);
        $audit->record($admin, 'return.refund', 'ReturnRequest', (int) $return->getId(), [
            'order' => $order->getOrderNumber(),
            'amount' => $payment->getAmount(),
        ]);
        $this->addFlash('success', 'Return refunded and stock restored.');

        return $this->redirectToRoute('app_admin_return', [], Response::HTTP_SEE_OTHER);
    }

    private function decide(ReturnRequest $return, Request $request, EntityManagerInterface $em, AuditLogRepository $audit, string $to, string $action): Response
    {
        $this->checkToken($request, $return);

        if (ReturnRequest::STATUS_REQUESTED !== $return->getStatus()) {
            $this->addFlash('error', 'This return has already been decided.');

            return $this->redirectToRoute('app_admin_return', [], Response::HTTP_SEE_OTHER);
        }

        $note = trim($request->request->getString('adminNote'));
        $return->setStatus($to)->setAdminNote('' === $note ? null : $note);
        $em->flush();

        /** @var User $admin */
        $admin = $this->getUser();
        $audit->record($admin, $action, 'ReturnRequest', (int) $return->getId(), ['order' => $return->getParentOrder()->getOrderNumber()]);
        $this->addFlash('success', 'Return '.$to.'.');

        return $this->redirectToRoute('app_admin_return', [], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request, ReturnRequest $return): void
    {
        if (!$this->isCsrfTokenValid('return'.$return->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
