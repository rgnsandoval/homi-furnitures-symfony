<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\ReturnRequest;
use App\Entity\User;
use App\Form\ReturnRequestFormType;
use App\Repository\PlatformSettingRepository;
use App\Repository\ReturnRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReturnNewController extends AbstractController
{
    #[Route('/orders/{id}/return', name: 'app_return_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function index(
        Order $order,
        Request $request,
        ReturnRequestRepository $returns,
        PlatformSettingRepository $settings,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/var/uploads/returns')] string $returnDir,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if ($order->getCustomer() !== $user) {
            throw $this->createNotFoundException();
        }

        $problem = $this->problem($order, $returns, $settings->getInt('return_window_days', 7));
        if (null !== $problem) {
            $this->addFlash('error', $problem);

            return $this->redirectToRoute('app_order_detail', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }

        $return = (new ReturnRequest())->setParentOrder($order)->setCustomer($user);
        $form = $this->createForm(ReturnRequestFormType::class, $return);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $photo */
            $photo = $form->get('photoFile')->getData();
            if (null !== $photo) {
                $filename = bin2hex(random_bytes(16)).'.'.$photo->guessExtension();
                $photo->move($returnDir, $filename);
                $return->setPhoto($filename);
            }

            $em->persist($return);
            $em->flush();
            $this->addFlash('success', 'Your return request has been sent.');

            return $this->redirectToRoute('app_return_list', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('return_new/index.html.twig', ['order' => $order, 'form' => $form]);
    }

    private function problem(Order $order, ReturnRequestRepository $returns, int $windowDays): ?string
    {
        if (Order::STATUS_DELIVERED !== $order->getStatus() || null === $order->getDeliveredAt()) {
            return 'Only delivered orders can be returned.';
        }
        if ($order->getDeliveredAt()->modify("+$windowDays days") < new \DateTimeImmutable()) {
            return sprintf('The %d day return window for this order has ended.', $windowDays);
        }
        if (null !== $returns->findActiveForOrder($order)) {
            return 'A return has already been requested for this order.';
        }

        return null;
    }
}
