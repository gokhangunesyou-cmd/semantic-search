<?php

namespace App\Service\V2\Search;

final readonly class SearchInput
{
    public function __construct(
        public string $query,
        public int $limit,
        public string|int|null $brandId,
        public array $categoryIds
    )
    {
    }

    public static function fromArray(array $input): self
    {
        $query = $input['query'] ?? null;
        $limit = $input['limit'] ?? 10;
        $mode = $input['mode'] ?? 'semantic';
        if (!is_string($query) || trim($query) === '' || strlen($query) > 2000) {
            throw new \InvalidArgumentException('query boş olamaz ve en fazla 2000 bayt olabilir.');
        }
        if ((!is_int($limit) && !is_string($limit)) || filter_var($limit, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('limit tam sayı olmalı (1–50).');
        }
        $limit = (int)$limit;
        if ($limit < 1 || $limit > 50 || $mode !== 'semantic') {
            throw new \InvalidArgumentException('limit 1–50 olmalı; v2 yalnızca semantic modunu destekler.');
        }
        $brandId = $input['brand_id'] ?? null;
        if ($brandId !== null && !is_string($brandId) && !is_int($brandId)) {
            throw new \InvalidArgumentException('brand_id geçersiz.');
        }
        $categoryIds = $input['category_ids'] ?? [];
        if (is_string($categoryIds)) {
            $categoryIds = explode(',', $categoryIds);
        }
        if (!is_array($categoryIds) || count($categoryIds) > 50 ||
            (isset($input['category_ids']) && !$categoryIds)) {
            throw new \InvalidArgumentException('category_ids 1–50 kategori içermeli.');
        }
        foreach ($categoryIds as $id) {
            if ((!is_string($id) && !is_int($id)) || (string)$id === '') {
                throw new \InvalidArgumentException('Kategori ID geçersiz.');
            }
        }
        return new self($query, $limit, $brandId, array_values($categoryIds));
    }
}