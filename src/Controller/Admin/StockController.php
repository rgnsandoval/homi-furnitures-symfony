<?php

namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Form\StockAdjustmentFormType;
use App\Repository\AuditLogRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StockController extends AbstractController
{
    #[Route('/admin/stock/{id}', name: 'app_admin_stock', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function index(
        ProductVariant $variant,
        Request $request,
        EntityManagerInterface $em,
        ProductVariantRepository $variants,
        AuditLogRepository $audit,
    ): Response {
        $form = $this->createForm(StockAdjustmentFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $admin */
            $admin = $this->getUser();
            $data = $form->getData();
            $change = (int) $data['quantityChange'];
            $applied = false;

            $em->beginTransaction();
            try {
                $locked = $variants->lockForUpdate([(int) $variant->getId()])[$variant->getId()];
                if ($locked->getStock() + $change >= 0) {
                    $locked->setStock($locked->getStock() + $change);
                    $em->persist((new StockMovement())
                        ->setVariant($locked)
                        ->setQuantityChange($change)
                        ->setMovementReason($data['movementReason'])
                        ->setNote($data['note'])
                        ->setAdminUser($admin));
                    $em->flush();
                    $applied = true;
                }
                $em->commit();
            } catch (\Throwable $e) {
                $em->rollback();
                throw $e;
            }

            if ($applied) {
                $audit->record($admin, 'stock.adjust', 'ProductVariant', (int) $variant->getId(), ['change' => $change, 'reason' => $data['movementReason'], 'stock' => $variant->getStock()]);
                $this->addFlash('success', 'Stock updated.');

                return $this->redirectToRoute('app_admin_stock', ['id' => $variant->getId()], Response::HTTP_SEE_OTHER);
            }

            $form->get('quantityChange')->addError(new FormError('Stock cannot go below zero.'));
        }

        return $this->render('admin/stock/index.html.twig', ['variant' => $variant, 'form' => $form]);
    }
}
