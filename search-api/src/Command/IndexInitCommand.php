<?php
namespace App\Command;

use App\Service\{Elastic, Embeddings, Mapping};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:elastic:init', description: 'Nested ürün mapping’i ve semantic alanıyla yeni indeks oluşturur.')]
final class IndexInitCommand extends Command
{
    public function __construct(private Elastic $elastic, private Mapping $mapping, private Embeddings $embeddings) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('mapping', null, InputOption::VALUE_REQUIRED, 'Gerçek _mapping çıktısı veya create-index JSON dosyası', dirname(__DIR__, 3).'/infrastructure/elasticsearch/products.mapping.json');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = $this->mapping->load($input->getOption('mapping'), $this->embeddings->version());
        $body['mappings']['properties']['semantic'] = Mapping::semantic($this->embeddings->dimensions());
        $this->elastic->request('PUT', rawurlencode($this->elastic->index()), $body);
        $output->writeln('İndeks oluşturuldu. Kaynak alanlar kökte; variants ve variants.merchants nested.');
        return Command::SUCCESS;
    }
}
