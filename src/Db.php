<?php

declare(strict_types=1);

namespace Aio;

use PDO;

/**
 * Database connections. Each application connects as its own user, read from
 * its credentials file in /etc/aio-mothership/db/ (M-036); never as root
 * (MS-SEC-04). Real prepared statements only (MS-SEC-05).
 */
final class Db
{
    /**
     * @param string $cnfPath a MariaDB client option file with a [client] section
     *        (user, password, and socket or host/port), as written by 06-mariadb.sh
     */
    public static function connect(string $cnfPath, string $dbName): PDO
    {
        $opts = self::readCnf($cnfPath);
        if (isset($opts['socket'])) {
            $dsn = "mysql:unix_socket={$opts['socket']};dbname=$dbName;charset=utf8mb4";
        } else {
            $host = $opts['host'] ?? '127.0.0.1';
            $port = (int) ($opts['port'] ?? 3306);
            $dsn = "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4";
        }
        return self::open($dsn, $opts['user'] ?? '', $opts['password'] ?? '');
    }

    public static function open(string $dsn, string $user, string $password): PDO
    {
        $pdo = PDO::connect($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // UTC everywhere (M-010); enough warnings kept for the INSERT IGNORE check (M-005).
        $pdo->exec("SET time_zone = '+00:00', max_error_count = 65535");
        return $pdo;
    }

    /** @return array<string, string> */
    public static function readCnf(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("can't read database credentials file $path");
        }
        $out = [];
        $inClient = false;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $line, $m)) {
                $inClient = in_array(strtolower($m[1]), ['client', 'mysql', 'mariadb', 'client-mariadb'], true);
                continue;
            }
            if ($inClient && preg_match('/^([A-Za-z_-]+)\s*=\s*(.*)$/', $line, $m)) {
                $out[str_replace('-', '_', strtolower($m[1]))] = trim($m[2], " \t\"'");
            }
        }
        return $out;
    }
}
