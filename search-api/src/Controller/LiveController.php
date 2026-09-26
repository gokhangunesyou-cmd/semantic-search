<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class LiveController
{
    #[Route('/health/live', methods: ['GET'])]
    public function live(): JsonResponse { return new JsonResponse(['status' => 'ok']); }
}
