<?php

declare(strict_types=1);

final class DatabaseLocator
{
    /**
     * @return array{path: string, source: string}
     */
    public function locate(Config $config): array
    {
        if ($config->sqlitePath !== null) {
            $path = $this->normalizePath($config->sqlitePath);
            if (!$this->isReadableSqlite($path)) {
                throw new RuntimeException('O arquivo SQLite configurado nao foi encontrado ou nao parece ser um banco SQLite valido.');
            }

            return ['path' => $path, 'source' => 'SQLITE_PATH'];
        }

        $dataPath = $this->normalizePath($config->dataPath);
        if (!is_dir($dataPath) || !is_readable($dataPath)) {
            throw new RuntimeException('O diretorio de dados do Uptime Kuma nao esta acessivel para leitura.');
        }

        $candidates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dataPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, ['db', 'sqlite', 'sqlite3'], true)) {
                continue;
            }

            if (!$this->isReadableSqlite($path)) {
                continue;
            }

            $name = strtolower($file->getBasename());
            $score = 0;
            if ($name === 'kuma.db') {
                $score += 100;
            }
            if (str_contains($name, 'kuma')) {
                $score += 40;
            }
            if (str_contains($name, 'uptime')) {
                $score += 20;
            }
            if ($extension === 'db') {
                $score += 5;
            }

            $candidates[] = [
                'path' => $this->normalizePath($path),
                'score' => $score,
                'mtime' => $file->getMTime(),
                'size' => $file->getSize(),
            ];
        }

        if ($candidates === []) {
            throw new RuntimeException('Nenhum arquivo SQLite valido foi encontrado no volume de dados do Uptime Kuma.');
        }

        usort($candidates, static function (array $a, array $b): int {
            return [$b['score'], $b['mtime'], $b['size']] <=> [$a['score'], $a['mtime'], $a['size']];
        });

        return ['path' => $candidates[0]['path'], 'source' => 'auto'];
    }

    private function normalizePath(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath !== false) {
            return $realPath;
        }

        return $path;
    }

    private function isReadableSqlite(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 16);
        fclose($handle);

        return $header === "SQLite format 3\0";
    }
}
