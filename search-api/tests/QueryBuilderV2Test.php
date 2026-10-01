<?php

namespace App\Tests;

use App\Service\V2\Search\QueryBuilder\ProductVectorQuery;
use App\Service\V2\Search\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderV2Test extends TestCase
{
    public function testBranchesAndRequestsCannotLeakWeightsPageOrSourceSettings(): void
    {
        $prototype = new QueryBuilder(new ProductVectorQuery());
        $weighted = $prototype->vector([1])->weights([
            'semantic' => 1.0, 'name' => 0.4, 'category' => 2.0, 'brand' => 0.1
        ]);
        $first = $weighted->size(2)->page(3)->source(false)->build();
        $second = $weighted->size(5)->build();
        self::assertFalse($first['_source']);
        self::assertTrue($second['_source']);
        self::assertSame(2, $first['size']);
        self::assertSame(4, $first['from']);
        self::assertSame(5, $second['size']);
        self::assertSame(0, $second['from']);
        self::assertTrue($second['track_total_hits']);
        self::assertSame(2.0, $second['query']['script_score']['script']['params']['category']);
        $nextRequest = $prototype->vector([0, 1])->build();
        self::assertSame(
            [['exists' => ['field' => 'semantic.vector']]],
            $nextRequest['query']['script_score']['query']['bool']['filter']
        );
        self::assertArrayNotHasKey('knn', $nextRequest);
        self::assertSame(
            [0, 1], $nextRequest['query']['script_score']['script']['params']['vector']
        );
        self::assertSame(0.5, $nextRequest['query']['script_score']['script']['params']['category']);
        self::assertStringNotContainsString('documentScore', $nextRequest['query']['script_score']['script']['source']);
        $boosted = $prototype->vector([1])->documentScore(true, 1.5)->build();
        self::assertStringContainsString('doc[\'documentScore\']', $boosted['query']['script_score']['script']['source']);
        self::assertSame(
            1.5, $boosted['query']['script_score']['script']['params']['documentScoreMaxMultiplier']
        );
        self::assertEqualsWithDelta(
            log(201.0), $boosted['query']['script_score']['script']['params']['documentScoreLogDenominator'], 0.000001
        );
        self::assertStringNotContainsString(
            'documentScore', $prototype->vector([1])->build()['query']['script_score']['script']['source']
        );
    }
}