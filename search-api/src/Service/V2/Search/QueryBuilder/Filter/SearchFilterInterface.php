<?php

namespace App\Service\V2\Search\QueryBuilder\Filter;

interface SearchFilterInterface
{
    public function createFilters(): array;
}