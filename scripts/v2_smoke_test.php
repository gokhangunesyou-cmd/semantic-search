<?php
/** Run in the isolated local Compose stack; creates and removes only unique test assets. */
require '/app/search-api/vendor/autoload.php';

use App\Service\V2\Index\FieldVectors;
use App\Service\V2\Search\ElasticSearchService;
use App\Service\V2\Search\SearchInput;
use App\Service\V2\Search\QueryBuilder\QueryBuilder;
use App\Service\V2\Search\QueryBuilder\ProductVectorQuery;
use App\Service\{Database, Elastic, Embeddings, Mapping, DocumentText, Products, Search};
use App\Command\IndexCommand;
use App\Command\V2\IndexCommand as IndexV2Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\HttpClient;

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$suffix = bin2hex(random_bytes(6));
$index = 'v2-smoke-'.$suffix; $schema = 'v2_smoke_'.$suffix;
putenv('ELASTICSEARCH_INDEX='.$index);
putenv('ELASTICSEARCH_ALIAS='.$index.'-alias');
$db = Database::connect(); $es = new Elastic(HttpClient::create()); $embedding = new Embeddings(HttpClient::create());
$fields = new FieldVectors($embedding); $products = new Products($db); $search = new Search($es, $embedding);
$v2Service = new ElasticSearchService($es, $embedding, new QueryBuilder(new ProductVectorQuery()));
$searchV2 = static function (array $input) use ($v2Service): array {
    $filters = $input['filters'] ?? [];
    unset($input['filters']);
    return $v2Service->search(SearchInput::fromArray($input + $filters));
};
$run = static function ($command, array $args = []): string {
    $tester = new CommandTester($command);
    check($tester->execute($args) === 0, $tester->getDisplay());
    return $tester->getDisplay();
};
try {
    $db->exec('CREATE SCHEMA '.$schema);
    $db->exec('SET search_path TO '.$schema);
    $db->exec(file_get_contents('/app/infrastructure/schema.sql'));
    $es->request('PUT', $index, (new Mapping())->load('/app/infrastructure/elasticsearch/products.mapping.json', $embedding->version()));
    $book = ['id' => 'book', 'brand' => ['id' => 1, 'name' => 'Kronik Kitap'],
        'category' => ['id' => 11, 'name' => 'Roman/Öykü', 'tree' => [['id' => 10, 'name' => 'Kitap'], ['id' => 11, 'name' => 'Roman/Öykü']]],
        'variants' => [['id' => 1, 'name' => 'Telefon Melefon Yok!', 'merchants' => []]], 'extra' => new stdClass(), 'emptyList' => []];
    $phone = ['id' => 'phone', 'brand' => ['id' => 2, 'name' => 'Tecno'],
        'category' => ['id' => 22, 'name' => 'Android Telefonlar', 'tree' => [['id' => 20, 'name' => 'Teknoloji'], ['id' => 21, 'name' => 'Cep Telefonu'], ['id' => 22, 'name' => 'Android Telefonlar']]],
        'variants' => [['id' => 2, 'name' => 'Tecno Pova Neo Le6 4Gb/64Gb Obsidyen Siyah Cep Telefonu', 'merchants' => []]]];
    foreach ([$book, $phone] as $doc) $products->saveJson(json_encode($doc, JSON_THROW_ON_ERROR), $doc['id']);
    $indexer = new IndexCommand($db, $es, $embedding, new DocumentText());
    $run($indexer);
    $before = $es->request('GET', $index.'/_doc/book');
    $v1Before = $search->find(['query' => 'telefon', 'limit' => 2]);
    $backfill = new IndexV2Command($db, $es, $embedding, $fields);
    echo $run($backfill, ['--batch-size' => '1']);
    $after = $es->request('GET', $index.'/_doc/book');
    check($before['_version'] === $after['_version'], 'External revision changed');
    $source = $after['_source']; unset($source['semantic']['v2']);
    check($source === $before['_source'], 'Source or v1 embedding changed');
    check(str_contains($run($backfill), 'değişmeyen: 2'), 'Backfill is not idempotent');
    $v2 = $searchV2(['query' => 'telefon', 'limit' => 2]);
    check($v2['count'] === 2, 'Missing v2 results');
    check(!isset($v2['items'][0]['document']->semantic), 'Vectors leaked');
    echo 'V2 real-model results: '.json_encode(array_map(fn($x) => [$x['id'], $x['score']], $v2['items']))."\n";
    check(json_encode($v1Before) === json_encode($search->find(['query' => 'telefon', 'limit' => 2])), 'V1 ranking changed');
    $filtered = $searchV2(['query' => 'telefon', 'filters' => ['brand_id' => 2, 'category_ids' => [21]]]);
    check($filtered['count'] === 1 && $filtered['items'][0]['id'] === 'phone', 'Filter mismatch');
    $bookHit = array_values(array_filter($v2['items'], fn($x) => $x['id'] === 'book'))[0];
    check($bookHit['document']->extra instanceof stdClass && $bookHit['document']->emptyList === [], 'JSON shape changed');
    $oldHash = $after['_source']['semantic']['v2']['hash'];
    $book['variants'][0]['name'] = 'Telefon Melefon Yok! Yeni Baskı';
    $products->saveJson(json_encode($book), 'book');
    echo $run($indexer);
    $updated = $es->request('GET', $index.'/_doc/book');
    check($updated['_version'] === 2, 'Normal indexing revision failed after backfill');
    check($updated['_source']['semantic']['v2']['hash'] !== $oldHash, 'Normal indexing left stale v2 vectors');
    // Absent category/brand must not crash Painless or create empty-text vectors.
    $minimal = ['id' => 'minimal', 'variants' => [['id' => 3, 'name' => 'Telefon', 'merchants' => []]]];
    $products->saveJson(json_encode($minimal), 'minimal'); $run($indexer);
    $missing = $es->request('GET', $index.'/_doc/minimal')['_source']['semantic']['v2'];
    check(!isset($missing['category'], $missing['brand']), 'Missing fields embedded');
    check($searchV2(['query' => 'telefon'])['count'] === 3, 'Missing-field search failed');
    $products->delete('book'); $run($indexer);
    check($searchV2(['query' => 'telefon'])['count'] === 2, 'Deletion not reflected');
    echo "PASS: real ES/model, v1 preservation, v2 scoring, filters, idempotency, source shape, missing fields, update and delete.\n";
} finally {
    $es->request('DELETE', $index, null, [404]);
    $db->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
}