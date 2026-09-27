<?php
use App\Service\SearchDocument;
use PHPUnit\Framework\TestCase;

final class SearchDocumentTest extends TestCase
{
    public function testSearchProjectionKeepsSourceAndOtherNestedFieldsIntact(): void
    {
        $json = '{"id":"1","author":{"name":"Author"},"extra":{},"variants":[{"id":"v1","name":"Product","specificList":[{"id":1}],"productKeywords":[{"keyword":"phone"}],"plistFeatured":[{"plistId":2}],"merchantFeatured":[{"merchantId":3}],"merchants":[{"id":"m1","price":42}]},{"id":"v2","productKeywords":["text"]}]}';
        $source = json_decode($json);
        $projected = SearchDocument::fromJson($json, ['hash' => 'test']);
        self::assertObjectNotHasProperty('author', $projected);
        foreach ($projected->variants as $variant) {
            foreach (['specificList', 'productKeywords', 'plistFeatured', 'merchantFeatured'] as $field) {
                self::assertObjectNotHasProperty($field, $variant);
            }
        }
        self::assertEquals($source->extra, $projected->extra);
        self::assertEquals($source->variants[0]->merchants, $projected->variants[0]->merchants);
        self::assertSame('Product', $projected->variants[0]->name);
        self::assertSame(['hash' => 'test'], $projected->semantic);
        self::assertEquals($source, json_decode($json));
        self::assertObjectHasProperty('author', $source);
    }
}
