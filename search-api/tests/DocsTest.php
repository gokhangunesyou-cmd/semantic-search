<?php
namespace App\Tests;

use App\Controller\DocsController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DocsTest extends TestCase
{
    public function testEmbeddingSchemaUsesPublicProxyAddress(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'openapi' => '3.1.0', 'servers' => [['url' => 'http://embedding:8000']],
            'paths' => ['/v1/embeddings' => []],
        ])));
        $result = (new DocsController())->embeddingSchema($http);
        $schema = json_decode($result->getContent(), true);
        self::assertSame([['url' => '/api/embedding']], $schema['servers']);
        self::assertArrayHasKey('/v1/embeddings', $schema['paths']);
    }

    public function testProxyPreservesValidationAndCapacityResponses(): void
    {
        foreach ([422, 429] as $status) {
            $http = new MockHttpClient(function ($method, $url, $options) use ($status) {
                self::assertSame('POST', $method);
                self::assertStringEndsWith('/v1/embeddings', $url);
                self::assertSame('{"kind":"query","texts":[]}', $options['body']);
                return new MockResponse('{"detail":"rejected"}', [
                    'http_code' => $status, 'response_headers' => ['Retry-After: 1'],
                ]);
            });
            $request = Request::create('/api/embedding/v1/embeddings', 'POST', content: '{"kind":"query","texts":[]}');
            $result = (new DocsController())->embedding($request, $http);
            self::assertSame($status, $result->getStatusCode());
            self::assertSame('1', $result->headers->get('Retry-After'));
            self::assertSame('{"detail":"rejected"}', $result->getContent());
        }
    }
}
