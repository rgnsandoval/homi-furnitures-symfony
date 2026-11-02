<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\EmailVerifier;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;

final class VerifyNoticeController extends AbstractController
{
    #[Route('/account/verify', name: 'app_verify_notice', methods: ['GET', 'POST'])]
    public function index(Request $request, EmailVerifier $emailVerifier): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isVerified()) {
            return $this->redirectToRoute('app_account');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('verify-resend', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $emailVerifier->sendEmailConfirmation('app_verify_email', $user, (new TemplatedEmail())
                ->from(new Address('noreply@homi.test', 'Homi Furniture'))
                ->to((string) $user->getEmail())
                ->subject('Please Confirm your Email')
                ->htmlTemplate('registration/confirmation_email.html.twig'));
            $this->addFlash('success', 'Verification email sent.');

            return $this->redirectToRoute('app_verify_notice', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('verify_notice/index.html.twig');
    }
}
