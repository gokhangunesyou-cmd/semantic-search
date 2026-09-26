<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Elastic
{
    public function __construct(private HttpClientInterface $http) {}

    public function index(): string
    {
        return getenv('ELASTICSEARCH_INDEX') ?: 'products_v1';
    }

    public function alias(): string
    {
        return getenv('ELASTICSEARCH_ALIAS') ?: 'products_current';
    }

    public function activate(bool $replace): void
    {
        $alias = $this->alias();
        $current = $this->request('GET', '_alias/'.rawurlencode($alias), null, [404]);
        if (isset($current['error'])) $current = [];
        if (isset($current[$this->index()]) && count($current) === 1) return;
        if ($current && !$replace) return;
        $actions = [];
        foreach (array_keys($current) as $old) $actions[] = ['remove' => ['index' => $old, 'alias' => $alias]];
        $actions[] = ['add' => ['index' => $this->index(), 'alias' => $alias]];
        $this->request('POST', '_aliases', ['actions' => $actions]);
    }

    public function request(string $method, string $path, ?array $body = null, array $allowed = []): array
    {
        $options = ['timeout' => 60, 'max_duration' => 120];
        if ($body !== null) $options['json'] = $body;
        if ($password = getenv('ELASTICSEARCH_PASSWORD')) $options['auth_basic'] = ['elastic', $password];
        $response = $this->http->request($method, rtrim(getenv('ELASTICSEARCH_URL'), '/').'/'.ltrim($path, '/'), $options);
        $status = $response->getStatusCode();
        $text = $response->getContent(false);
        $data = $text === '' ? [] : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (isset($data['hits']['hits'])) {
            $native = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
            foreach ($native->hits->hits as $i => $hit) {
                if (isset($hit->_source)) $data['hits']['hits'][$i]['_source'] = $hit->_source;
            }
        }
        if ($status >= 300 && !in_array($status, $allowed, true)) {
            throw new \RuntimeException('Elasticsearch HTTP '.$status.': '.substr($text, 0, 1500));
        }
        return $data;
    }

    public function bulk(string $ndjson): array
    {
        $options = ['body' => $ndjson, 'headers' => ['Content-Type' => 'application/x-ndjson'], 'timeout' => 60, 'max_duration' => 120];
        if ($password = getenv('ELASTICSEARCH_PASSWORD')) $options['auth_basic'] = ['elastic', $password];
        return $this->http->request('POST', rtrim(getenv('ELASTICSEARCH_URL'), '/').'/_bulk', $options)->toArray();
    }
}
