<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Security\Voter\PaymentProofVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentProofController extends AbstractController
{
    #[Route('/orders/proof/{id}', name: 'app_payment_proof', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(
        Payment $payment,
        #[Autowire('%kernel.project_dir%/var/uploads/proofs')] string $proofDir,
    ): BinaryFileResponse {
        $this->denyAccessUnlessGranted(PaymentProofVoter::VIEW, $payment);

        $path = $proofDir.'/'.basename((string) $payment->getProofFile());
        if (null === $payment->getProofFile() || !is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'payment-proof-'.$payment->getId().'.'.pathinfo($path, PATHINFO_EXTENSION));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
