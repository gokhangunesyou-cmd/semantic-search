<?php

namespace App\Service\V2\Search;

final class ScoreExplanation
{
    public static function fromSource(
        array|\stdClass $source,
        array $queryVector,
        array $weights,
        float $elasticScore
    ): array
    {
        $vectors = [
            'semantic' => self::value($source, ['semantic', 'vector']),
            'name' => self::value($source, ['semantic', 'v2', 'name', 'vector']),
            'category' => self::value($source, ['semantic', 'v2', 'category', 'vector']),
            'brand' => self::value($source, ['semantic', 'v2', 'brand', 'vector'])
        ];
        $components = [];
        $total = 0.0;
        foreach ($weights as $field => $weight) {
            $cosine = self::cosine($queryVector, $vectors[$field]);
            $similarity = $cosine === null ? null : ($cosine + 1.0) / 2.0;
            $contribution = $similarity === null ? 0.0 : $weight * $similarity;
            $components[$field] = [
                'cosine' => $cosine,
                'similarity' => $similarity,
                'weight' => $weight,
                'contribution' => $contribution
            ];
            $total += $contribution;
        }
        return [
            'components' => $components,
            'calculated_total' => max(0.0, $total),
            'elasticsearch_score' => $elasticScore
        ];
    }

    private static function value(array|\stdClass $source, array $path): mixed
    {
        $value = $source;
        foreach ($path as $part) {
            $value = is_array($value) ? ($value[$part] ?? null) : ($value->$part ?? null);
            if ($value === null) {
                return null;
            }
        }
        return $value;
    }

    private static function cosine(array $query, mixed $document): ?float
    {
        if (!is_array($document) || count($document) !== count($query) || !$document) {
            return null;
        }
        $dot = $queryNorm = $documentNorm = 0.0;
        foreach ($query as $i => $value) {
            if (!is_numeric($document[$i] ?? null)) {
                return null;
            }
            $other = (float)$document[$i];
            $dot += $value * $other;
            $queryNorm += $value * $value;
            $documentNorm += $other * $other;
        }
        if ($queryNorm == 0.0 || $documentNorm == 0.0) {
            return null;
        }
        return max(-1.0, min(1.0, $dot / sqrt($queryNorm * $documentNorm)));
    }
}