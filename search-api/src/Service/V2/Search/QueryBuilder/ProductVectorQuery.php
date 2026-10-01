<?php

namespace App\Service\V2\Search\QueryBuilder;

final class ProductVectorQuery
{
    public const DEFAULT_WEIGHTS = ['semantic' => 1.0, 'name' => 0.4, 'category' => 0.5, 'brand' => 0.1];
    public const DEFAULT_DOCUMENT_SCORE_MAX_MULTIPLIER = 1.2;
    public const DOCUMENT_SCORE_SATURATION = 200.0;

    public function scoring(array $vector, array $weights, bool $includeDocumentScore, float $maxMultiplier): array
    {
        $script = "double score = params.semantic * "
            . "(cosineSimilarity(params.vector, 'semantic.vector') + 1.0) / 2.0;";
        foreach (['name', 'category', 'brand'] as $field) {
            $path = 'semantic.v2.' . $field . '.vector';
            $script .= " if (doc.containsKey('$path') && doc['$path'].size() != 0) {"
                . " score += params.$field * (cosineSimilarity(params.vector, '$path') + 1.0) / 2.0; }";
        }
        $params = ['vector' => $vector] + $weights;
        if ($includeDocumentScore) {
            $script .= " if (doc.containsKey('documentScore') && doc['documentScore'].size() != 0) {"
                . " double documentScore = Math.max(0.0, doc['documentScore'].value);"
                . ' double ratio = Math.min(1.0, Math.log(1.0 + documentScore) / params.documentScoreLogDenominator);'
                . ' score *= 1.0 + (params.documentScoreMaxMultiplier - 1.0) * ratio; }';
            $params['documentScoreLogDenominator'] = log(1.0 + self::DOCUMENT_SCORE_SATURATION);
            $params['documentScoreMaxMultiplier'] = $maxMultiplier;
        }
        $script .= ' return Math.max(0.0, score);';
        return ['script_score' => [
            'query' => ['bool' => ['filter' => [['exists' => ['field' => 'semantic.vector']]]]],
            'script' => [
                'source' => $script,
                'params' => $params
            ]
        ]];
    }
}