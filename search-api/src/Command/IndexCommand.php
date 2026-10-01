<?php
namespace App\Command;

use App\Service\V2\Index\FieldVectors;
use App\Service\{DocumentText, Elastic, Embeddings, SearchDocument};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:products:index', description: 'DB ürünlerini okuyup embedding üretir ve Elasticsearch Bulk API ile yazar. Kuyruk kullanmaz.')]
final class IndexCommand extends Command
{
    public function __construct(private \PDO $db, private Elastic $elastic, private Embeddings $embeddings, private DocumentText $text) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, '1–8 arası', '8');
        $this->addOption(
            'index', null, InputOption::VALUE_REQUIRED, 'İndeks hedefi; verilmezse ELASTICSEARCH_INDEX kullanılır.'
        );
        $this->addOption('activate', null, InputOption::VALUE_NONE, 'Başarılı aktarım sonrası arama aliasını bu indekse geçirir.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($index = $input->getOption('index')) $this->elastic->useIndex($index);
        $batchSize = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT);
        if (!$batchSize || $batchSize > 8 || $batchSize < 1) throw new \InvalidArgumentException('batch-size 1–8 olmalı.');
        // A session-level advisory lock prevents two manual indexers racing.
        if (!$this->db->query('SELECT pg_try_advisory_lock(470967)')->fetchColumn()) {
            $output->writeln('Başka bir indeksleme komutu çalışıyor.'); return Command::FAILURE;
        }
        try {
            $result = $this->runIndex($batchSize, $output);
            if ($result === Command::SUCCESS) $this->elastic->activate((bool) $input->getOption('activate'));
            return $result;
        } finally { $this->db->query('SELECT pg_advisory_unlock(470967)'); }
    }

    private function runIndex(int $batchSize, OutputInterface $output): int
    {
        $index = $this->elastic->index();
        $info = $this->elastic->request('GET', rawurlencode($index));
        if (count($info) !== 1 || !isset($info[$index])) throw new \RuntimeException('ELASTICSEARCH_INDEX tek bir fiziksel indeks olmalı.');
        $uuid = $info[$index]['settings']['index']['uuid'];
        $meta = $info[$index]['mappings']['_meta'] ?? [];
        if (($meta['embedding_version'] ?? '') !== $this->embeddings->version() || ($meta['text_version'] ?? '') !== DocumentText::VERSION) {
            throw new \RuntimeException('İndeks/model sürümü uyuşmuyor; yeni indeks oluşturun.');
        }
        $v2Enabled = isset($info[$index]['mappings']['properties']['semantic']['properties']['v2']);
        $fieldVectors = new FieldVectors($this->embeddings);
        $cursor = ''; $success = $failed = 0;
        while (true) {
            // Lock selected source rows until ES and checkpoint commits finish.
            // A DB update cannot be marked indexed using an older document snapshot.
            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare('SELECT p.*,s.embedding_hash,s.semantic FROM products p
                    LEFT JOIN product_index_state s ON s.product_id=p.id AND s.index_uuid=:uuid
                    WHERE p.id>:cursor AND (s.indexed_revision IS NULL OR s.indexed_revision<>p.revision)
                    ORDER BY p.id LIMIT '.$batchSize.' FOR UPDATE OF p');
                $stmt->execute(['uuid' => $uuid, 'cursor' => $cursor]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if (!$rows) { $this->db->commit(); break; }
                $cursor = end($rows)['id'];
                $pending = $texts = [];
                foreach ($rows as $i => &$row) {
                    $row['deleted'] = in_array($row['deleted'], [true, 1, '1', 't'], true);
                    if ($row['deleted']) continue;
                    $row['doc'] = json_decode($row['document'], true, 512, JSON_THROW_ON_ERROR);
                    $row['text'] = $this->text->build($row['doc']);
                    $row['hash'] = hash('sha256', DocumentText::VERSION."\0".$this->embeddings->version()."\0".$row['text']);
                    if ($row['hash'] === $row['embedding_hash'] && $row['semantic']) {
                        $row['semantic'] = json_decode($row['semantic'], true, 512, JSON_THROW_ON_ERROR);
                    } else { $pending[] = $i; $texts[] = $row['text']; }
                }
                unset($row);
                if ($texts) {
                    $items = $this->retry(fn() => $this->embeddings->encode('document', $texts));
                    foreach ($pending as $j => $i) {
                        $rows[$i]['semantic'] = [
                            'text' => $rows[$i]['text'], 'vector' => $items[$j]['embedding'],
                            'hash' => $rows[$i]['hash'], 'version' => $this->embeddings->version(),
                            'truncated' => $items[$j]['truncated'], 'token_count' => $items[$j]['token_count'],
                        ];
                    }
                }
                $ndjson = '';
                foreach ($rows as &$row) {
                    $op = $row['deleted'] ? 'delete' : 'index';
                    $ndjson .= json_encode([$op => ['_index' => $index, '_id' => $row['id'], 'version' => (int) $row['revision'], 'version_type' => 'external_gte']], JSON_THROW_ON_ERROR)."\n";
                    if (!$row['deleted']) {
                        if ($v2Enabled) $row['semantic']['v2'] = $fieldVectors->build($row['doc'], $row['semantic']['v2'] ?? null);
                        $row['semantic']['identifiers'] = $this->text->identifiers($row['doc']);
                        $original = SearchDocument::fromJson($row['document'], $row['semantic']);
                        $ndjson .= json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                    }
                }
                unset($row);
                $result = $this->retry(fn() => $this->elastic->bulk($ndjson));
                if (count($result['items'] ?? []) !== count($rows)) throw new \RuntimeException('Eksik bulk yanıtı.');
                foreach ($rows as $i => $row) {
                    $item = reset($result['items'][$i]);
                    $ok = $item['status'] < 300 || ($row['deleted'] && $item['status'] === 404);
                    if (!$ok) {
                        ++$failed;
                        $output->writeln('HATA '.$row['id'].': '.json_encode($item['error'] ?? $item));
                        continue;
                    }
                    $stmt = $this->db->prepare('INSERT INTO product_index_state(product_id,index_uuid,indexed_revision,embedding_hash,semantic)
                        VALUES (?,?,?, ?,CAST(? AS jsonb)) ON CONFLICT(product_id,index_uuid)
                        DO UPDATE SET indexed_revision=EXCLUDED.indexed_revision,embedding_hash=EXCLUDED.embedding_hash,semantic=EXCLUDED.semantic');
                    $stmt->execute([$row['id'], $uuid, $row['revision'], $row['deleted'] ? null : $row['hash'], $row['deleted'] ? null : json_encode($row['semantic'], JSON_THROW_ON_ERROR)]);
                    ++$success;
                }
                $this->db->commit();
                $output->writeln("İşlenen: $success, hatalı: $failed; son ID: $cursor");
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e; // No checkpoint on failure; rerunning resumes safely.
            }
        }
        $this->elastic->request('POST', rawurlencode($index).'/_refresh');
        $output->writeln("Tamamlandı: $success kayıt, $failed hata. Başarısız kayıtlar yeniden çalıştırmada denenir.");
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function retry(callable $operation): array
    {
        for ($attempt = 0; ; ++$attempt) {
            try { return $operation(); }
            catch (\Throwable $e) { if ($attempt >= 2) throw $e; usleep(250000 * (2 ** $attempt)); }
        }
    }
}