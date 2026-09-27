<?php

namespace App\Tests;

use App\Service\V2\Search\QueryBuilder\Filter\ProductBrandFilter;
use App\Service\V2\Search\QueryBuilder\ProductVectorQuery;
use App\Service\V2\Search\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderV2Test extends TestCase
{
    public function testBranchesAndRequestsCannotLeakFiltersOrSourceSettings(): void
    {
        $prototype = new QueryBuilder(new ProductVectorQuery());
        $filtered = $prototype->vector([1])->applyFilter(new ProductBrandFilter('7'));
        $first = $filtered->size(2)->source(false)->build();
        $second = $filtered->size(5)->build();
        self::assertFalse($first['_source']);
        self::assertTrue($second['_source']);
        self::assertSame(2, $first['size']);
        self::assertSame(5, $second['size']);
        self::assertCount(2, $second['query']['script_score']['query']['bool']['filter']);
        $nextRequest = $prototype->vector([0, 1])->build();
        self::assertSame(
            [['exists' => ['field' => 'semantic.vector']]],
            $nextRequest['query']['script_score']['query']['bool']['filter']
        );
        self::assertArrayNotHasKey('knn', $nextRequest);
        self::assertSame([0, 1], $nextRequest['query']['script_score']['script']['params']['vector']);
    }
}