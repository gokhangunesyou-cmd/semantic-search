<?php
namespace App\Command;

use App\Service\Products;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputArgument};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:products:import', description: 'JSON veya NDJSON ürünlerini PostgreSQL veritabanına alır; Elasticsearch’e yazmaz.')]
final class ImportCommand extends Command
{
    public function __construct(private Products $products) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('file', InputArgument::REQUIRED, 'JSON veya .ndjson dosyası'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('file');
        if (!is_readable($path)) throw new \InvalidArgumentException('Dosya okunamıyor.');
        $count = $failed = 0;
        $save = function ($doc, $line) use (&$count, &$failed, $output): void {
            try {
                if (!$doc instanceof \stdClass) throw new \InvalidArgumentException('JSON nesnesi bekleniyor.');
                $this->products->saveJson(json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                ++$count;
            } catch (\Throwable $e) { ++$failed; $output->writeln('Kayıt '.$line.': '.$e->getMessage()); }
        };
        if (str_ends_with(strtolower($path), '.ndjson')) {
            $file = new \SplFileObject($path);
            foreach ($file as $line => $text) {
                if (!trim($text)) continue;
                try { $doc = json_decode($text, false, 512, JSON_THROW_ON_ERROR); }
                catch (\JsonException $e) { ++$failed; $output->writeln('Satır '.($line + 1).': bozuk JSON'); continue; }
                $save($doc, $line + 1);
            }
        } else {
            $data = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
            foreach (is_array($data) ? $data : [$data] as $i => $doc) $save($doc, $i + 1);
        }
        $output->writeln("DB: $count başarılı, $failed hatalı. İndeksleme için app:products:index çalıştırın.");
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
