<?php
namespace App\Service;

final class Search
{
    public function __construct(private Elastic $elastic, private Embeddings $embeddings) {}

    public function find(array $input): array
    {
        $query = $input['query'] ?? null;
        $limit = $input['limit'] ?? 10;
        if (!is_string($query) || trim($query) === '' || strlen($query) > 2000 || !is_int($limit) || $limit < 1 || $limit > 50 || (isset($input['mode']) && $input['mode'] !== 'semantic')) {
            throw new \InvalidArgumentException('query, limit (1–50) veya mode geçersiz.');
        }
        if (isset($input['brand_id']) || isset($input['category_ids'])) {
            throw new \InvalidArgumentException('brand_id ve category_ids desteklenmiyor.');
        }
        $index = rawurlencode($this->elastic->alias());
        $mapping = $this->elastic->request('GET', $index.'/_mapping');
        if (count($mapping) !== 1) throw new \RuntimeException('Arama aliası tek indekse bağlı olmalı.');
        $definition = reset($mapping)['mappings'];
        $meta = $definition['_meta'] ?? [];
        if (($meta['embedding_version'] ?? '') !== $this->embeddings->version() || ($meta['text_version'] ?? '') !== DocumentText::VERSION) throw new \RuntimeException('Arama/model sürümü uyuşmuyor.');
        $vector = $this->embeddings->encode('query', [$query])[0]['embedding'];
        $base = ['size' => $limit, '_source' => true];
        $knn = ['field' => 'semantic.vector', 'query_vector' => $vector, 'k' => $limit, 'num_candidates' => 100];
        $hits = $this->elastic->request('POST', $index.'/_search', $base + ['knn' => $knn])['hits']['hits'];
        return $this->format($hits);
    }

    private function format(array $hits): array
    {
        return ['mode' => 'semantic', 'count' => count($hits), 'items' => array_map(static fn($hit) => [
            'id' => $hit['_id'], 'score' => $hit['_score'], 'document' => $hit['_source'],
        ], $hits)];
    }
}