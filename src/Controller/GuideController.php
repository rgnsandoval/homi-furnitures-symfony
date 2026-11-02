<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GuideController extends AbstractController
{
    private const MATERIALS = [
        'Solid wood' => 'Long lasting and repairable. Keep it out of direct sun and away from damp walls.',
        'Engineered wood' => 'Affordable and stable. Wipe spills quickly and avoid standing water.',
        'Metal' => 'Slim and strong, and well suited to small rooms. Check for rust protection near the coast.',
        'Rattan' => 'Light and airy, and a good match for the local climate. Keep it indoors or under a roof.',
        'Fabric' => 'Soft and comfortable. Choose a removable cover if you have children or pets.',
        'Leather' => 'Ages well and wipes clean. Condition it twice a year.',
    ];

    /**
     * Furniture should leave walking room, so one piece may take up to 60% of the room's width and 40% of its depth.
     */
    private const WIDTH_SHARE = '0.60';
    private const DEPTH_SHARE = '0.40';

    #[Route('/guide', name: 'app_guide', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products): Response
    {
        $width = $request->query->getString('roomWidth');
        $depth = $request->query->getString('roomDepth');
        $valid = static fn (string $metres): bool => 1 === preg_match('/^\d{1,2}(\.\d{1,2})?$/', $metres) && 1 === bccomp($metres, '0', 2);

        $maxWidth = null;
        $maxDepth = null;
        $suggestions = [];

        if ($valid($width) && $valid($depth)) {
            $maxWidth = bcmul(bcmul($width, '100', 2), self::WIDTH_SHARE, 1);
            $maxDepth = bcmul(bcmul($depth, '100', 2), self::DEPTH_SHARE, 1);
            $suggestions = $products->search(['maxWidth' => $maxWidth, 'maxDepth' => $maxDepth, 'inStock' => true], 'newest', 1, 12);
        }

        return $this->render('guide/index.html.twig', [
            'materials' => self::MATERIALS,
            'roomWidth' => $width,
            'roomDepth' => $depth,
            'maxWidth' => $maxWidth,
            'maxDepth' => $maxDepth,
            'suggestions' => $suggestions,
        ]);
    }
}
