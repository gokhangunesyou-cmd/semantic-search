<?php

namespace App\Command;

use App\Service\{Elastic, Embeddings, Mapping};
use App\Service\V2\Index\FieldVectors;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:products:reindex',
    description: 'Ürün dosyasını DB’ye alır, seçili model için yeni indeks oluşturur, vektörleri üretip aliası taşır.'
)]
final class ReindexCommand extends Command
{
    public function __construct(
        private Elastic $elastic,
        private Embeddings $embeddings,
        private Mapping $mapping
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dataset', null, InputOption::VALUE_REQUIRED,
                'DB’ye import edilecek JSON/NDJSON dosyası',
                dirname(__DIR__, 3).'/infrastructure/datasets/products.ndjson'
            )
            ->addOption(
                'mapping', null, InputOption::VALUE_REQUIRED,
                'Elasticsearch kaynak mapping dosyası',
                dirname(__DIR__, 3).'/infrastructure/elasticsearch/products.mapping.json'
            )
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, '1–8 arası indeksleme batch boyutu', '8');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->embeddings->ready()) {
            throw new \RuntimeException('Seçili embedding modeli hazır değil veya sürümü uyuşmuyor.');
        }

        $application = $this->getApplication();
        if (!$application) {
            throw new \RuntimeException('Console uygulaması bulunamadı.');
        }
        $import = $application->find('app:products:import');
        $importResult = $import->run(new ArrayInput(['file' => $input->getOption('dataset')]), $output);
        if ($importResult !== Command::SUCCESS) {
            return $importResult;
        }

        $suffix = substr(hash('sha256', $this->embeddings->version().microtime(true)), 0, 8);
        $index = 'products_'.gmdate('Ymd_His').'_'.$suffix;
        $this->elastic->useIndex($index);
        $dimensions = $this->embeddings->dimensions();
        $body = $this->mapping->load($input->getOption('mapping'), $this->embeddings->version());
        $body['mappings']['properties']['semantic'] = Mapping::semantic($dimensions);
        $body['mappings']['properties']['semantic']['properties']['v2'] = FieldVectors::mapping($dimensions);
        $this->elastic->request('PUT', rawurlencode($index), $body);
        $output->writeln("Yeni indeks oluşturuldu: $index ($dimensions boyut)");

        $indexCommand = $application->find('app:products:index');
        $result = $indexCommand->run(new ArrayInput([
            '--batch-size' => $input->getOption('batch-size'),
            '--index' => $index,
        ]), $output);
        if ($result !== Command::SUCCESS) {
            $output->writeln('İndeksleme tamamlanmadı; arama aliası eski indekste bırakıldı.');
            return $result;
        }

        $this->elastic->activate(true);
        $aliases = $this->elastic->request('GET', '_alias/'.rawurlencode($this->elastic->alias()));
        if (!isset($aliases[$index])) {
            throw new \RuntimeException('Yeni indeks hazırlandı fakat arama aliası yeni indekse geçmedi.');
        }
        $output->writeln('Arama aliası güncellendi: '.$this->elastic->alias().' → '.$index);
        return $result;
    }
}