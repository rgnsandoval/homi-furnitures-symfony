<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\PaymentRepository;
use App\Service\PaymentTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentQueueController extends AbstractController
{
    public function __construct(
        private PaymentTransitionService $transitions,
        private AuditLogRepository $audit,
    ) {
    }

    #[Route('/admin/payments/queue', name: 'app_admin_payment_queue', methods: ['GET'])]
    public function index(Request $request, PaymentRepository $payments): Response
    {
        $bankPage = max(1, $request->query->getInt('bankPage', 1));
        $codPage = max(1, $request->query->getInt('codPage', 1));
        $paidPage = max(1, $request->query->getInt('paidPage', 1));

        return $this->render('admin/payment_queue/index.html.twig', [
            'bankTransfers' => $payments->findPendingBankTransfersPage($bankPage),
            'collectableCod' => $payments->findCollectableCodPage($codPage),
            'paid' => $payments->findPaidPage($paidPage),
            'bankPage' => $bankPage,
            'codPage' => $codPage,
            'paidPage' => $paidPage,
        ]);
    }

    #[Route('/admin/payments/{id}/approve', name: 'app_admin_payment_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(Payment $payment, Request $request): Response
    {
        $allowed = Payment::METHOD_BANK === $payment->getMethod() && null !== $payment->getProofFile();

        return $this->act($payment, $request, $allowed, Payment::STATUS_PAID, 'payment.approve', 'Bank transfer approved');
    }

    #[Route('/admin/payments/{id}/reject', name: 'app_admin_payment_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(Payment $payment, Request $request): Response
    {
        return $this->act($payment, $request, Payment::METHOD_BANK === $payment->getMethod(), Payment::STATUS_FAILED, 'payment.reject', 'Bank transfer rejected');
    }

    #[Route('/admin/payments/{id}/collect', name: 'app_admin_payment_collect', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function collect(Payment $payment, Request $request): Response
    {
        $allowed = Payment::METHOD_COD === $payment->getMethod() && Order::STATUS_DELIVERED === $payment->getParentOrder()->getStatus();

        return $this->act($payment, $request, $allowed, Payment::STATUS_PAID, 'payment.collect', 'Cash collected');
    }

    #[Route('/admin/payments/{id}/refund', name: 'app_admin_payment_refund', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refund(Payment $payment, Request $request): Response
    {
        return $this->act($payment, $request, true, Payment::STATUS_REFUNDED, 'payment.refund', 'Refund issued');
    }

    private function act(Payment $payment, Request $request, bool $allowed, string $to, string $action, string $note): Response
    {
        if (!$this->isCsrfTokenValid('payment'.$payment->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$allowed) {
            $this->addFlash('error', 'That action does not apply to this payment.');

            return $this->redirectToRoute('app_admin_payment_queue', [], Response::HTTP_SEE_OTHER);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $from = $payment->getStatus();

        try {
            $this->transitions->transition($payment, $to, $admin, $note);
            $this->audit->record($admin, $action, 'Payment', (int) $payment->getId(), [
                'from' => $from,
                'to' => $to,
                'amount' => $payment->getAmount(),
                'order' => $payment->getParentOrder()->getOrderNumber(),
            ]);
            $this->addFlash('success', $note.'.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_payment_queue', [], Response::HTTP_SEE_OTHER);
    }
}
