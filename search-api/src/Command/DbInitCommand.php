<?php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:db:init', description: 'PostgreSQL ürün ve indeks durum tablolarını oluşturur.')]
final class DbInitCommand extends Command
{
    public function __construct(private \PDO $db) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->db->exec(file_get_contents(dirname(__DIR__, 3).'/infrastructure/schema.sql'));
        $output->writeln('Veritabanı hazır.');
        return Command::SUCCESS;
    }
}
