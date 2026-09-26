<?php
namespace App\Service;

final class Database
{
    public static function connect(): \PDO
    {
        return new \PDO(getenv('DATABASE_DSN'), getenv('POSTGRES_USER'), getenv('POSTGRES_PASSWORD'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
