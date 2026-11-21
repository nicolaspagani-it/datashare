<?php

namespace App\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    /**
     * Vérifier que le service répond.
     *
     * Route publique, utile pour surveiller l'application.
     */
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    #[OA\Tag(name: 'Système')]
    #[OA\Response(
        response: 200,
        description: 'Le service répond.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'app', type: 'string', example: 'datashare'),
            new OA\Property(property: 'status', type: 'string', example: 'ok'),
        ]),
    )]
    public function __invoke(): JsonResponse
    {
        return $this->json(['app' => 'datashare', 'status' => 'ok']);
    }
}
