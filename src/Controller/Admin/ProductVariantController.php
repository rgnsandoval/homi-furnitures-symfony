<?php

namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use App\Form\ProductVariantType;
use App\Repository\ProductVariantRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/product/variant')]
final class ProductVariantController extends AbstractController
{
    #[Route(name: 'app_admin_product_variant_index', methods: ['GET'])]
    public function index(Request $request, ProductVariantRepository $productVariantRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('admin/product_variant/index.html.twig', [
            'product_variants' => $productVariantRepository->findPage($page),
            'page' => $page,
        ]);
    }

    #[Route('/new', name: 'app_admin_product_variant_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $productVariant = new ProductVariant();
        $form = $this->createForm(ProductVariantType::class, $productVariant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($productVariant);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_product_variant_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/product_variant/new.html.twig', [
            'product_variant' => $productVariant,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_product_variant_show', methods: ['GET'])]
    public function show(ProductVariant $productVariant): Response
    {
        return $this->render('admin/product_variant/show.html.twig', [
            'product_variant' => $productVariant,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_product_variant_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ProductVariant $productVariant, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ProductVariantType::class, $productVariant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_product_variant_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/product_variant/edit.html.twig', [
            'product_variant' => $productVariant,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_product_variant_delete', methods: ['POST'])]
    public function delete(Request $request, ProductVariant $productVariant, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$productVariant->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $entityManager->remove($productVariant);
                $entityManager->flush();
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash('error', 'This record is in use and cannot be deleted. Deactivate it instead.');
            }
        }

        return $this->redirectToRoute('app_admin_product_variant_index', [], Response::HTTP_SEE_OTHER);
    }
}
