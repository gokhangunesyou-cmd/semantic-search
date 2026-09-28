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
    public function testSingleSearchUsesMainVectorAndFieldBoostsWithoutMappingRequests(): void
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
            self::assertTrue($body['_source']);
            $scoring = $body['query']['function_score'];
            $vectorScoring = $scoring['query']['script_score'];
            $filters = $vectorScoring['query']['bool']['filter'];
            self::assertCount(3, $filters);
            self::assertSame(['term' => ['brand.id' => '7']], $filters[0]);
            self::assertSame('category.tree', $filters[1]['bool']['should'][1]['nested']['path']);
            self::assertSame(['exists' => ['field' => 'semantic.vector']], $filters[2]);
            self::assertStringNotContainsString('"ids"', json_encode($body));
            self::assertSame(1.0, $vectorScoring['script']['params']['semantic']);
            self::assertStringContainsString(
                "cosineSimilarity(params.vector, 'semantic.vector')", $vectorScoring['script']['source']
            );
            self::assertSame(0.5, $vectorScoring['script']['params']['category']);
            foreach (array_keys(ProductVectorQuery::WEIGHTS) as $field) {
                self::assertStringContainsString("semantic.v2.$field.vector", $vectorScoring['script']['source']);
            }
            self::assertSame('replace', $scoring['boost_mode']);
            self::assertStringContainsString(
                "return _score * factor;", $scoring['functions'][0]['script_score']['script']['source']
            );
            return new MockResponse(json_encode(['hits' => ['hits' => [[
                '_id' => 'phone', '_score' => 0.9,
                '_source' => ['id' => 'phone', 'empty' => new \stdClass(), 'list' => [],
                    'semantic' => ['text' => 'Telefon', 'vector' => [1, 0], 'v2' => [
                        'name' => ['text' => 'Telefon adı', 'vector' => [0, 1]]
                    ]]]
            ]]]]));
        }, 'http://localhost');
        $controller = $this->controller($http);
        $request = Request::create('/api/v2/search?query=telefon&limit=2&brand_id=7&category_ids=11,12');
        $result = $controller->search($request);
        self::assertSame(200, $result->getStatusCode());
        $body = json_decode($result->getContent());
        self::assertSame('v2', $body->version);
        self::assertSame('phone', $body->items[0]->id);
        self::assertInstanceOf(\stdClass::class, $body->items[0]->document->empty);
        self::assertSame('Telefon', $body->items[0]->document->semantic->text);
        self::assertSame([1, 0], $body->items[0]->document->semantic->vector);
        self::assertSame('Telefon adı', $body->items[0]->document->semantic->v2->name->text);
        self::assertSame([0, 1], $body->items[0]->document->semantic->v2->name->vector);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testInvalidInputDoesNotCallDependencies(): void
    {
        $http = new MockHttpClient();
        $controller = $this->controller($http);
        $queries = [
            '', '?query[]=x', '?query=x&mode=unsupported', '?query=x&limit=abc', '?query=x&limit=0',
            '?query=x&limit=51', '?query=x&brand_id[]=1', '?query=x&category_ids=',
            '?query=x&category_ids[0][]=1'
        ];
        foreach ($queries as $query) {
            $response = $controller->search(Request::create('/api/v2/search' . $query));
            self::assertSame(400, $response->getStatusCode());
        }
        self::assertSame(0, $http->getRequestsCount());
    }

    private function controller(MockHttpClient $http): SearchController
    {
        $service = new ElasticSearchService(
            new Elastic($http), new Embeddings($http), new QueryBuilder(new ProductVectorQuery())
        );
        return new SearchController($service);
    }
}