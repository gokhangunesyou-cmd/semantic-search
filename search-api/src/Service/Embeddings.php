<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Embeddings
{
    public function __construct(private HttpClientInterface $http) {}

    public function version(): string
    {
        return getenv('EMBEDDING_VERSION') ?: 'e5-small-onnx-fp32-v1';
    }

    public function ready(): bool
    {
        $result = $this->http->request('GET', rtrim(getenv('EMBEDDING_URL'), '/').'/health/ready', ['timeout' => 3, 'max_duration' => 4])->toArray();
        return ($result['model_version'] ?? '') === $this->version();
    }

    public function encode(string $kind, array $texts): array
    {
        $result = $this->http->request('POST', rtrim(getenv('EMBEDDING_URL'), '/').'/v1/embeddings', [
            'json' => ['kind' => $kind, 'texts' => $texts], 'timeout' => 120, 'max_duration' => 180,
        ])->toArray();
        if (($result['model_version'] ?? null) !== $this->version() || ($result['dimensions'] ?? null) !== 384 || count($result['items'] ?? []) !== count($texts)) {
            throw new \RuntimeException('Embedding sürümü veya boyutu uyuşmuyor.');
        }
        foreach ($result['items'] as $item) {
            $vector = $item['embedding'] ?? [];
            if (count($vector) !== 384) throw new \RuntimeException('Geçersiz vektör boyutu.');
            $norm = 0.0;
            foreach ($vector as $value) {
                if (!is_int($value) && !is_float($value) || !is_finite((float) $value)) throw new \RuntimeException('Geçersiz vektör değeri.');
                $norm += $value * $value;
            }
            if (abs($norm - 1.0) > 0.01) throw new \RuntimeException('Vektör normalize değil.');
        }
        return $result['items'];
    }
}
