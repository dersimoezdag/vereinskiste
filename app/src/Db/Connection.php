<?php
declare(strict_types=1);

namespace Vereinskiste\Db;

use PDO;

final class Connection
{
    /**
     * @param array{dsn?:string,host?:string,port?:int|string,name?:string,user?:string,pass?:string} $cfg
     */
    public static function fromConfig(array $cfg): PDO
    {
        $dsn = $cfg['dsn'] ?? sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $cfg['host'] ?? 'localhost',
            $cfg['port'] ?? 5432,
            $cfg['name'] ?? ''
        );
        $pdo = new PDO($dsn, $cfg['user'] ?? null, $cfg['pass'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET client_encoding TO 'UTF8'");
        return $pdo;
    }
}
