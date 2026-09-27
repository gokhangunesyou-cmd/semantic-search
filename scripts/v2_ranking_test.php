<?php

/** Local Compose test: real Elasticsearch, deterministic vectors, isolated disposable index. */
require '/app/search-api/vendor/autoload.php';

use App\Service\Elastic;
use App\Service\Embeddings;
use App\Service\Mapping;
use App\Service\V2\Index\FieldVectors;
use App\Service\V2\Search\ElasticSearchService;
use App\Service\V2\Search\QueryBuilder\ProductVectorQuery;
use App\Service\V2\Search\QueryBuilder\QueryBuilder;
use App\Service\V2\Search\SearchInput;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$index = 'v2-ranking-test-' . bin2hex(random_bytes(6));
putenv('ELASTICSEARCH_ALIAS=' . $index);
$elastic = new Elastic(HttpClient::create());
$vector = static fn($x, $y) => [$x, $y, ...array_fill(0, 382, 0)];
$positive = $vector(1, 0);
$negative = $vector(-1, 0);
$embedding = new Embeddings(new MockHttpClient(static fn() => new MockResponse(json_encode([
    'model_version' => getenv('EMBEDDING_VERSION') ?: 'e5-small-onnx-fp32-v1',
    'dimensions' => 384, 'items' => [['embedding' => $positive]]
])), 'http://localhost'));
$service = new ElasticSearchService($elastic, $embedding, new QueryBuilder(new ProductVectorQuery()));

try {
    $semantic = Mapping::semantic();
    $semantic['properties']['v2'] = FieldVectors::mapping();
    $elastic->request('PUT', $index, [
        'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
        'mappings' => ['properties' => ['semantic' => $semantic, 'brand' => ['properties' => [
            'id' => ['type' => 'keyword']
        ]]]]
    ]);
    $bulk = '';
    for ($i = 0; $i < 152; ++$i) {
        $id = (string)$i;
        $doc = ['id' => $id, 'brand' => ['id' => $id], 'semantic' => []];
        if ($i !== 151) {
            $doc['semantic']['vector'] = $i === 0 ? $positive : ($i === 150 ? $negative : $vector(0.8, 0.6));
        }
        if ($i !== 2) {
            foreach (FieldVectors::FIELDS as $field) {
                $doc['semantic']['v2'][$field]['vector'] = in_array($i, [1, 150, 151]) ? $positive : $negative;
            }
        }
        $bulk .= json_encode(['index' => ['_index' => $index, '_id' => $id]]) . "\n";
        $bulk .= json_encode($doc) . "\n";
    }
    verify(!$elastic->bulk($bulk)['errors'], 'Fixture indexing failed');
    $elastic->request('POST', $index . '/_refresh');

    $result = $service->search(SearchInput::fromArray(['query' => 'test', 'limit' => 50]));
    $ids = array_column($result['items'], 'id');
    verify($ids[0] === '1', 'Field boosts did not promote the eligible candidate');
    verify(abs($result['items'][0]['score'] - 1.9) < 0.001, 'Base score + field boosts incorrect');
    verify(in_array('150', $ids, true), 'A fixed candidate cutoff prevented global boosted ranking');
    verify(!in_array('151', $ids, true), 'A product with no main vector entered the results');

    $fallback = $service->search(SearchInput::fromArray(['query' => 'test', 'brand_id' => '2']));
    verify($fallback['count'] === 1, 'Missing boost fields excluded a valid main-vector candidate');
    verify(abs($fallback['items'][0]['score'] - 0.9) < 0.001, 'Missing boost fields changed base score');
    echo "PASS: main-vector scoring, additive boosts, no candidate cutoff, missing-field fallback.\n";
} finally {
    $elastic->request('DELETE', $index, null, [404]);
}