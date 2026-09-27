<?php

namespace App\Service\V2\Search\QueryBuilder\Filter;

final class ProductBrandFilter implements SearchFilterInterface
{
    public function __construct(private string|int|null $brandId)
    {
    }

    public function createFilters(): array
    {
        return $this->brandId === null ? [] : [['term' => ['brand.id' => $this->brandId]]];
    }
}