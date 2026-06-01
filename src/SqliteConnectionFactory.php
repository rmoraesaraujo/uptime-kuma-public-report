<?php

declare(strict_types=1);

final class SqliteConnectionFactory
{
    public function openReadOnly(string $path, Config $config): PDO
    {
        $query = ['mode' => 'ro'];
        if ($config->sqliteImmutable) {
            $query['immutable'] = '1';
        }

        $dsn = 'sqlite:' . $this->toSqliteUri($path, $query);

        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 2,
        ]);

        $pdo->exec('PRAGMA query_only = ON');
        $pdo->exec('PRAGMA busy_timeout = 1000');

        return $pdo;
    }

    /**
     * @param array<string, string> $query
     */
    private function toSqliteUri(string $path, array $query): string
    {
        $normalized = str_replace('\\', '/', $path);
        $parts = explode('/', $normalized);
        $encoded = implode('/', array_map('rawurlencode', $parts));

        if (preg_match('/^[A-Za-z]%3A\//', $encoded) === 1) {
            $encoded = '/' . $encoded;
        }

        return 'file:' . $encoded . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
