<?php

namespace App\Controller;

use App\Entity\ReturnRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ReturnPhotoController extends AbstractController
{
    #[Route('/orders/returns/{id}/photo', name: 'app_return_photo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(
        ReturnRequest $return,
        #[Autowire('%kernel.project_dir%/var/uploads/returns')] string $returnDir,
    ): BinaryFileResponse {
        if ($return->getCustomer() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $path = $returnDir.'/'.basename((string) $return->getPhoto());
        if (null === $return->getPhoto() || !is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
