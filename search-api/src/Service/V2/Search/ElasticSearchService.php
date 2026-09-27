<?php

namespace App\Service\V2\Search;

use App\Service\Elastic;
use App\Service\Embeddings;
use App\Service\V2\Search\QueryBuilder\Filter\ProductBrandFilter;
use App\Service\V2\Search\QueryBuilder\Filter\ProductCategoryFilter;
use App\Service\V2\Search\QueryBuilder\ProductVectorQuery;
use App\Service\V2\Search\QueryBuilder\QueryBuilder;

final class ElasticSearchService
{
    public function __construct(
        private Elastic $elastic,
        private Embeddings $embeddings,
        private QueryBuilder $queryBuilder
    )
    {
    }

    public function search(SearchInput $input): array
    {
        $vector = $this->embeddings->encode('query', [$input->query])[0]['embedding'];
        $query = $this->queryBuilder
            ->vector($vector)
            ->applyFilter(new ProductBrandFilter($input->brandId))
            ->applyFilter(new ProductCategoryFilter($input->categoryIds))
            ->size($input->limit)
            ->build();
        $result = $this->execute($query);
        return $this->format($result['hits']['hits']);
    }

    private function execute(array $query): array
    {
        $path = rawurlencode($this->elastic->alias()) . '/_search?allow_partial_search_results=false';
        $result = $this->elastic->request('POST', $path, $query);
        if (($result['timed_out'] ?? false) || ($result['_shards']['failed'] ?? 0) > 0) {
            throw new \RuntimeException('Eksik Elasticsearch arama yanıtı.');
        }
        return $result;
    }

    private function format(array $hits): array
    {
        return [
            'mode' => 'semantic',
            'version' => 'v2',
            'weights' => ['semantic' => ProductVectorQuery::BASE_WEIGHT] + ProductVectorQuery::WEIGHTS,
            'count' => count($hits),
            'items' => array_map(static fn($hit) => [
                'id' => $hit['_id'], 'score' => $hit['_score'], 'document' => $hit['_source']
            ], $hits)
        ];
    }
}