<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\PlatformSettingRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    private const ROOM_CARDS = [
        ['name' => 'Living room', 'slug' => 'living-room', 'param' => 'room', 'image' => 'living', 'class' => 'md:col-span-2 md:row-span-2'],
        ['name' => 'Dining room', 'slug' => 'dining-room', 'param' => 'room', 'image' => 'dining', 'class' => 'md:row-span-2'],
        ['name' => 'Bedroom', 'slug' => 'bedroom', 'param' => 'room', 'image' => 'bedroom', 'class' => ''],
        ['name' => 'Accent chairs', 'slug' => 'armchairs-and-accent-chairs', 'param' => 'category', 'image' => 'chair', 'class' => ''],
    ];

    #[Route('/', name: 'app_home')]
    public function index(ProductRepository $products, CategoryRepository $categories, PlatformSettingRepository $settings): Response
    {
        $featured = $products->findFeatured();
        $onSale = $products->findOnSale(4);
        $products->loadVariants([...$featured, ...$onSale]);

        $roomCards = [];
        foreach (self::ROOM_CARDS as $card) {
            $roomCards[] = $card + ['count' => $products->countActiveInCategory($card['slug'])];
        }

        return $this->render('home/index.html.twig', [
            'rooms' => $categories->findActiveRoots(),
            'roomCards' => $roomCards,
            'featured' => $featured,
            'onSale' => $onSale,
            'returnDays' => $settings->getInt('return_window_days', 7),
        ]);
    }
}
