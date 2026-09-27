<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DocsController
{
    #[Route('/docs', methods: ['GET'])]
    public function docs(): Response
    {
        return new Response(<<<'HTML'
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Semantic Search PoC — Swagger</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.11.0/swagger-ui.css">
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.11.0/swagger-ui-bundle.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.11.0/swagger-ui-standalone-preset.js"></script>
  <script>
    SwaggerUIBundle({
      urls: [
        {url: '/openapi.json', name: 'Arama ve ürün API'},
        {url: '/api/embedding/openapi.json', name: 'Embedding API'}
      ],
      dom_id: '#swagger-ui',
      presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
      layout: 'StandaloneLayout',
      deepLinking: true,
      tryItOutEnabled: true,
      validatorUrl: null
    });
  </script>
</body>
</html>
HTML);
    }

    #[Route('/api/embedding/openapi.json', methods: ['GET'])]
    public function embeddingSchema(HttpClientInterface $http): JsonResponse
    {
        try {
            $schema = $http->request('GET', $this->embeddingUrl().'/openapi.json', ['timeout' => 5, 'max_duration' => 10])->toArray();
            $schema['servers'] = [['url' => '/api/embedding']];
            return new JsonResponse($schema);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            return new JsonResponse(['error' => 'Embedding şeması alınamadı.'], 503);
        }
    }

    #[Route('/api/embedding/health/{check}', requirements: ['check' => 'live|ready'], methods: ['GET'])]
    #[Route('/api/embedding/v1/embeddings', methods: ['GET'])]
    public function embedding(Request $request, HttpClientInterface $http): Response
    {
        if (strlen($request->getContent()) > 2 * 1024 * 1024) {
            return new JsonResponse(['error' => 'İstek en fazla 2 MiB olabilir.'], 413);
        }
        try {
            $path = substr($request->getPathInfo(), strlen('/api/embedding'));
            $upstream = $http->request($request->getMethod(), $this->embeddingUrl().$path.($request->server->get('QUERY_STRING', '') !== '' ? '?'.$request->server->get('QUERY_STRING') : ''), [
                'timeout' => 120, 'max_duration' => 180,
            ]);
            $headers = ['Content-Type' => 'application/json'];
            $retry = $upstream->getHeaders(false)['retry-after'][0] ?? null;
            if ($retry !== null) $headers['Retry-After'] = $retry;
            return new Response($upstream->getContent(false), $upstream->getStatusCode(), $headers);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            return new JsonResponse(['error' => 'Embedding servisine erişilemedi.'], 503);
        }
    }

    private function embeddingUrl(): string
    {
        return rtrim(getenv('EMBEDDING_URL') ?: 'http://embedding:8000', '/');
    }
}
