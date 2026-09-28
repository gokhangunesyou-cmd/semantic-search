<?php
namespace App\Tests;

use App\Controller\ApiController;
use App\Service\{DocumentText, Elastic, Embeddings, Products, Search};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class SearchGetTest extends TestCase
{
    public function testBrowserQueryParametersReachSearch(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            if (str_ends_with($url, '/_mapping')) {
                return new MockResponse(json_encode(['products' => ['mappings' => ['_meta' => [
                    'embedding_version' => (new Embeddings(new MockHttpClient()))->version(),
                    'text_version' => DocumentText::VERSION,
                ]]]]));
            }
            $body = json_decode($options['body'], true);
            if (str_ends_with($url, '/v1/embeddings')) {
                self::assertSame(['bebek arabası & puset'], $body['texts']);
                return new MockResponse(json_encode([
                    'model_version' => (new Embeddings(new MockHttpClient()))->version(),
                    'dimensions' => 384, 'items' => [['embedding' => [1.0, ...array_fill(0, 383, 0.0)]]],
                ]));
            }
            self::assertSame(3, $body['size']);
            self::assertSame(['term' => ['brand.id' => '20048005']], $body['knn']['filter']['bool']['filter'][0]);
            self::assertSame(['11', '12'], $body['knn']['filter']['bool']['filter'][1]['bool']['should'][0]['terms']['category.id']);
            return new MockResponse('{"hits":{"hits":[]}}');
        }, 'http://localhost');
        $controller = $this->controller($http);
        $result = $controller->search(Request::create('/api/search?query=bebek%20arabası%20%26%20puset&limit=3&brand_id=20048005&category_ids=11,12'));
        self::assertSame(200, $result->getStatusCode());
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testSemanticDataIsReturnedInBothModes(): void
    {
        $semantic = ['text' => 'Telefon', 'vector' => [1, 0], 'v2' => [
            'name' => ['text' => 'Telefon adı', 'vector' => [0, 1]]
        ]];
        $mode = 'semantic';
            $http = new MockHttpClient(function ($method, $url, $options) use ($semantic) {
                if (str_ends_with($url, '/_mapping')) {
                    return new MockResponse(json_encode(['products' => ['mappings' => ['_meta' => [
                        'embedding_version' => (new Embeddings(new MockHttpClient()))->version(),
                        'text_version' => DocumentText::VERSION
                    ]]]]));
                }
                if (str_ends_with($url, '/v1/embeddings')) {
                    return new MockResponse(json_encode([
                        'model_version' => (new Embeddings(new MockHttpClient()))->version(),
                        'dimensions' => 384, 'items' => [['embedding' => [1, ...array_fill(0, 383, 0)]]]
                    ]));
                }
                $body = json_decode($options['body'], true);
                self::assertTrue($body['_source']);
                return new MockResponse(json_encode(['hits' => ['hits' => [[
                    '_id' => 'phone', '_score' => 0.9, '_source' => ['id' => 'phone', 'semantic' => $semantic]
                ]]]]));
            }, 'http://localhost');
            $response = $this->controller($http)->search(Request::create('/api/search?query=telefon&mode=' . $mode));
            self::assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getContent(), true);
            self::assertSame($semantic, $body['items'][0]['document']['semantic']);
            self::assertSame(3, $http->getRequestsCount());
        }
    }

    public function testInvalidBrowserInputReturns400WithoutCallingServices(): void
    {
        $http = new MockHttpClient();
        $controller = $this->controller($http);
        foreach (['', '?query=x&limit=abc', '?query=x&limit=1.5', '?query=x&limit=0', '?query=x&limit=51', '?query[]=x', '?query=x&mode=unsupported', '?query=x&mode=bad'] as $query) {
            self::assertSame(400, $controller->search(Request::create('/api/search'.$query))->getStatusCode());
        }
        self::assertSame(0, $http->getRequestsCount());
    }

    private function controller(MockHttpClient $http): ApiController
    {
        $db = $this->createStub(\PDO::class);
        $elastic = new Elastic($http);
        $embeddings = new Embeddings($http);
        return new ApiController(new Products($db), new Search($elastic, $embeddings), $elastic, $embeddings, $db);
    }
}