<?php

declare(strict_types=1);

final class Config
{
    public function __construct(
        public readonly string $dataPath,
        public readonly ?string $sqlitePath,
        public readonly string $cachePath,
        public readonly int $cacheTtl,
        public readonly string $appTimezone,
        public readonly string $dbTimezone,
        public readonly string $publicTitle,
        public readonly bool $sqliteImmutable,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $cacheTtl = self::envInt('CACHE_TTL', 60);
        if ($cacheTtl < 5) {
            $cacheTtl = 5;
        }

        return new self(
            dataPath: rtrim(self::envString('DATA_PATH', '/kuma-data'), DIRECTORY_SEPARATOR),
            sqlitePath: self::envNullable('SQLITE_PATH'),
            cachePath: rtrim(self::envString('CACHE_PATH', sys_get_temp_dir() . '/uptime-kuma-public-report-cache'), DIRECTORY_SEPARATOR),
            cacheTtl: $cacheTtl,
            appTimezone: self::envString('APP_TIMEZONE', 'America/Sao_Paulo'),
            dbTimezone: self::envString('DB_TIMEZONE', 'UTC'),
            publicTitle: self::envString('PUBLIC_TITLE', 'Relatorio de Incidentes'),
            sqliteImmutable: self::envBool('SQLITE_IMMUTABLE', false),
        );
    }

    private static function envString(string $name, string $default): string
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        return trim((string) $value);
    }

    private static function envNullable(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
