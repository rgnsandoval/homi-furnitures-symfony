<?php

namespace App\Controller;

use App\Entity\Inquiry;
use App\Form\ContactFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        #[Target('contact_form.limiter')] RateLimiterFactory $limiter,
    ): Response {
        $inquiry = new Inquiry();
        $form = $this->createForm(ContactFormType::class, $inquiry);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if (!$limiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('error', 'Too many messages. Please try again later.');

                return $this->redirectToRoute('app_contact', [], Response::HTTP_SEE_OTHER);
            }

            if ('' !== (string) $form->get('website')->getData()) {
                // Looks like success so the bot learns nothing; nothing is stored.
                $this->addFlash('success', 'Thanks! We will get back to you soon.');

                return $this->redirectToRoute('app_contact', [], Response::HTTP_SEE_OTHER);
            }

            if ($form->isValid()) {
                $em->persist($inquiry);
                $em->flush();
                $this->addFlash('success', 'Thanks! We will get back to you soon.');

                return $this->redirectToRoute('app_contact', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('contact/index.html.twig', ['form' => $form]);
    }
}
