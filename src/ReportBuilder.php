<?php

declare(strict_types=1);

/**
 * Wires DatabaseLocator + SchemaDetector + StatsService + FileCache together to build
 * (and cache) a report for a given set of filters. Shared by the public page, the
 * JSON API and the admin panel's diagnostics view.
 */
final class ReportBuilder
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array{monitor: string, period: string, status: string} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $locator = new DatabaseLocator();
        $database = $locator->locate($this->config);
        $cache = new FileCache($this->config->cachePath);
        $cacheKey = implode('|', [
            'report-v4',
            $database['path'],
            $this->config->appTimezone,
            $this->config->dbTimezone,
            $this->config->sqliteImmutable ? 'immutable' : 'ro',
            $filters['monitor'],
            $filters['period'],
            $filters['status'],
        ]);

        return $cache->remember($cacheKey, $this->config->cacheTtl, function () use ($database, $filters): array {
            $connectionFactory = new SqliteConnectionFactory();
            $pdo = $connectionFactory->openReadOnly($database['path'], $this->config);
            $schema = (new SchemaDetector())->detect($pdo);
            $report = (new StatsService($this->config))->buildReport($pdo, $schema, $filters);
            $report['meta'] = [
                'databaseSource' => $database['source'],
                'cacheTtl' => $this->config->cacheTtl,
            ];

            return $report;
        });
    }
}
