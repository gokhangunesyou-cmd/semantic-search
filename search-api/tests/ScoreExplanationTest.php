<?php

namespace App\Tests;

use App\Service\V2\Search\ScoreExplanation;
use PHPUnit\Framework\TestCase;

final class ScoreExplanationTest extends TestCase
{
    public function testDocumentScoreMultiplierIsOptionalLogarithmicAndCapped(): void
    {
        $weights = ['semantic' => 1.0, 'name' => 0.0, 'category' => 0.0, 'brand' => 0.0];
        $source = ['documentScore' => 40, 'semantic' => ['vector' => [1.0, 0.0]]];
        $disabled = ScoreExplanation::fromSource($source, [1.0, 0.0], $weights, 1.0, false, 1.2);
        self::assertSame(1.0, $disabled['document_multiplier']);
        self::assertSame(1.0, $disabled['calculated_total']);

        $enabled = ScoreExplanation::fromSource($source, [1.0, 0.0], $weights, 1.14, true, 1.2);
        self::assertEqualsWithDelta(1.14005, $enabled['document_multiplier'], 0.00001);
        self::assertEqualsWithDelta(1.14005, $enabled['calculated_total'], 0.00001);

        foreach ([200, 248] as $documentScore) {
            $source['documentScore'] = $documentScore;
            $capped = ScoreExplanation::fromSource($source, [1.0, 0.0], $weights, 1.2, true, 1.2);
            self::assertSame(1.2, $capped['document_multiplier']);
        }

        foreach ([0, -5] as $documentScore) {
            $source['documentScore'] = $documentScore;
            $unboosted = ScoreExplanation::fromSource($source, [1.0, 0.0], $weights, 1.0, true, 1.2);
            self::assertSame(1.0, $unboosted['document_multiplier']);
        }
        unset($source['documentScore']);
        $missing = ScoreExplanation::fromSource($source, [1.0, 0.0], $weights, 1.0, true, 1.2);
        self::assertNull($missing['document_score']);
        self::assertSame(1.0, $missing['document_multiplier']);
    }
}