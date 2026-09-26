<?php
namespace App\Service;

final class Products
{
    public function __construct(private \PDO $db) {}

    public function saveJson(string $json, ?string $expectedId = null): string
    {
        $native = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$native instanceof \stdClass) throw new \InvalidArgumentException('Ürün JSON nesnesi olmalı.');
        $native = $native->_source ?? $native;
        if (!$native instanceof \stdClass) throw new \InvalidArgumentException('_source JSON nesnesi olmalı.');
        $encoded = json_encode($native, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return $this->save(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR), $expectedId, $encoded);
    }

    public function save(array $input, ?string $expectedId = null, ?string $originalJson = null): string
    {
        $doc = $input['_source'] ?? $input;
        if (!is_array($doc) || !isset($doc['id']) || !is_scalar($doc['id'])) {
            throw new \InvalidArgumentException('document.id zorunlu.');
        }
        $id = (string) $doc['id'];
        if ($id === '' || strlen($id) > 256 || ($expectedId !== null && $expectedId !== $id)) {
            throw new \InvalidArgumentException('Doküman kimliği geçersiz veya URL ile farklı.');
        }
        if (array_key_exists('semantic', $doc)) {
            throw new \InvalidArgumentException('semantic uygulamanın ayrılmış alanıdır.');
        }
        if (!isset($doc['variants']) || !is_array($doc['variants']) || !array_is_list($doc['variants'])) {
            throw new \InvalidArgumentException('variants bir dizi olmalıdır.');
        }
        foreach ($doc['variants'] as $variant) {
            if (!is_array($variant) || !isset($variant['id']) || (isset($variant['merchants']) && (!is_array($variant['merchants']) || !array_is_list($variant['merchants'])))) {
                throw new \InvalidArgumentException('Varyant veya merchants yapısı geçersiz.');
            }
        }
        $sql = 'INSERT INTO products(id,document) VALUES (:id,CAST(:doc AS jsonb))
            ON CONFLICT(id) DO UPDATE SET document=EXCLUDED.document, deleted=FALSE,
            revision=products.revision+1, updated_at=NOW()
            WHERE products.document IS DISTINCT FROM EXCLUDED.document OR products.deleted';
        $this->db->prepare($sql)->execute(['id' => $id, 'doc' => $originalJson ?? json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        return $id;
    }

    public function get(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT document, revision FROM products WHERE id=? AND NOT deleted');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? ['document' => json_decode($row['document'], false, 512, JSON_THROW_ON_ERROR), 'revision' => (int) $row['revision']] : null;
    }

    public function delete(string $id): void
    {
        $this->db->prepare('UPDATE products SET deleted=TRUE,revision=revision+1,updated_at=NOW() WHERE id=? AND NOT deleted')->execute([$id]);
    }
}
