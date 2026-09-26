<?php
use App\Service\{DocumentText, Mapping};
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    private function doc(): array { return json_decode(file_get_contents(__DIR__.'/fixtures/product.json'), true)['_source']; }

    public function testPriceStockAndCampaignDoNotChangeEmbeddingText(): void
    {
        $builder = new DocumentText();
        $doc = $this->doc(); $before = $builder->build($doc);
        $doc['variants'][0]['merchants'][0]['price'] = 9999;
        $doc['variants'][0]['totalStock'] = 1;
        $doc['variants'][0]['badges'] = [];
        self::assertSame($before, $builder->build($doc));
        $doc['variants'][0]['attributes'][0]['valueText'] = 'Kırmızı';
        self::assertNotSame($before, $builder->build($doc));
    }

    public function testVariantOrderDoesNotChangeEmbeddingText(): void
    {
        $doc = $this->doc();
        $variant = $doc['variants'][0]; $variant['name'] .= ' Siyah';
        $doc['variants'][] = $variant;
        $builder = new DocumentText(); $before = $builder->build($doc);
        $doc['variants'] = array_reverse($doc['variants']);
        self::assertSame($before, $builder->build($doc));
    }

    public function testMappingPreservesRootAndNestedHierarchy(): void
    {
        $file = dirname(__DIR__, 2).'/infrastructure/elasticsearch/products.mapping.json';
        $original = json_decode(file_get_contents($file), true);
        $body = (new Mapping())->load($file, 'test-model');
        foreach ($original['mappings']['properties'] as $key => $definition) self::assertSame($definition, $body['mappings']['properties'][$key]);
        foreach (['variants', 'variants.merchants', 'variants.merchants.regions', 'variants.merchants.merchantBadges', 'variants.attributes', 'variants.images', 'variants.listings', 'category.tree', 'breadcrumb'] as $path) {
            $field = $body['mappings'];
            foreach (explode('.', $path) as $part) $field = $field['properties'][$part];
            self::assertSame('nested', $field['type'], $path);
        }
        self::assertArrayNotHasKey('payload', $body['mappings']['properties']);
        self::assertSame(384, $body['mappings']['properties']['semantic']['properties']['vector']['dims']);
    }

    public function testOriginalMappingExportCanBeExtendedWithoutChangingItsFields(): void
    {
        $source = ['variants' => ['type' => 'nested', 'properties' => ['merchants' => ['type' => 'nested']]], 'custom' => ['type' => 'keyword']];
        $file = tempnam(sys_get_temp_dir(), 'mapping');
        file_put_contents($file, json_encode(['original_index' => ['mappings' => ['properties' => $source]]]));
        try {
            $body = (new Mapping())->load($file, 'test-model');
            foreach ($source as $key => $value) self::assertSame($value, $body['mappings']['properties'][$key]);
        } finally { unlink($file); }
    }
}
