<?php
namespace App\Service;

final class DocumentText
{
    public const VERSION = 'document-tr-v1';

    public function build(array $doc): string
    {
        $names = $attrs = $keywords = [];
        foreach ($doc['variants'] ?? [] as $v) {
            if (!empty($v['name'])) $names[] = $v['name'];
            foreach ($v['attributes'] ?? [] as $a) {
                if (!empty($a['text']) && !empty($a['valueText'])) $attrs[] = $a['text'].': '.$a['valueText'];
            }
            foreach ($v['highScoreAttributes'] ?? [] as $a) {
                if (!empty($a['attributeName']) && !empty($a['attributeValue'])) $attrs[] = $a['attributeName'].': '.$a['attributeValue'];
            }
            foreach ($v['productKeywords'] ?? [] as $k) {
                if (is_string($k)) $keywords[] = $k;
            }
        }
        $unique = static function (array $items): string {
            $items = array_unique(array_filter(array_map(static fn($s) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $s))), $items)));
            sort($items, SORT_STRING);
            return implode('; ', $items);
        };
        $parts = [
            'Marka: '.($doc['brand']['name'] ?? ''),
            'Kategori: '.implode(' > ', array_column($doc['category']['tree'] ?? [], 'name')).' '.($doc['category']['name'] ?? ''),
            'Ürün: '.$unique($names),
            'Özellikler: '.$unique($attrs),
            'Anahtar kelimeler: '.$unique($keywords),
        ];
        if (!empty($doc['description']) && is_string($doc['description'])) $parts[] = strip_tags($doc['description']);
        if (!$names) throw new \InvalidArgumentException('Embedding için en az bir varyant adı gerekiyor.');
        return implode("\n", $parts);
    }

    public function identifiers(array $doc): array
    {
        $ids = [(string) $doc['id']];
        foreach ($doc['variants'] as $v) foreach (['id', 'barcode'] as $key) {
            if (isset($v[$key])) $ids[] = (string) $v[$key];
        }
        return array_values(array_unique($ids));
    }
}
