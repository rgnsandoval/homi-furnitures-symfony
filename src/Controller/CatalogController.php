<?php

namespace App\Controller;

use App\Repository\BrandRepository;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    private const TEXT_FILTERS = ['q', 'room', 'category', 'brand', 'material', 'color', 'inStock'];
    private const NUMBER_FILTERS = ['minPrice', 'maxPrice', 'maxWidth', 'maxDepth', 'maxHeight'];

    #[Route('/shop', name: 'app_shop', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products, CategoryRepository $categories, BrandRepository $brands): Response
    {
        $filters = [];
        foreach (self::TEXT_FILTERS as $key) {
            $filters[$key] = $request->query->getString($key);
        }
        foreach (self::NUMBER_FILTERS as $key) {
            $value = $request->query->getString($key);
            $filters[$key] = preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value) ? $value : '';
        }

        $sort = $request->query->getString('sort', 'newest');
        if (!in_array($sort, ProductRepository::SORTS, true)) {
            $sort = 'newest';
        }

        $page = max(1, $request->query->getInt('page', 1));

        $results = $products->search($filters, $sort, $page);
        $products->loadVariants($results);

        return $this->render('catalog/index.html.twig', [
            'products' => $results,
            'page' => $page,
            'filters' => $filters,
            'sort' => $sort,
            'rooms' => $categories->findActiveRoots(),
            'brands' => $brands->findBy(['isActive' => true], ['name' => 'ASC']),
            'materials' => $products->findDistinctMaterials(),
        ]);
    }
}
