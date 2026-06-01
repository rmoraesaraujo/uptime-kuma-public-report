<?php

declare(strict_types=1);

final class SchemaDetector
{
    /**
     * @return array{
     *     monitorTable: ?string,
     *     monitorColumns: array{id: ?string, name: ?string, active: ?string},
     *     heartbeatTable: string,
     *     heartbeatColumns: array{id: ?string, monitor_id: string, status: string, time: string},
     *     timeMode: string
     * }
     */
    public function detect(PDO $pdo): array
    {
        $tables = $this->tables($pdo);
        $metadata = [];

        foreach ($tables as $table) {
            $metadata[$table] = $this->columns($pdo, $table);
        }

        $heartbeat = $this->detectHeartbeatTable($metadata);
        if ($heartbeat === null) {
            throw new RuntimeException('Nao foi possivel identificar a tabela de heartbeats do Uptime Kuma.');
        }

        $monitor = $this->detectMonitorTable($metadata);
        $heartbeatColumns = [
            'id' => $this->findColumn($metadata[$heartbeat], ['id', 'heartbeat_id']),
            'monitor_id' => $this->findColumn($metadata[$heartbeat], ['monitor_id', 'monitorId', 'monitor', 'monitorID']),
            'status' => $this->findColumn($metadata[$heartbeat], ['status', 'state', 'value']),
            'time' => $this->findColumn($metadata[$heartbeat], ['time', 'created_at', 'createdAt', 'timestamp', 'date', 'datetime']),
        ];

        if ($heartbeatColumns['monitor_id'] === null || $heartbeatColumns['status'] === null || $heartbeatColumns['time'] === null) {
            throw new RuntimeException('A tabela de heartbeats foi encontrada, mas as colunas essenciais nao foram reconhecidas.');
        }

        $monitorColumns = [
            'id' => null,
            'name' => null,
            'active' => null,
        ];

        if ($monitor !== null) {
            $monitorColumns = [
                'id' => $this->findColumn($metadata[$monitor], ['id', 'monitor_id', 'monitorId']),
                'name' => $this->findColumn($metadata[$monitor], ['name', 'display_name', 'displayName', 'friendly_name', 'title']),
                'active' => $this->findColumn($metadata[$monitor], ['active', 'enabled', 'is_active', 'isActive']),
            ];
        }

        return [
            'monitorTable' => $monitor,
            'monitorColumns' => $monitorColumns,
            'heartbeatTable' => $heartbeat,
            'heartbeatColumns' => [
                'id' => $heartbeatColumns['id'],
                'monitor_id' => $heartbeatColumns['monitor_id'],
                'status' => $heartbeatColumns['status'],
                'time' => $heartbeatColumns['time'],
            ],
            'timeMode' => $this->detectTimeMode($pdo, $heartbeat, $heartbeatColumns['time']),
        ];
    }

    /**
     * @return list<string>
     */
    private function tables(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll();

        return array_values(array_map(static fn (array $row): string => (string) $row['name'], $rows));
    }

    /**
     * @return array<string, array{name: string, type: string}>
     */
    private function columns(PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')')->fetchAll();
        $columns = [];

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $columns[$name] = [
                'name' => $name,
                'type' => strtoupper((string) ($row['type'] ?? '')),
            ];
        }

        return $columns;
    }

    /**
     * @param array<string, array<string, array{name: string, type: string}>> $metadata
     */
    private function detectHeartbeatTable(array $metadata): ?string
    {
        $bestTable = null;
        $bestScore = 0;

        foreach ($metadata as $table => $columns) {
            $score = 0;
            $tableKey = $this->normalize($table);

            if ($tableKey === 'heartbeat' || $tableKey === 'heartbeats') {
                $score += 60;
            }
            if (str_contains($tableKey, 'heartbeat')) {
                $score += 30;
            }
            if ($this->findColumn($columns, ['monitor_id', 'monitorId', 'monitor']) !== null) {
                $score += 15;
            }
            if ($this->findColumn($columns, ['status', 'state', 'value']) !== null) {
                $score += 15;
            }
            if ($this->findColumn($columns, ['time', 'created_at', 'createdAt', 'timestamp', 'date', 'datetime']) !== null) {
                $score += 15;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTable = $table;
            }
        }

        return $bestScore >= 35 ? $bestTable : null;
    }

    /**
     * @param array<string, array<string, array{name: string, type: string}>> $metadata
     */
    private function detectMonitorTable(array $metadata): ?string
    {
        $bestTable = null;
        $bestScore = 0;

        foreach ($metadata as $table => $columns) {
            $score = 0;
            $tableKey = $this->normalize($table);

            if ($tableKey === 'monitor' || $tableKey === 'monitors') {
                $score += 70;
            }
            if (str_contains($tableKey, 'monitor')) {
                $score += 20;
            }
            if ($this->findColumn($columns, ['id', 'monitor_id', 'monitorId']) !== null) {
                $score += 10;
            }
            if ($this->findColumn($columns, ['name', 'display_name', 'displayName', 'friendly_name', 'title']) !== null) {
                $score += 20;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTable = $table;
            }
        }

        return $bestScore >= 50 ? $bestTable : null;
    }

    /**
     * @param array<string, array{name: string, type: string}> $columns
     * @param list<string> $aliases
     */
    private function findColumn(array $columns, array $aliases): ?string
    {
        $normalizedAliases = array_map(fn (string $alias): string => $this->normalize($alias), $aliases);

        foreach ($columns as $column) {
            if (in_array($this->normalize($column['name']), $normalizedAliases, true)) {
                return $column['name'];
            }
        }

        return null;
    }

    private function detectTimeMode(PDO $pdo, string $table, string $timeColumn): string
    {
        $sql = sprintf(
            'SELECT %s AS sample FROM %s WHERE %s IS NOT NULL ORDER BY %s DESC LIMIT 1',
            $this->quoteIdentifier($timeColumn),
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($timeColumn),
            $this->quoteIdentifier($timeColumn)
        );

        $sample = $pdo->query($sql)->fetchColumn();
        if ($sample !== false && is_numeric($sample)) {
            $numeric = (float) $sample;
            if ($numeric > 100000000000) {
                return 'unix_ms';
            }
            if ($numeric > 1000000000) {
                return 'unix';
            }
        }

        return 'text';
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $value));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
