<?php

namespace App\Service\V2\Search;

use App\Service\V2\Search\QueryBuilder\ProductVectorQuery;

final readonly class SearchInput
{
    public function __construct(
        public string $query,
        public int $limit,
        public int $page,
        public array $weights
    )
    {
    }

    public static function fromArray(array $input): self
    {
        $query = $input['query'] ?? null;
        $limit = self::integer($input['limit'] ?? 10, 'limit');
        $page = self::integer($input['page'] ?? 1, 'page');
        $mode = $input['mode'] ?? 'semantic';
        if (!is_string($query) || trim($query) === '' || strlen($query) > 2000) {
            throw new \InvalidArgumentException('query boş olamaz ve en fazla 2000 bayt olabilir.');
        }
        if ($limit < 1 || $limit > 50 || $page < 1 || $page * $limit > 10000 || $mode !== 'semantic') {
            throw new \InvalidArgumentException('limit 1–50, page en az 1 olmalı; en fazla 10000 sonuç gezilebilir.');
        }
        if (isset($input['brand_id']) || isset($input['category_ids'])) {
            throw new \InvalidArgumentException('brand_id ve category_ids desteklenmiyor.');
        }
        $weights = ProductVectorQuery::DEFAULT_WEIGHTS;
        foreach (array_keys($weights) as $field) {
            $key = 'weight_' . $field;
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value) ||
                !is_finite((float)$value) || (float)$value < 0 || (float)$value > 10) {
                throw new \InvalidArgumentException($key . ' 0–10 arasında sayı olmalı.');
            }
            $weights[$field] = (float)$value;
        }
        return new self($query, $limit, $page, $weights);
    }

    private static function integer(mixed $value, string $name): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException($name . ' tam sayı olmalı.');
        }
        return (int)$value;
    }
}