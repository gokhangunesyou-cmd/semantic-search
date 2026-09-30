<?php
namespace App\Service;

final class Mapping
{
    public static function semantic(int $dimensions = 384): array
    {
        return ['properties' => [
            'text' => ['type' => 'text', 'analyzer' => 'turkish'],
            'vector' => ['type' => 'dense_vector', 'dims' => $dimensions, 'index' => true, 'similarity' => 'cosine', 'index_options' => ['type' => 'int8_hnsw']],
            'hash' => ['type' => 'keyword'], 'version' => ['type' => 'keyword'],
            'truncated' => ['type' => 'boolean'], 'token_count' => ['type' => 'integer'],
            'identifiers' => ['type' => 'keyword'],
        ]];
    }

    public function load(string $file, string $version): array
    {
        $body = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        // Accept create-index bodies, plain mappings, and GET /index/_mapping exports.
        if (!isset($body['mappings']) && !isset($body['properties'])) {
            if (count($body) !== 1) throw new \InvalidArgumentException('Mapping tek indeks içermeli.');
            $body = reset($body);
        }
        if (isset($body['properties'])) $body = ['mappings' => $body];
        foreach (['variants', 'variants.merchants'] as $path) {
            $field = $body['mappings'];
            foreach (explode('.', $path) as $part) $field = $field['properties'][$part] ?? [];
            if (($field['type'] ?? null) !== 'nested') throw new \InvalidArgumentException($path.' nested olmalı.');
        }
        if (isset($body['mappings']['properties']['semantic'])) throw new \InvalidArgumentException('Kaynak mapping semantic alanını zaten içeriyor.');
        $body['mappings']['properties']['semantic'] = self::semantic();
        $body['mappings']['_meta']['embedding_version'] = $version;
        $body['mappings']['_meta']['text_version'] = DocumentText::VERSION;
        $body['settings']['number_of_shards'] = 1;
        $body['settings']['number_of_replicas'] = 0;
        return $body;
    }
}
