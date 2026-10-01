<?php
namespace App\Tests;

use App\Command\V2\IndexCommand as IndexV2Command;
use App\Service\V2\Index\FieldVectors;
use App\Service\{DocumentText, Elastic, Embeddings};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\{MockHttpClient, Response\MockResponse};

final class IndexV2CommandTest extends TestCase
{
    public function testPartialBulkFailurePreservesVersionSourceAndReleasesLock(): void
    {
        $stmt = $this->createStub(\PDOStatement::class); $stmt->method('fetchColumn')->willReturn(true);
        $db = $this->createMock(\PDO::class);
        $db->expects(self::exactly(2))->method('query')->willReturnCallback(function ($query) use ($stmt) {
            self::assertMatchesRegularExpression('/^SELECT pg_(try_advisory_lock|advisory_unlock)\(470967\)$/', $query);
            return $stmt;
        });
        $cleared = false;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$cleared) {
            $body = json_decode($options['body'] ?? 'null', true);
            if (str_ends_with($url, '/products_v1')) return new MockResponse(json_encode(['products_v1' => ['mappings' => ['_meta' => [
                'embedding_version' => 'e5-small-onnx-fp32-v1', 'text_version' => DocumentText::VERSION,
            ]]]]));
            if (str_ends_with($url, '/_mapping') || str_ends_with($url, '/_refresh')) return new MockResponse('{}');
            if (str_ends_with($url, '/health/ready')) return new MockResponse(json_encode([
                'model_version' => 'e5-small-onnx-fp32-v1', 'dimensions' => 384
            ]));
            if (str_contains($url, '_search?scroll=')) return new MockResponse(json_encode(['_scroll_id' => 'test-scroll', 'hits' => ['hits' => [
                ['_id' => 'a', '_version' => 7, '_routing' => 'route-a', '_source' => ['id' => 'a', 'variants' => [['name' => 'Telefon']], 'empty' => new \stdClass(), 'list' => []]],
                ['_id' => 'b', '_version' => 9, '_source' => ['id' => 'b', 'variants' => [['name' => 'Kitap']]]],
            ]]]));
            if (str_ends_with($url, '/v1/embeddings')) return new MockResponse(json_encode([
                'model_version' => 'e5-small-onnx-fp32-v1', 'dimensions' => 384,
                'items' => [['embedding' => [1, ...array_fill(0, 383, 0)]]],
            ]));
            if (str_ends_with($url, '/_bulk')) {
                $lines = explode("\n", trim($options['body']));
                $action = json_decode($lines[0], true)['index'];
                self::assertSame(7, $action['version']);
                self::assertSame('external_gte', $action['version_type']);
                self::assertSame('route-a', $action['routing']);
                $doc = json_decode($lines[1]);
                self::assertInstanceOf(\stdClass::class, $doc->empty);
                self::assertSame([], $doc->list);
                self::assertCount(384, $doc->semantic->v2->name->vector);
                return new MockResponse('{"errors":true,"items":[{"index":{"status":200}},{"index":{"status":409,"error":{"type":"version_conflict_engine_exception"}}}]}');
            }
            self::assertStringEndsWith('/_search/scroll', $url);
            if ($method === 'DELETE') { $cleared = true; return new MockResponse('{}'); }
            return new MockResponse('{"_scroll_id":"test-scroll","hits":{"hits":[]}}');
        }, 'http://localhost');
        $es = new Elastic($http); $embedding = new Embeddings($http);
        $tester = new CommandTester(new IndexV2Command($db, $es, $embedding, new FieldVectors($embedding)));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('HATA b', $tester->getDisplay());
        self::assertTrue($cleared);
    }
}