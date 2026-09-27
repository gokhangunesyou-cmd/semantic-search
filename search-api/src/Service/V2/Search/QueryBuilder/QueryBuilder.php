<?php

namespace App\Service\V2\Search\QueryBuilder;

use App\Service\V2\Search\QueryBuilder\Filter\SearchFilterInterface;

/** Each fluent step returns a copy so shared services cannot leak request state. */
final class QueryBuilder
{
    private array $filters = [];
    private array $vector = [];
    private int $size = 10;
    private array|bool $source = ['excludes' => ['semantic']];

    public function __construct(private ProductVectorQuery $vectorQuery)
    {
    }

    public function applyFilter(SearchFilterInterface $filter): self
    {
        $builder = clone $this;
        $builder->filters = array_merge($builder->filters, $filter->createFilters());
        return $builder;
    }

    public function vector(array $vector): self
    {
        $builder = clone $this;
        $builder->vector = $vector;
        return $builder;
    }

    public function size(int $size): self
    {
        $builder = clone $this;
        $builder->size = $size;
        return $builder;
    }

    public function source(array|bool $source): self
    {
        $builder = clone $this;
        $builder->source = $source;
        return $builder;
    }

    public function build(): array
    {
        return [
            'size' => $this->size,
            '_source' => $this->source,
            'query' => $this->vectorQuery->scoring($this->vector, $this->filters)
        ];
    }
}