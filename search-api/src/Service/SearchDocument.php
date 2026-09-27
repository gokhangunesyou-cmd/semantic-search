<?php
namespace App\Service;

final class SearchDocument
{
    public static function fromJson(string $json, array $semantic): \stdClass
    {
        // Work on a separate decoded copy; PostgreSQL retains the full document.
        $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        unset($document->author);
        foreach ($document->variants as $variant) {
            unset($variant->specificList, $variant->productKeywords, $variant->plistFeatured, $variant->merchantFeatured);
        }
        $document->semantic = $semantic;
        return $document;
    }
}
