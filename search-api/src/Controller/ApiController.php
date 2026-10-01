<?php
namespace App\Controller;

use App\Service\{Products, Search, Elastic, Embeddings};
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class ApiController
{
    public function __construct(private Products $products, private Search $search, private Elastic $elastic, private Embeddings $embeddings, private \PDO $db) {}

    private function run(callable $action): JsonResponse
    {
        try { return $action(); }
        catch (\InvalidArgumentException | \JsonException $e) { return new JsonResponse(['error' => $e->getMessage()], 400); }
        catch (\Throwable $e) { error_log($e->getMessage()); return new JsonResponse(['error' => 'İşlem tamamlanamadı; servisleri kontrol edip tekrar deneyin.'], 503); }
    }

    private function body(Request $request): array
    {
        if (strlen($request->getContent()) > 2 * 1024 * 1024) throw new \InvalidArgumentException('İstek en fazla 2 MiB olabilir.');
        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) throw new \InvalidArgumentException('JSON nesnesi bekleniyor.');
        return $body;
    }

    #[Route('/api/documents/{id}', methods: ['PUT'])]
    public function put(string $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $this->body($request);
            $this->products->saveJson($request->getContent(), $id);
            return new JsonResponse(['id' => $id, 'stored_in' => 'postgresql', 'indexing' => 'Run app:products:index'], 200);
        });
    }

    #[Route('/api/documents/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        return $this->run(fn() => ($row = $this->products->get($id)) ? new JsonResponse($row) : new JsonResponse(['error' => 'Bulunamadı.'], 404));
    }

    #[Route('/api/documents/{id}', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id) { $this->products->delete($id); return new JsonResponse(['id' => $id, 'indexing' => 'Run app:products:index']); });
    }

    #[Route('/api/search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $input = $request->query->all();
            if (isset($input['limit'])) {
                $limit = filter_var($input['limit'], FILTER_VALIDATE_INT);
                if ($limit === false) throw new \InvalidArgumentException('limit tam sayı olmalı (1–50).');
                $input['limit'] = $limit;
            }
            return new JsonResponse($this->search->find($input));
        });
    }

    #[Route('/health/ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        try {
            $this->db->query('SELECT 1 FROM products LIMIT 1');
            $health = $this->elastic->request('GET', '_cluster/health');
            if (($health['status'] ?? 'red') === 'red') throw new \RuntimeException('ES red');
            if (!$this->embeddings->ready()) throw new \RuntimeException('Model ready değil.');
            return new JsonResponse(['status' => 'ready']);
        } catch (\Throwable) { return new JsonResponse(['status' => 'not_ready'], 503); }
    }
}