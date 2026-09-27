<?php

namespace App\Service\V2\Search\QueryBuilder\Filter;

final class ProductCategoryFilter implements SearchFilterInterface
{
    public function __construct(private array $categoryIds)
    {
    }

    public function createFilters(): array
    {
        if (!$this->categoryIds) {
            return [];
        }
        return [['bool' => [
            'should' => [
                ['terms' => ['category.id' => $this->categoryIds]],
                ['nested' => [
                    'path' => 'category.tree',
                    'query' => ['terms' => ['category.tree.id' => $this->categoryIds]]
                ]]
            ],
            'minimum_should_match' => 1
        ]]];
    }
}