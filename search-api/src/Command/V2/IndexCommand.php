<?php

namespace App\Command\V2;

use App\Service\DocumentText;
use App\Service\Elastic;
use App\Service\Embeddings;
use App\Service\V2\Index\FieldVectors;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:products:index-v2',
    description: 'Ad, kategori ağacı ve marka vektörlerini ES ürünlerine ekler.'
)]
final class IndexCommand extends Command
{
    public function __construct(
        private \PDO $db,
        private Elastic $elastic,
        private Embeddings $embeddings,
        private FieldVectors $fields
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Her sayfada 1–8 ürün', '8');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Değişmeyen vektörleri de yeniden üretir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $size = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT);
        if (!$size || $size < 1 || $size > 8) {
            throw new \InvalidArgumentException('batch-size 1–8 olmalı.');
        }
        // Same lock as the DB indexer: no stale overwrite or resurrection of deleted products.
        if (!$this->db->query('SELECT pg_try_advisory_lock(470967)')->fetchColumn()) {
            $output->writeln('Başka bir indeksleme komutu çalışıyor.');
            return Command::FAILURE;
        }
        $scroll = null;
        try {
            $index = $this->elastic->index();
            $path = rawurlencode($index);
            $info = $this->elastic->request('GET', $path);
            if (count($info) !== 1 || !isset($info[$index])) {
                throw new \RuntimeException('ELASTICSEARCH_INDEX tek bir fiziksel indeks olmalı.');
            }
            $meta = $info[$index]['mappings']['_meta'] ?? [];
            if (($meta['embedding_version'] ?? '') !== $this->embeddings->version() ||
                ($meta['text_version'] ?? '') !== DocumentText::VERSION) {
                throw new \RuntimeException('İndeks/model sürümü uyuşmuyor.');
            }
            $this->elastic->request('PUT', $path . '/_mapping', [
                'properties' => ['semantic' => ['properties' => ['v2' => FieldVectors::mapping()]]]
            ]);
            $page = $this->elastic->request('POST', $path . '/_search?scroll=30m', [
                'size' => $size, 'sort' => ['_doc'], '_source' => true,
                'stored_fields' => ['_routing'], 'version' => true, 'query' => ['match_all' => new \stdClass()]
            ]);
            $written = $skipped = $failed = 0;
            while (true) {
                $scroll = $page['_scroll_id'] ?? $scroll;
                if (($page['timed_out'] ?? false) || ($page['_shards']['failed'] ?? 0) > 0) {
                    throw new \RuntimeException('Eksik Elasticsearch tarama yanıtı.');
                }
                $hits = $page['hits']['hits'];
                if (!$hits) {
                    break;
                }
                $ndjson = '';
                $ids = [];
                foreach ($hits as $hit) {
                    $source = $hit['_source'];
                    $document = json_decode(json_encode($source, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                    $old = $document['semantic']['v2'] ?? null;
                    $v2 = $this->fields->build($document, $old, (bool)$input->getOption('force'));
                    if (!$input->getOption('force') && $old === $v2) {
                        ++$skipped;
                        continue;
                    }
                    if (!isset($source->semantic)) {
                        $source->semantic = new \stdClass();
                    }
                    $source->semantic->v2 = $v2;
                    // Preserve the DB revision stored as ES external version. A partial _update would
                    // increment it and make the next app:products:index revision conflict.
                    $action = [
                        '_index' => $index, '_id' => $hit['_id'],
                        'version' => $hit['_version'], 'version_type' => 'external_gte'
                    ];
                    if (isset($hit['_routing'])) {
                        $action['routing'] = $hit['_routing'];
                    }
                    $ndjson .= json_encode(['index' => $action], JSON_THROW_ON_ERROR) . "\n";
                    $ndjson .= json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                    $ids[] = $hit['_id'];
                }
                if ($ids) {
                    $result = $this->elastic->bulk($ndjson);
                    if (count($result['items'] ?? []) !== count($ids)) {
                        throw new \RuntimeException('Eksik bulk yanıtı.');
                    }
                    foreach ($result['items'] as $i => $entry) {
                        $item = $entry['index'];
                        if ($item['status'] >= 300) {
                            ++$failed;
                            $error = json_encode($item['error'] ?? $item, JSON_UNESCAPED_UNICODE);
                            $output->writeln('HATA ' . $ids[$i] . ': ' . $error);
                        } else {
                            ++$written;
                        }
                    }
                }
                $output->writeln("Yazılan: $written; değişmeyen: $skipped; hatalı: $failed");
                if (!$scroll) {
                    throw new \RuntimeException('Elasticsearch scroll kimliği dönmedi.');
                }
                $page = $this->elastic->request('POST', '_search/scroll', ['scroll' => '30m', 'scroll_id' => $scroll]);
            }
            $this->elastic->request('POST', $path . '/_refresh');
            $output->writeln("V2 tamamlandı. Yazılan: $written; değişmeyen: $skipped; hatalı: $failed.");
            if ($failed) {
                $output->writeln('Hatalar için komutu tekrar çalıştırın.');
            }
            return $failed ? Command::FAILURE : Command::SUCCESS;
        } finally {
            try {
                if ($scroll) {
                    $this->elastic->request('DELETE', '_search/scroll', ['scroll_id' => [$scroll]]);
                }
            } finally {
                $this->db->query('SELECT pg_advisory_unlock(470967)');
            }
        }
    }
}