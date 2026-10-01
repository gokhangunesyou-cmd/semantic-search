<?php

namespace App\Service\V2\Search;

use App\Service\Elastic;
use App\Service\Embeddings;
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
            ->weights($input->weights)
            ->documentScore($input->includeDocumentScore, $input->documentScoreMaxMultiplier)
            ->size($input->limit)
            ->page($input->page)
            ->build();
        $result = $this->execute($query);
        return $this->format($result['hits'], $input, $vector);
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

    private function format(array $hits, SearchInput $input, array $vector): array
    {
        $total = (int)($hits['total']['value'] ?? count($hits['hits']));
        return [
            'mode' => 'semantic',
            'version' => 'v2',
            'weights' => $input->weights,
            'document_score' => [
                'enabled' => $input->includeDocumentScore,
                'max_multiplier' => $input->documentScoreMaxMultiplier
            ],
            'page' => $input->page,
            'limit' => $input->limit,
            'total' => $total,
            'pages' => min((int)ceil($total / $input->limit), (int)floor(10000 / $input->limit)),
            'count' => count($hits['hits']),
            'items' => array_map(static fn($hit) => [
                'id' => $hit['_id'],
                'score' => $hit['_score'],
                'explain' => ScoreExplanation::fromSource(
                    $hit['_source'], $vector, $input->weights, $hit['_score'],
                    $input->includeDocumentScore, $input->documentScoreMaxMultiplier
                ),
                'document' => $hit['_source']
            ], $hits['hits'])
        ];
    }
}