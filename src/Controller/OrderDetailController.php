<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Payment;
use App\Form\PaymentProofFormType;
use App\Repository\OrderRepository;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderDetailController extends AbstractController
{
    #[Route('/orders/{id}', name: 'app_order_detail', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function index(
        Order $order,
        Request $request,
        OrderRepository $orders,
        EntityManagerInterface $em,
        PaymentTransitionService $payments,
        #[Autowire('%kernel.project_dir%/var/uploads/proofs')] string $proofDir,
    ): Response {
        $this->checkOwner($order);

        $payment = $order->getPayment();
        $proofForm = null;
        $canUpload = Payment::METHOD_BANK === $payment->getMethod()
            && in_array($payment->getStatus(), [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)
            && Order::STATUS_CANCELLED !== $order->getStatus();

        if ($canUpload) {
            $proofForm = $this->createForm(PaymentProofFormType::class);
            $proofForm->handleRequest($request);

            if ($proofForm->isSubmitted() && $proofForm->isValid()) {
                /** @var UploadedFile $file */
                $file = $proofForm->get('proof')->getData();
                $filename = bin2hex(random_bytes(16)).'.'.$file->guessExtension();
                $file->move($proofDir, $filename);

                $payment->setProofFile($filename);
                $em->flush();
                if (Payment::STATUS_FAILED === $payment->getStatus()) {
                    $payments->transition($payment, Payment::STATUS_PENDING, $order->getCustomer(), 'New proof uploaded');
                }
                $this->addFlash('success', 'Proof received. We will check it shortly.');

                return $this->redirectToRoute('app_order_detail', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('order_detail/index.html.twig', [
            'order' => $order,
            'payment' => $payment,
            'history' => $orders->statusHistory($order),
            'proofForm' => $proofForm,
        ]);
    }

    #[Route('/orders/{id}/cancel', name: 'app_order_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(Order $order, Request $request, OrderTransitionService $transitions): Response
    {
        $this->checkOwner($order);

        if (!$this->isCsrfTokenValid('cancel'.$order->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Customers may cancel only while pending; admins have more options.
        if (Order::STATUS_PENDING !== $order->getStatus()) {
            $this->addFlash('error', 'This order can no longer be cancelled.');

            return $this->redirectToRoute('app_order_detail', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }

        $reason = trim($request->request->getString('reason'));
        try {
            $transitions->cancel($order, '' === $reason ? 'Cancelled by customer' : mb_substr($reason, 0, 255), $order->getCustomer());
            $this->addFlash('success', 'Your order has been cancelled.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_order_detail', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    private function checkOwner(Order $order): void
    {
        if ($order->getCustomer() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }
    }
}
