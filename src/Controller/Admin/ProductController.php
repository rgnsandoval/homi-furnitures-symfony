<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\ProductImage;
use App\Form\ProductType;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/product')]
final class ProductController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/products')] private string $uploadDir,
    ) {
    }

    #[Route(name: 'app_admin_product_index', methods: ['GET'])]
    public function index(Request $request, ProductRepository $productRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $q = $request->query->getString('q');

        return $this->render('admin/product/index.html.twig', [
            'products' => $productRepository->findAdminPage($q, $page),
            'page' => $page,
            'q' => $q,
        ]);
    }

    #[Route('/new', name: 'app_admin_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->storeUploads($form, $product, $entityManager);
            $entityManager->persist($product);
            $entityManager->flush();
            $this->addFlash('success', 'Product created. Add at least one variant so it can be sold.');

            return $this->redirectToRoute('app_admin_product_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/product/new.html.twig', ['product' => $product, 'form' => $form]);
    }

    #[Route('/{id}', name: 'app_admin_product_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Product $product): Response
    {
        return $this->render('admin/product/show.html.twig', ['product' => $product]);
    }

    #[Route('/{id}/edit', name: 'app_admin_product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Product $product, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->storeUploads($form, $product, $entityManager);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_product_edit', ['id' => $product->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/product/edit.html.twig', ['product' => $product, 'form' => $form]);
    }

    #[Route('/{id}', name: 'app_admin_product_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Product $product, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$product->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $entityManager->remove($product);
                $entityManager->flush();
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash('error', 'This product has orders and cannot be deleted. Archive it instead.');
            }
        }

        return $this->redirectToRoute('app_admin_product_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/image/{id}/delete', name: 'app_admin_product_image_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteImage(Request $request, ProductImage $image, EntityManagerInterface $entityManager): Response
    {
        $productId = $image->getProduct()->getId();

        if ($this->isCsrfTokenValid('delete-image'.$image->getId(), $request->getPayload()->getString('_token'))) {
            $path = $this->uploadDir.'/'.basename((string) $image->getFilename());
            $entityManager->remove($image);
            $entityManager->flush();
            if (is_file($path)) {
                unlink($path);
            }
        }

        return $this->redirectToRoute('app_admin_product_edit', ['id' => $productId], Response::HTTP_SEE_OTHER);
    }

    private function storeUploads(FormInterface $form, Product $product, EntityManagerInterface $entityManager): void
    {
        /** @var UploadedFile|null $main */
        $main = $form->get('imageFile')->getData();
        if (null !== $main) {
            $product->setImage($this->store($main));
        }

        /** @var list<UploadedFile> $gallery */
        $gallery = $form->get('galleryFiles')->getData() ?? [];
        $order = $product->getImages()->count();
        foreach ($gallery as $file) {
            $image = (new ProductImage())->setFilename($this->store($file))->setAltText($product->getName())->setSortOrder($order++);
            $product->addImage($image);
            $entityManager->persist($image);
        }
    }

    private function store(UploadedFile $file): string
    {
        $filename = bin2hex(random_bytes(16)).'.'.$file->guessExtension();
        $file->move($this->uploadDir, $filename);

        return $filename;
    }
}
