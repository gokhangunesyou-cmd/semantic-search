<?php

namespace App\Tests;

use App\Controller\V2\SearchController;
use App\Service\Elastic;
use App\Service\Embeddings;
use App\Service\V2\Search\ElasticSearchService;
use App\Service\V2\Search\QueryBuilder\ProductVectorQuery;
use App\Service\V2\Search\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class SearchV2Test extends TestCase
{
    public function testPagedSearchUsesRequestWeightsAndReturnsScoreExplanation(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertStringNotContainsString('_mapping', $url);
            $body = json_decode($options['body'], true);
            if (str_ends_with($url, '/v1/embeddings')) {
                return new MockResponse(json_encode([
                    'model_version' => 'e5-small-onnx-fp32-v1', 'dimensions' => 384,
                    'items' => [['embedding' => [1, ...array_fill(0, 383, 0)]]]
                ]));
            }
            self::assertArrayNotHasKey('knn', $body);
            self::assertSame(2, $body['size']);
            self::assertSame(2, $body['from']);
            self::assertTrue($body['track_total_hits']);
            self::assertTrue($body['_source']);
            $vectorScoring = $body['query']['script_score'];
            $filters = $vectorScoring['query']['bool']['filter'];
            self::assertSame([['exists' => ['field' => 'semantic.vector']]], $filters);
            self::assertStringNotContainsString('"ids"', json_encode($body));
            self::assertSame(1.0, $vectorScoring['script']['params']['semantic']);
            self::assertStringContainsString(
                "cosineSimilarity(params.vector, 'semantic.vector')", $vectorScoring['script']['source']
            );
            self::assertSame(2.0, $vectorScoring['script']['params']['category']);
            foreach (['name', 'category', 'brand'] as $field) {
                self::assertStringContainsString("semantic.v2.$field.vector", $vectorScoring['script']['source']);
            }
            self::assertStringNotContainsString('categoryFactor', $vectorScoring['script']['source']);
            self::assertStringNotContainsString('documentScore', $vectorScoring['script']['source']);
            return new MockResponse(json_encode(['hits' => ['total' => ['value' => 101], 'hits' => [[
                '_id' => 'phone', '_score' => 3.2,
                '_source' => ['id' => 'phone', 'empty' => new \stdClass(), 'list' => [],
                    'semantic' => ['text' => 'Telefon', 'vector' => [1, ...array_fill(0, 383, 0)], 'v2' => [
                        'name' => ['text' => 'Telefon adı', 'vector' => [0, 1, ...array_fill(0, 382, 0)]],
                        'category' => ['vector' => [1, ...array_fill(0, 383, 0)]]
                    ]]]
            ]]]]));
        }, 'http://localhost');
        $controller = $this->controller($http);
        $request = Request::create('/api/v2/search?query=telefon&limit=2&page=2&weight_category=2');
        $result = $controller->search($request);
        self::assertSame(200, $result->getStatusCode());
        $body = json_decode($result->getContent());
        self::assertSame('v2', $body->version);
        self::assertFalse($body->document_score->enabled);
        self::assertSame(2, $body->page);
        self::assertSame(101, $body->total);
        self::assertSame(51, $body->pages);
        self::assertSame('phone', $body->items[0]->id);
        self::assertInstanceOf(\stdClass::class, $body->items[0]->document->empty);
        self::assertSame('Telefon', $body->items[0]->document->semantic->text);
        self::assertCount(384, $body->items[0]->document->semantic->vector);
        self::assertSame('Telefon adı', $body->items[0]->document->semantic->v2->name->text);
        self::assertCount(384, $body->items[0]->document->semantic->v2->name->vector);
        self::assertEqualsWithDelta(3.2, $body->items[0]->explain->calculated_total, 0.000001);
        self::assertEquals(2.0, $body->items[0]->explain->components->category->contribution);
        self::assertEquals(0.0, $body->items[0]->explain->components->brand->contribution);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testInvalidInputDoesNotCallDependencies(): void
    {
        $http = new MockHttpClient();
        $controller = $this->controller($http);
        $queries = [
            '', '?query[]=x', '?query=x&mode=unsupported', '?query=x&limit=abc', '?query=x&limit=0',
            '?query=x&limit=51', '?query=x&page=0', '?query=x&page=201&limit=50',
            '?query=x&weight_name=-1', '?query=x&weight_category=11', '?query=x&weight_brand[]=1',
            '?query=x&include_document_score=true', '?query=x&include_document_score[]=1',
            '?query=x&document_score_max_multiplier=0.9', '?query=x&document_score_max_multiplier=11',
            '?query=x&document_score_max_multiplier=abc', '?query=x&document_score_max_multiplier[]=1.2',
            '?query=x&brand_id=7', '?query=x&category_ids=11'
        ];
        foreach ($queries as $query) {
            $response = $controller->search(Request::create('/api/v2/search' . $query));
            self::assertSame(400, $response->getStatusCode());
        }
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testDocumentScoreBoostUsesRequestedCapAndExplainsResult(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            if (str_ends_with($url, '/v1/embeddings')) {
                return new MockResponse(json_encode([
                    'model_version' => 'e5-small-onnx-fp32-v1', 'dimensions' => 384,
                    'items' => [['embedding' => [1, ...array_fill(0, 383, 0)]]]
                ]));
            }
            $script = json_decode($options['body'], true)['query']['script_score']['script'];
            self::assertStringContainsString("doc['documentScore']", $script['source']);
            self::assertSame(1.5, $script['params']['documentScoreMaxMultiplier']);
            return new MockResponse(json_encode(['hits' => ['total' => ['value' => 1], 'hits' => [[
                '_id' => 'phone', '_score' => 1.350058,
                '_source' => ['documentScore' => 40, 'semantic' => [
                    'vector' => [1, ...array_fill(0, 383, 0)]
                ]]
            ]]]]));
        }, 'http://localhost');
        $response = $this->controller($http)->search(Request::create(
            '/api/v2/search?query=telefon&include_document_score=1&document_score_max_multiplier=1.5'
        ));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent());
        self::assertTrue($body->document_score->enabled);
        self::assertSame(1.5, $body->document_score->max_multiplier);
        self::assertEquals(40.0, $body->items[0]->explain->document_score);
        self::assertEquals(1.0, $body->items[0]->explain->base_total);
        self::assertEqualsWithDelta(1.3501, $body->items[0]->explain->document_multiplier, 0.0001);
        self::assertEqualsWithDelta(1.3501, $body->items[0]->explain->calculated_total, 0.0001);
    }

    private function controller(MockHttpClient $http): SearchController
    {
        $service = new ElasticSearchService(
            new Elastic($http), new Embeddings($http), new QueryBuilder(new ProductVectorQuery())
        );
        return new SearchController($service);
    }
}