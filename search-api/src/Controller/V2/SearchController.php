<?php

namespace App\Controller\V2;

use App\Service\V2\Search\ElasticSearchService;
use App\Service\V2\Search\SearchInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController
{
    public function __construct(private ElasticSearchService $search)
    {
    }

    #[Route('/api/v2/search', name: 'api_v2_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        try {
            $input = SearchInput::fromArray($request->query->all());
            return new JsonResponse($this->search->search($input));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            error_log($exception->getMessage());
            return new JsonResponse(['error' => 'İşlem tamamlanamadı; servisleri kontrol edip tekrar deneyin.'], 503);
        }
    }
}