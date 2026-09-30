<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Embeddings
{
    private ?array $readyResponse = null;
    private ?int $dimensionCache = null;

    public function __construct(private HttpClientInterface $http) {}

    public function version(): string
    {
        $model = getenv('EMBEDDING_MODEL') ?: 'intfloat/multilingual-e5-small';
        $revision = match ($model) {
            'intfloat/multilingual-e5-small' => '614241f622f53c4eeff9890bdc4f31cfecc418b3',
            'Trendyol/TY-ecomm-embed-multilingual-base-v1.2.0' => '00c030c9a56bff9403f95c1b45f4b82e669e243c',
            default => getenv('EMBEDDING_REVISION') ?: throw new \RuntimeException(
                'Özel EMBEDDING_MODEL için EMBEDDING_REVISION sabitlenmeli.'
            )
        };
        $modelFile = getenv('MODEL_FILE') ?: 'onnx/model.onnx';
        if ($model === 'intfloat/multilingual-e5-small' &&
            $revision === '614241f622f53c4eeff9890bdc4f31cfecc418b3' && $modelFile === 'onnx/model.onnx') {
            return 'e5-small-onnx-fp32-v1';
        }
        return $model.'@'.$revision.($model === 'intfloat/multilingual-e5-small' ? '#'.$modelFile : '');
    }

    public function dimensions(): int
    {
        if ($this->dimensionCache !== null) {
            return $this->dimensionCache;
        }
        $ready = $this->readyResponse();
        if (($ready['model_version'] ?? null) !== $this->version() || !is_int($ready['dimensions'] ?? null)) {
            throw new \RuntimeException('Embedding modeli hazır değil veya sürümü uyuşmuyor.');
        }
        return $this->dimensionCache = $ready['dimensions'];
    }

    public function ready(): bool
    {
        $result = $this->readyResponse();
        return ($result['model_version'] ?? '') === $this->version() && is_int($result['dimensions'] ?? null);
    }

    public function encode(string $kind, array $texts): array
    {
        $result = $this->http->request('POST', rtrim(getenv('EMBEDDING_URL'), '/').'/v1/embeddings', [
            'json' => ['kind' => $kind, 'texts' => $texts], 'timeout' => 120, 'max_duration' => 180,
        ])->toArray();
        $dimensions = $result['dimensions'] ?? null;
        if (!is_int($dimensions) || $dimensions < 1 ||
            ($this->dimensionCache !== null && $this->dimensionCache !== $dimensions)) {
            throw new \RuntimeException('Embedding boyutu geçersiz veya değişti.');
        }
        $this->dimensionCache = $dimensions;
        if (($result['model_version'] ?? null) !== $this->version() ||
            ($result['dimensions'] ?? null) !== $dimensions || count($result['items'] ?? []) !== count($texts)) {
            throw new \RuntimeException('Embedding sürümü veya boyutu uyuşmuyor.');
        }
        foreach ($result['items'] as $item) {
            $vector = $item['embedding'] ?? [];
            if (count($vector) !== $dimensions) throw new \RuntimeException('Geçersiz vektör boyutu.');
            $norm = 0.0;
            foreach ($vector as $value) {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                    throw new \RuntimeException('Geçersiz vektör değeri.');
                }
                $norm += $value * $value;
            }
            if (abs($norm - 1.0) > 0.01) throw new \RuntimeException('Vektör normalize değil.');
        }
        return $result['items'];
    }

    private function readyResponse(): array
    {
        if ($this->readyResponse === null) {
            $this->readyResponse = $this->http->request(
                'GET', rtrim(getenv('EMBEDDING_URL'), '/').'/health/ready',
                ['timeout' => 10, 'max_duration' => 15]
            )->toArray();
            if (is_int($this->readyResponse['dimensions'] ?? null)) {
                $this->dimensionCache = $this->readyResponse['dimensions'];
            }
        }
        return $this->readyResponse;
    }
}
