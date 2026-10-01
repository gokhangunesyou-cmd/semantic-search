<?php

namespace App\Service\V2\Search\QueryBuilder;

final class ProductVectorQuery
{
    public const DEFAULT_WEIGHTS = ['semantic' => 1.0, 'name' => 0.4, 'category' => 0.5, 'brand' => 0.1];

    public function scoring(array $vector, array $weights): array
    {
        $script = "double score = params.semantic * "
            . "(cosineSimilarity(params.vector, 'semantic.vector') + 1.0) / 2.0;";
        foreach (['name', 'category', 'brand'] as $field) {
            $path = 'semantic.v2.' . $field . '.vector';
            $script .= " if (doc.containsKey('$path') && doc['$path'].size() != 0) {"
                . " score += params.$field * (cosineSimilarity(params.vector, '$path') + 1.0) / 2.0; }";
        }
        $script .= ' return Math.max(0.0, score);';
        return ['script_score' => [
            'query' => ['bool' => ['filter' => [['exists' => ['field' => 'semantic.vector']]]]],
            'script' => [
                'source' => $script,
                'params' => ['vector' => $vector] + $weights
            ]
        ]];
    }
}