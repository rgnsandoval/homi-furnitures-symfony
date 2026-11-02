<?php

namespace App\Controller;

use App\Entity\Address;
use App\Entity\User;
use App\Form\AddressType;
use App\Repository\AddressRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/addresses')]
final class AddressController extends AbstractController
{
    #[Route(name: 'app_address_index', methods: ['GET'])]
    public function index(Request $request, AddressRepository $addresses): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('address/index.html.twig', [
            'addresses' => $addresses->findForUser($this->currentUser(), $page),
            'page' => $page,
        ]);
    }

    #[Route('/new', name: 'app_address_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, AddressRepository $addresses): Response
    {
        $user = $this->currentUser();
        $address = (new Address())->setOwner($user);
        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (0 === $addresses->countForUser($user)) {
                $address->setIsDefault(true);
            }
            if ($address->isDefault()) {
                $addresses->clearDefaultExcept($user, $address);
            }
            $em->persist($address);
            $em->flush();

            return $this->redirectToRoute('app_address_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('address/new.html.twig', ['address' => $address, 'form' => $form]);
    }

    #[Route('/{id}', name: 'app_address_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Address $address): Response
    {
        $this->checkOwner($address);

        return $this->render('address/show.html.twig', ['address' => $address]);
    }

    #[Route('/{id}/edit', name: 'app_address_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Address $address, EntityManagerInterface $em, AddressRepository $addresses): Response
    {
        $this->checkOwner($address);
        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($address->isDefault()) {
                $addresses->clearDefaultExcept($this->currentUser(), $address);
            }
            $em->flush();

            return $this->redirectToRoute('app_address_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('address/edit.html.twig', ['address' => $address, 'form' => $form]);
    }

    #[Route('/{id}', name: 'app_address_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Address $address, EntityManagerInterface $em): Response
    {
        $this->checkOwner($address);

        if ($this->isCsrfTokenValid('delete'.$address->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $em->remove($address);
                $em->flush();
            } catch (ForeignKeyConstraintViolationException) {
                // The row is referenced by a past order; the EntityManager is closed, so only redirect.
                $this->addFlash('error', 'This address is used by an order and cannot be deleted.');
            }
        }

        return $this->redirectToRoute('app_address_index', [], Response::HTTP_SEE_OTHER);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function checkOwner(Address $address): void
    {
        if ($address->getOwner() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }
    }
}
