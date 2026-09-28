<?php

namespace App\Service\V2\Search\QueryBuilder;

final class ProductVectorQuery
{
    public const WEIGHTS = ['name' => 0.4, 'category' => 0.5, 'brand' => 0.1];
    public const BASE_WEIGHT = 1.0;

    public function scoring(array $vector, array $filters): array
    {
        $filters[] = ['exists' => ['field' => 'semantic.vector']];
        $script = "double score = params.semantic * "
            . "(cosineSimilarity(params.vector, 'semantic.vector') + 1.0) / 2.0;";
        foreach (self::WEIGHTS as $field => $weight) {
            $path = 'semantic.v2.' . $field . '.vector';
            $script .= " if (doc.containsKey('$path') && doc['$path'].size() != 0) {"
                . " score += params.$field * (cosineSimilarity(params.vector, '$path') + 1.0) / 2.0; }";
        }
        $script .= ' return Math.max(0.0, score);';
        return ['function_score' => [
            'query' => ['script_score' => [
                'query' => ['bool' => ['filter' => $filters]],
                'script' => [
                    'source' => $script,
                    'params' => ['vector' => $vector, 'semantic' => self::BASE_WEIGHT] + self::WEIGHTS
                ]
            ]],
            'functions' => [[
                'script_score' => [
                    'script' => [
                        'source' => "double factor = 1.0; if (doc.containsKey('category.categoryFactor') "
                            . "&& doc['category.categoryFactor'].size() != 0) { "
                            . "factor = doc['category.categoryFactor'].value; } return _score * factor;"
                    ]
                ]
            ]],
            'boost_mode' => 'replace'
        ]];
    }
}