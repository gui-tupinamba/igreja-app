<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\ReadinessCheckInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/ready', name: 'api_ready', methods: ['GET'])]
    public function ready(ReadinessCheckInterface $readiness): JsonResponse
    {
        if (!$readiness->isReady()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'SERVICE_UNAVAILABLE',
                    'message' => 'Serviço temporariamente indisponível.',
                ],
            ], JsonResponse::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse(['status' => 'ok'], headers: ['Cache-Control' => 'no-store']);
    }
}
