<?php
namespace App\Tests;

use App\Service\V2\Index\FieldVectors;
use App\Service\{Embeddings};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\{MockHttpClient, Response\MockResponse};

final class FieldVectorsTest extends TestCase
{
    public function testIndependentTextsMissingFieldsAndIdempotency(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            $body = json_decode($options['body'], true);
            self::assertSame('document', $body['kind']);
            self::assertSame(['Cep Telefonu', 'Teknoloji > Telefon'], $body['texts']);
            return new MockResponse(json_encode(['model_version' => 'e5-small-onnx-fp32-v1', 'dimensions' => 384,
                'items' => array_fill(0, 2, ['embedding' => [1, ...array_fill(0, 383, 0)]])]));
        }, 'http://localhost');
        $fields = new FieldVectors(new Embeddings($http));
        $document = ['variants' => [['name' => '  Cep <b>Telefonu</b> '], ['name' => 'Cep Telefonu']],
            'category' => ['name' => 'Telefon', 'tree' => [['name' => 'Teknoloji'], ['name' => 'Telefon']]]];
        $result = $fields->build($document);
        self::assertArrayNotHasKey('brand', $result);
        self::assertSame($result, $fields->build($document, $result));
        self::assertSame(1, $http->getRequestsCount());
        $fields->build($document, $result, true);
        self::assertSame(2, $http->getRequestsCount());
    }
}