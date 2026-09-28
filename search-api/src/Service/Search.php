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
        $filters = $input['filters'] ?? [];
        if (!is_array($filters) || array_diff(array_keys($filters), ['brand_id', 'category_ids'])) throw new \InvalidArgumentException('Desteklenmeyen filtre.');
        $index = rawurlencode($this->elastic->alias());
        $mapping = $this->elastic->request('GET', $index.'/_mapping');
        if (count($mapping) !== 1) throw new \RuntimeException('Arama aliası tek indekse bağlı olmalı.');
        $definition = reset($mapping)['mappings'];
        $meta = $definition['_meta'] ?? [];
        if (($meta['embedding_version'] ?? '') !== $this->embeddings->version() || ($meta['text_version'] ?? '') !== DocumentText::VERSION) throw new \RuntimeException('Arama/model sürümü uyuşmuyor.');
        $filter = [];
        if (isset($filters['brand_id'])) {
            if (!is_string($filters['brand_id']) && !is_int($filters['brand_id'])) throw new \InvalidArgumentException('brand_id geçersiz.');
            $filter[] = ['term' => ['brand.id' => $filters['brand_id']]];
        }
        if (isset($filters['category_ids'])) {
            if (!is_array($filters['category_ids']) || !$filters['category_ids'] || count($filters['category_ids']) > 50) throw new \InvalidArgumentException('category_ids dizi olmalı (1–50).');
            foreach ($filters['category_ids'] as $id) if (!is_string($id) && !is_int($id)) throw new \InvalidArgumentException('Kategori ID geçersiz.');
            $treeQuery = ['terms' => ['category.tree.id' => $filters['category_ids']]];
            if (($definition['properties']['category']['properties']['tree']['type'] ?? null) === 'nested') {
                $treeQuery = ['nested' => ['path' => 'category.tree', 'query' => $treeQuery]];
            }
            $filter[] = ['bool' => ['should' => [
                ['terms' => ['category.id' => $filters['category_ids']]],
                $treeQuery,
            ], 'minimum_should_match' => 1]];
        }
        $vector = $this->embeddings->encode('query', [$query])[0]['embedding'];
        $base = ['size' => $limit, '_source' => true];
        $knn = ['field' => 'semantic.vector', 'query_vector' => $vector, 'k' => $limit, 'num_candidates' => 100];
        if ($filter) $knn['filter'] = ['bool' => ['filter' => $filter]];
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