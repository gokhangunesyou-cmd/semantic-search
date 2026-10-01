<?php

namespace App\Service\V2\Search\QueryBuilder;

/** Each fluent step returns a copy so shared services cannot leak request state. */
final class QueryBuilder
{
    private array $vector = [];
    private array $weights = ProductVectorQuery::DEFAULT_WEIGHTS;
    private int $size = 10;
    private int $page = 1;
    private array|bool $source = true;

    public function __construct(private ProductVectorQuery $vectorQuery)
    {
    }

    public function weights(array $weights): self
    {
        $builder = clone $this;
        $builder->weights = $weights;
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

    public function page(int $page): self
    {
        $builder = clone $this;
        $builder->page = $page;
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
            'from' => ($this->page - 1) * $this->size,
            'size' => $this->size,
            'track_total_hits' => true,
            '_source' => $this->source,
            'query' => $this->vectorQuery->scoring($this->vector, $this->weights)
        ];
    }
}