<?php

declare(strict_types=1);

final class FileCache
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function remember(string $key, int $ttl, callable $producer): array
    {
        $this->ensureDirectory();
        $file = $this->fileForKey($key);
        $now = time();

        $cached = $this->read($file);
        if ($cached !== null && ($cached['expires_at'] ?? 0) >= $now) {
            $payload = $cached['payload'] ?? [];
            if (is_array($payload)) {
                $payload['_cache'] = [
                    'hit' => true,
                    'expires_at' => $cached['expires_at'],
                ];

                return $payload;
            }
        }

        $payload = $producer();
        if (!is_array($payload)) {
            throw new RuntimeException('O produtor de cache precisa retornar um array.');
        }

        $payload['_cache'] = [
            'hit' => false,
            'expires_at' => $now + $ttl,
        ];

        $this->write($file, [
            'expires_at' => $now + $ttl,
            'payload' => $payload,
        ]);

        return $payload;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
    }

    private function fileForKey(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $file): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(string $file, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        @file_put_contents($file, $json, LOCK_EX);
    }
}
