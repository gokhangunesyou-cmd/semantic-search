<?php

namespace App\Service\V2\Index;

use App\Service\Embeddings;

/** Separate embeddings built from original values, never analyzer token streams. */
final class FieldVectors
{
    public const VERSION = 'fields-tr-v2';
    public const FIELDS = ['name', 'category', 'brand'];

    public function __construct(private Embeddings $embeddings)
    {
    }

    public function texts(array $document): array
    {
        $clean = static fn($value) => trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)));
        $names = array_map($clean, array_column($document['variants'] ?? [], 'name'));
        $names = array_values(array_unique(array_filter($names, static fn($value) => $value !== '')));
        sort($names, SORT_STRING);
        if (!$names) {
            throw new \InvalidArgumentException('V2 için en az bir varyant adı gerekiyor.');
        }
        $categories = array_column($document['category']['tree'] ?? [], 'name');
        $categories[] = $document['category']['name'] ?? '';
        $categories = array_unique(array_filter(array_map($clean, $categories), static fn($value) => $value !== ''));
        return [
            'name' => implode('; ', $names),
            'category' => implode(' > ', $categories),
            'brand' => $clean($document['brand']['name'] ?? '')
        ];
    }

    public function version(): string
    {
        return self::VERSION . ':' . $this->embeddings->version();
    }

    public function build(array $document, array|null $existing = null, bool $force = false): array
    {
        $texts = $this->texts($document);
        $encoded = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $this->version() . "\0" . $encoded);
        if (!$force && ($existing['hash'] ?? '') === $hash && ($existing['version'] ?? '') === $this->version()) {
            return $existing;
        }
        $fields = array_keys(array_filter($texts, static fn($value) => $value !== ''));
        $items = $this->embeddings->encode('document', array_values(array_intersect_key($texts, array_flip($fields))));
        $result = ['hash' => $hash, 'version' => $this->version()];
        foreach ($fields as $i => $field) {
            $result[$field] = [
                'text' => $texts[$field], 'vector' => $items[$i]['embedding'],
                'truncated' => $items[$i]['truncated'] ?? false, 'token_count' => $items[$i]['token_count'] ?? 0
            ];
        }
        return $result;
    }

    public static function mapping(int $dimensions = 384): array
    {
        $properties = ['hash' => ['type' => 'keyword'], 'version' => ['type' => 'keyword']];
        foreach (self::FIELDS as $field) {
            $properties[$field] = ['properties' => [
                'text' => ['type' => 'text', 'index' => false],
                'vector' => [
                    'type' => 'dense_vector', 'dims' => $dimensions, 'index' => true,
                    'similarity' => 'cosine', 'index_options' => ['type' => 'int8_hnsw']
                ],
                'truncated' => ['type' => 'boolean'], 'token_count' => ['type' => 'integer']
            ]];
        }
        return ['properties' => $properties];
    }
}