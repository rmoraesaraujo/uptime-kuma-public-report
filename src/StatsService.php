<?php

declare(strict_types=1);

final class StatsService
{
    private DateTimeZone $appTimezone;
    private DateTimeZone $dbTimezone;

    public function __construct(private readonly Config $config)
    {
        $this->appTimezone = new DateTimeZone($config->appTimezone);
        $this->dbTimezone = new DateTimeZone($config->dbTimezone);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array{monitor: string, period: string, status: string} $filters
     * @return array<string, mixed>
     */
    public function buildReport(PDO $pdo, array $schema, array $filters): array
    {
        $now = new DateTimeImmutable('now', $this->appTimezone);
        $ranges = $this->ranges($now);
        $selectedRange = $ranges[$filters['period']] ?? $ranges['7d'];
        $monitors = $this->fetchMonitors($pdo, $schema);

        $availableMonitorIds = array_keys($monitors);
        $monitorId = $this->sanitizeMonitorFilter($filters['monitor'], $availableMonitorIds);
        $monitorScopedIds = $monitorId === null ? $availableMonitorIds : [$monitorId];

        $eventsByMonitor = $this->fetchEventsByMonitor($pdo, $schema, $monitorScopedIds, $ranges['30d']['start']);
        $currentStatuses = $this->currentStatuses($eventsByMonitor, $now);
        $visibleMonitorIds = $this->applyStatusFilter($monitorScopedIds, $currentStatuses, $filters['status']);

        $today = $this->computeRange($eventsByMonitor, $visibleMonitorIds, $ranges['today']['start'], $ranges['today']['end']);
        $sevenDays = $this->computeRange($eventsByMonitor, $visibleMonitorIds, $ranges['7d']['start'], $ranges['7d']['end']);
        $thirtyDays = $this->computeRange($eventsByMonitor, $visibleMonitorIds, $ranges['30d']['start'], $ranges['30d']['end']);
        $selected = $this->computeRange($eventsByMonitor, $visibleMonitorIds, $selectedRange['start'], $selectedRange['end']);

        $monitorRows = $this->buildMonitorRows($monitors, $visibleMonitorIds, $currentStatuses, $selected, $today, $sevenDays, $thirtyDays);
        $recentIncidents = $selected['incidentEvents'];
        usort($recentIncidents, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return [
            'title' => $this->config->publicTitle,
            'generatedAt' => $now->format(DateTimeInterface::ATOM),
            'generatedAtLabel' => $now->format('d/m/Y H:i:s'),
            'filters' => [
                'monitor' => $monitorId === null ? 'all' : (string) $monitorId,
                'period' => $filters['period'],
                'status' => $filters['status'],
            ],
            'ranges' => [
                'selected' => $this->rangePayload($selectedRange),
                'today' => $this->rangePayload($ranges['today']),
                '7d' => $this->rangePayload($ranges['7d']),
                '30d' => $this->rangePayload($ranges['30d']),
            ],
            'summary' => [
                'totalMonitors' => count($visibleMonitorIds),
                'downMonitors' => count(array_filter($visibleMonitorIds, static fn (int $id): bool => ($currentStatuses[$id] ?? 'unknown') === 'down')),
                'incidentsToday' => $today['incidents'],
                'incidents7d' => $sevenDays['incidents'],
                'incidents30d' => $thirtyDays['incidents'],
                'downtimeSelectedSeconds' => $selected['downtimeSeconds'],
                'downtimeSelectedLabel' => $this->formatDuration($selected['downtimeSeconds']),
                'uptimeToday' => $this->formatPercent($today['uptimePercent']),
                'uptime7d' => $this->formatPercent($sevenDays['uptimePercent']),
                'uptime30d' => $this->formatPercent($thirtyDays['uptimePercent']),
                'uptimeSelected' => $this->formatPercent($selected['uptimePercent']),
            ],
            'monitors' => array_values($monitors),
            'visibleMonitorRows' => $monitorRows,
            'series' => $this->dailySeries($eventsByMonitor, $visibleMonitorIds, $selectedRange['start'], $selectedRange['end']),
            'recentIncidents' => array_slice($recentIncidents, 0, 25),
        ];
    }

    /**
     * @return array<string, array{start: DateTimeImmutable, end: DateTimeImmutable, label: string}>
     */
    private function ranges(DateTimeImmutable $now): array
    {
        $todayStart = $now->setTime(0, 0, 0);

        return [
            'today' => [
                'start' => $todayStart,
                'end' => $now,
                'label' => 'Hoje',
            ],
            '7d' => [
                'start' => $todayStart->modify('-6 days'),
                'end' => $now,
                'label' => 'Ultimos 7 dias',
            ],
            '30d' => [
                'start' => $todayStart->modify('-29 days'),
                'end' => $now,
                'label' => 'Ultimos 30 dias',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<int, array{id: int, name: string, active: ?bool}>
     */
    private function fetchMonitors(PDO $pdo, array $schema): array
    {
        $monitorTable = $schema['monitorTable'] ?? null;
        $monitorColumns = $schema['monitorColumns'] ?? [];

        if (
            is_string($monitorTable)
            && is_string($monitorColumns['id'] ?? null)
            && is_string($monitorColumns['name'] ?? null)
        ) {
            $select = [
                $this->quoteIdentifier($monitorColumns['id']) . ' AS id',
                $this->quoteIdentifier($monitorColumns['name']) . ' AS name',
            ];

            if (is_string($monitorColumns['active'] ?? null)) {
                $select[] = $this->quoteIdentifier($monitorColumns['active']) . ' AS active';
            } else {
                $select[] = 'NULL AS active';
            }

            $sql = sprintf(
                'SELECT %s FROM %s ORDER BY %s COLLATE NOCASE ASC',
                implode(', ', $select),
                $this->quoteIdentifier($monitorTable),
                $this->quoteIdentifier($monitorColumns['name'])
            );

            $rows = $pdo->query($sql)->fetchAll();
            $monitors = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $monitors[$id] = [
                    'id' => $id,
                    'name' => trim((string) ($row['name'] ?? '')) !== '' ? trim((string) $row['name']) : 'Monitor #' . $id,
                    'active' => $row['active'] === null ? null : ((int) $row['active'] === 1),
                ];
            }

            if ($monitors !== []) {
                return $monitors;
            }
        }

        $heartbeat = $schema['heartbeatTable'];
        $monitorIdColumn = $schema['heartbeatColumns']['monitor_id'];
        $sql = sprintf(
            'SELECT DISTINCT %s AS id FROM %s ORDER BY %s ASC',
            $this->quoteIdentifier($monitorIdColumn),
            $this->quoteIdentifier($heartbeat),
            $this->quoteIdentifier($monitorIdColumn)
        );

        $rows = $pdo->query($sql)->fetchAll();
        $monitors = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $monitors[$id] = [
                'id' => $id,
                'name' => 'Monitor #' . $id,
                'active' => null,
            ];
        }

        return $monitors;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<int> $monitorIds
     * @return array<int, list<array{monitor_id: int, status: mixed, statusKey: string, at: DateTimeImmutable}>>
     */
    private function fetchEventsByMonitor(PDO $pdo, array $schema, array $monitorIds, DateTimeImmutable $from): array
    {
        $eventsByMonitor = [];
        foreach ($monitorIds as $monitorId) {
            $eventsByMonitor[$monitorId] = [];
        }

        if ($monitorIds === []) {
            return $eventsByMonitor;
        }

        foreach ($this->fetchPreviousEvents($pdo, $schema, $monitorIds, $from) as $event) {
            $eventsByMonitor[$event['monitor_id']][] = $event;
        }

        try {
            $events = $this->fetchChangedEventsWithWindowFunction($pdo, $schema, $monitorIds, $from);
        } catch (Throwable) {
            $events = $this->fetchChangedEventsFallback($pdo, $schema, $monitorIds, $from);
        }

        foreach ($events as $event) {
            $eventsByMonitor[$event['monitor_id']][] = $event;
        }

        foreach ($eventsByMonitor as $monitorId => $events) {
            usort($events, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $eventsByMonitor[$monitorId] = $events;
        }

        return $eventsByMonitor;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<int> $monitorIds
     * @return list<array{monitor_id: int, status: mixed, statusKey: string, at: DateTimeImmutable}>
     */
    private function fetchPreviousEvents(PDO $pdo, array $schema, array $monitorIds, DateTimeImmutable $from): array
    {
        $events = [];
        $heartbeat = $schema['heartbeatTable'];
        $columns = $schema['heartbeatColumns'];
        $timeValue = $this->toDbTime($from, $schema['timeMode']);
        $timeWhere = $this->timeWhere($columns['time'], '<', ':from', $schema['timeMode']);
        $order = $this->timeOrderExpression($columns['time'], $schema['timeMode']) . ' DESC';
        if (is_string($columns['id'] ?? null)) {
            $order .= ', ' . $this->quoteIdentifier($columns['id']) . ' DESC';
        }

        $sql = sprintf(
            'SELECT %s AS monitor_id, %s AS status, %s AS recorded_at FROM %s WHERE %s = :monitor_id AND %s ORDER BY %s LIMIT 1',
            $this->quoteIdentifier($columns['monitor_id']),
            $this->quoteIdentifier($columns['status']),
            $this->quoteIdentifier($columns['time']),
            $this->quoteIdentifier($heartbeat),
            $this->quoteIdentifier($columns['monitor_id']),
            $timeWhere,
            $order
        );

        $statement = $pdo->prepare($sql);
        foreach ($monitorIds as $monitorId) {
            $statement->execute([
                ':monitor_id' => $monitorId,
                ':from' => $timeValue,
            ]);
            $row = $statement->fetch();
            if (is_array($row)) {
                $event = $this->rowToEvent($row, $schema['timeMode']);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<int> $monitorIds
     * @return list<array{monitor_id: int, status: mixed, statusKey: string, at: DateTimeImmutable}>
     */
    private function fetchChangedEventsWithWindowFunction(PDO $pdo, array $schema, array $monitorIds, DateTimeImmutable $from): array
    {
        $heartbeat = $schema['heartbeatTable'];
        $columns = $schema['heartbeatColumns'];
        $timeValue = $this->toDbTime($from, $schema['timeMode']);
        $timeExpression = $this->timeOrderExpression($columns['time'], $schema['timeMode']);
        $idExpression = is_string($columns['id'] ?? null) ? $this->quoteIdentifier($columns['id']) : $timeExpression;
        [$inSql, $params] = $this->inClause('monitor', $monitorIds);

        $sql = sprintf(
            'WITH ordered AS (
                SELECT
                    %1$s AS monitor_id,
                    %2$s AS status,
                    %3$s AS recorded_at,
                    %4$s AS sort_time,
                    LAG(%2$s) OVER (PARTITION BY %1$s ORDER BY %4$s ASC, %5$s ASC) AS previous_status
                FROM %6$s
                WHERE %1$s IN (%7$s) AND %8$s
            )
            SELECT monitor_id, status, recorded_at
            FROM ordered
            WHERE previous_status IS NULL OR CAST(status AS TEXT) <> CAST(previous_status AS TEXT)
            ORDER BY monitor_id ASC, sort_time ASC',
            $this->quoteIdentifier($columns['monitor_id']),
            $this->quoteIdentifier($columns['status']),
            $this->quoteIdentifier($columns['time']),
            $timeExpression,
            $idExpression,
            $this->quoteIdentifier($heartbeat),
            $inSql,
            $this->timeWhere($columns['time'], '>=', ':from', $schema['timeMode'])
        );

        $statement = $pdo->prepare($sql);
        $statement->execute($params + [':from' => $timeValue]);

        return $this->rowsToEvents($statement->fetchAll(), $schema['timeMode']);
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<int> $monitorIds
     * @return list<array{monitor_id: int, status: mixed, statusKey: string, at: DateTimeImmutable}>
     */
    private function fetchChangedEventsFallback(PDO $pdo, array $schema, array $monitorIds, DateTimeImmutable $from): array
    {
        $heartbeat = $schema['heartbeatTable'];
        $columns = $schema['heartbeatColumns'];
        $timeValue = $this->toDbTime($from, $schema['timeMode']);
        [$inSql, $params] = $this->inClause('monitor', $monitorIds);
        $order = $this->timeOrderExpression($columns['time'], $schema['timeMode']) . ' ASC';
        if (is_string($columns['id'] ?? null)) {
            $order .= ', ' . $this->quoteIdentifier($columns['id']) . ' ASC';
        }

        $sql = sprintf(
            'SELECT %s AS monitor_id, %s AS status, %s AS recorded_at
             FROM %s
             WHERE %s IN (%s) AND %s
             ORDER BY %s ASC, %s',
            $this->quoteIdentifier($columns['monitor_id']),
            $this->quoteIdentifier($columns['status']),
            $this->quoteIdentifier($columns['time']),
            $this->quoteIdentifier($heartbeat),
            $this->quoteIdentifier($columns['monitor_id']),
            $inSql,
            $this->timeWhere($columns['time'], '>=', ':from', $schema['timeMode']),
            $this->quoteIdentifier($columns['monitor_id']),
            $order
        );

        $statement = $pdo->prepare($sql);
        $statement->execute($params + [':from' => $timeValue]);
        $rawEvents = $this->rowsToEvents($statement->fetchAll(), $schema['timeMode']);
        $changedEvents = [];
        $lastStatusByMonitor = [];

        foreach ($rawEvents as $event) {
            $monitorId = $event['monitor_id'];
            if (($lastStatusByMonitor[$monitorId] ?? null) !== $event['statusKey']) {
                $changedEvents[] = $event;
                $lastStatusByMonitor[$monitorId] = $event['statusKey'];
            }
        }

        return $changedEvents;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{monitor_id: int, status: mixed, statusKey: string, at: DateTimeImmutable}>
     */
    private function rowsToEvents(array $rows, string $timeMode): array
    {
        $events = [];
        foreach ($rows as $row) {
            $event = $this->rowToEvent($row, $timeMode);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{monitor_id: int, status: mixed, statusKey: string, at: DateTimeImmutable}|null
     */
    private function rowToEvent(array $row, string $timeMode): ?array
    {
        $at = $this->parseDbTime($row['recorded_at'] ?? null, $timeMode);
        if ($at === null) {
            return null;
        }

        $status = $row['status'] ?? null;

        return [
            'monitor_id' => (int) $row['monitor_id'],
            'status' => $status,
            'statusKey' => $this->normalizeStatus($status),
            'at' => $at,
        ];
    }

    /**
     * @param array<int, list<array{monitor_id: int, status: mixed, statusKey: string, at: DateTimeImmutable}>> $eventsByMonitor
     * @param list<int> $monitorIds
     * @return array{
     *     incidents: int,
     *     downtimeSeconds: int,
     *     uptimePercent: ?float,
     *     perMonitor: array<int, array{incidents: int, downtimeSeconds: int, uptimePercent: ?float}>,
     *     incidentEvents: list<array<string, mixed>>
     * }
     */
    private function computeRange(array $eventsByMonitor, array $monitorIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $duration = max(0, $end->getTimestamp() - $start->getTimestamp());
        $totalDowntime = 0;
        $totalIncidents = 0;
        $incidentEvents = [];
        $perMonitor = [];

        foreach ($monitorIds as $monitorId) {
            $events = $eventsByMonitor[$monitorId] ?? [];
            $state = 'unknown';
            $stateStart = $start;
            $monitorDowntime = 0;
            $monitorIncidents = 0;

            foreach ($events as $event) {
                $eventAt = $event['at'];

                if ($eventAt < $start) {
                    $state = $event['statusKey'];
                    $stateStart = $start;
                    continue;
                }

                if ($eventAt > $end) {
                    break;
                }

                if ($state === 'down') {
                    $monitorDowntime += $this->overlapSeconds($stateStart, $eventAt, $start, $end);
                }

                if ($state === 'up' && $event['statusKey'] === 'down') {
                    $monitorIncidents++;
                    $incidentEvents[] = [
                        'monitorId' => $monitorId,
                        'at' => $eventAt->format(DateTimeInterface::ATOM),
                        'atLabel' => $eventAt->format('d/m/Y H:i'),
                        'from' => $state,
                        'to' => 'down',
                    ];
                }

                $state = $event['statusKey'];
                $stateStart = $eventAt;
            }

            if ($state === 'down') {
                $monitorDowntime += $this->overlapSeconds($stateStart, $end, $start, $end);
            }

            $monitorUptime = $duration > 0 ? max(0.0, 100.0 - (($monitorDowntime / $duration) * 100.0)) : null;
            $perMonitor[$monitorId] = [
                'incidents' => $monitorIncidents,
                'downtimeSeconds' => $monitorDowntime,
                'uptimePercent' => $monitorUptime,
            ];

            $totalDowntime += $monitorDowntime;
            $totalIncidents += $monitorIncidents;
        }

        $denominator = $duration * count($monitorIds);

        return [
            'incidents' => $totalIncidents,
            'downtimeSeconds' => $totalDowntime,
            'uptimePercent' => $denominator > 0 ? max(0.0, 100.0 - (($totalDowntime / $denominator) * 100.0)) : null,
            'perMonitor' => $perMonitor,
            'incidentEvents' => $incidentEvents,
        ];
    }

    /**
     * @param array<int, array{id: int, name: string, active: ?bool}> $monitors
     * @param list<int> $visibleMonitorIds
     * @param array<int, string> $currentStatuses
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $today
     * @param array<string, mixed> $sevenDays
     * @param array<string, mixed> $thirtyDays
     * @return list<array<string, mixed>>
     */
    private function buildMonitorRows(
        array $monitors,
        array $visibleMonitorIds,
        array $currentStatuses,
        array $selected,
        array $today,
        array $sevenDays,
        array $thirtyDays
    ): array {
        $rows = [];

        foreach ($visibleMonitorIds as $monitorId) {
            $monitor = $monitors[$monitorId] ?? [
                'id' => $monitorId,
                'name' => 'Monitor #' . $monitorId,
                'active' => null,
            ];

            $selectedMonitor = $selected['perMonitor'][$monitorId] ?? [
                'incidents' => 0,
                'downtimeSeconds' => 0,
                'uptimePercent' => null,
            ];

            $rows[] = [
                'id' => $monitorId,
                'name' => $monitor['name'],
                'active' => $monitor['active'],
                'status' => $currentStatuses[$monitorId] ?? 'unknown',
                'statusLabel' => $this->statusLabel($currentStatuses[$monitorId] ?? 'unknown'),
                'incidentsSelected' => $selectedMonitor['incidents'],
                'incidentsToday' => $today['perMonitor'][$monitorId]['incidents'] ?? 0,
                'incidents7d' => $sevenDays['perMonitor'][$monitorId]['incidents'] ?? 0,
                'incidents30d' => $thirtyDays['perMonitor'][$monitorId]['incidents'] ?? 0,
                'downtimeSelectedSeconds' => $selectedMonitor['downtimeSeconds'],
                'downtimeSelectedLabel' => $this->formatDuration((int) $selectedMonitor['downtimeSeconds']),
                'uptimeSelected' => $this->formatPercent($selectedMonitor['uptimePercent']),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$b['incidentsSelected'], $b['downtimeSelectedSeconds'], $a['name']] <=> [$a['incidentsSelected'], $a['downtimeSelectedSeconds'], $b['name']];
        });

        return $rows;
    }

    /**
     * @param array<int, list<array{monitor_id: int, status: mixed, statusKey: string, at: DateTimeImmutable}>> $eventsByMonitor
     * @param list<int> $monitorIds
     * @return list<array<string, mixed>>
     */
    private function dailySeries(array $eventsByMonitor, array $monitorIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $series = [];
        $cursor = $start;

        while ($cursor < $end) {
            $nextMidnight = $cursor->modify('+1 day')->setTime(0, 0, 0);
            if ($nextMidnight <= $cursor) {
                $nextMidnight = $cursor->modify('+1 day');
            }
            $bucketEnd = $nextMidnight < $end ? $nextMidnight : $end;
            $computed = $this->computeRange($eventsByMonitor, $monitorIds, $cursor, $bucketEnd);

            $series[] = [
                'label' => $cursor->format('d/m'),
                'from' => $cursor->format(DateTimeInterface::ATOM),
                'to' => $bucketEnd->format(DateTimeInterface::ATOM),
                'incidents' => $computed['incidents'],
                'downtimeSeconds' => $computed['downtimeSeconds'],
                'downtimeLabel' => $this->formatDuration($computed['downtimeSeconds']),
                'uptimePercent' => $computed['uptimePercent'],
                'uptimeLabel' => $this->formatPercent($computed['uptimePercent']),
                'barHeight' => $computed['uptimePercent'] === null ? 0 : max(4, (int) round($computed['uptimePercent'])),
            ];

            $cursor = $bucketEnd;
        }

        return $series;
    }

    /**
     * @param array<int, list<array{monitor_id: int, status: mixed, statusKey: string, at: DateTimeImmutable}>> $eventsByMonitor
     * @return array<int, string>
     */
    private function currentStatuses(array $eventsByMonitor, DateTimeImmutable $now): array
    {
        $statuses = [];

        foreach ($eventsByMonitor as $monitorId => $events) {
            $status = 'unknown';
            foreach ($events as $event) {
                if ($event['at'] > $now) {
                    break;
                }
                $status = $event['statusKey'];
            }
            $statuses[$monitorId] = $status;
        }

        return $statuses;
    }

    /**
     * @param list<int> $monitorIds
     * @param array<int, string> $currentStatuses
     * @return list<int>
     */
    private function applyStatusFilter(array $monitorIds, array $currentStatuses, string $status): array
    {
        if ($status === 'all') {
            return $monitorIds;
        }

        return array_values(array_filter(
            $monitorIds,
            static fn (int $monitorId): bool => ($currentStatuses[$monitorId] ?? 'unknown') === $status
        ));
    }

    /**
     * @param list<int> $availableMonitorIds
     */
    private function sanitizeMonitorFilter(string $monitor, array $availableMonitorIds): ?int
    {
        if ($monitor === 'all' || $monitor === '') {
            return null;
        }

        $monitorId = (int) $monitor;
        return in_array($monitorId, $availableMonitorIds, true) ? $monitorId : null;
    }

    private function normalizeStatus(mixed $status): string
    {
        if (is_numeric($status)) {
            return match ((int) $status) {
                0 => 'down',
                1 => 'up',
                2 => 'pending',
                3 => 'maintenance',
                default => 'unknown',
            };
        }

        $value = strtolower(trim((string) $status));
        return match ($value) {
            'up', 'online', 'ok', 'healthy', 'success', '1' => 'up',
            'down', 'offline', 'fail', 'failed', 'error', 'critical', '0' => 'down',
            'pending', 'starting', 'queued', '2' => 'pending',
            'maintenance', 'maint', 'paused', '3' => 'maintenance',
            default => 'unknown',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'up' => 'UP',
            'down' => 'DOWN',
            'pending' => 'Pendente',
            'maintenance' => 'Manutencao',
            default => 'Desconhecido',
        };
    }

    private function overlapSeconds(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): int
    {
        $start = max($from->getTimestamp(), $rangeStart->getTimestamp());
        $end = min($to->getTimestamp(), $rangeEnd->getTimestamp());

        return max(0, $end - $start);
    }

    private function parseDbTime(mixed $value, string $timeMode): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($timeMode === 'unix' || $timeMode === 'unix_ms') {
                $timestamp = (int) $value;
                if ($timeMode === 'unix_ms') {
                    $timestamp = (int) floor($timestamp / 1000);
                }

                return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->appTimezone);
            }

            $text = trim((string) $value);
            $hasExplicitTimezone = preg_match('/(Z|[+-][0-9]{2}:?[0-9]{2})$/', $text) === 1;
            $date = $hasExplicitTimezone
                ? new DateTimeImmutable($text)
                : new DateTimeImmutable($text, $this->dbTimezone);

            return $date->setTimezone($this->appTimezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function toDbTime(DateTimeImmutable $date, string $timeMode): int|string
    {
        $dbDate = $date->setTimezone($this->dbTimezone);

        return match ($timeMode) {
            'unix' => $dbDate->getTimestamp(),
            'unix_ms' => $dbDate->getTimestamp() * 1000,
            default => $dbDate->format('Y-m-d H:i:s'),
        };
    }

    private function timeWhere(string $column, string $operator, string $placeholder, string $timeMode): string
    {
        $quoted = $this->quoteIdentifier($column);
        if ($timeMode === 'text') {
            return sprintf('datetime(%s) %s datetime(%s)', $quoted, $operator, $placeholder);
        }

        return sprintf('%s %s %s', $quoted, $operator, $placeholder);
    }

    private function timeOrderExpression(string $column, string $timeMode): string
    {
        $quoted = $this->quoteIdentifier($column);
        return $timeMode === 'text' ? sprintf('datetime(%s)', $quoted) : $quoted;
    }

    /**
     * @param list<int> $values
     * @return array{0: string, 1: array<string, int>}
     */
    private function inClause(string $prefix, array $values): array
    {
        $placeholders = [];
        $params = [];

        foreach (array_values($values) as $index => $value) {
            $placeholder = ':' . $prefix . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $value;
        }

        return [implode(', ', $placeholders), $params];
    }

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable, label: string} $range
     * @return array<string, string>
     */
    private function rangePayload(array $range): array
    {
        return [
            'label' => $range['label'],
            'start' => $range['start']->format(DateTimeInterface::ATOM),
            'end' => $range['end']->format(DateTimeInterface::ATOM),
            'startLabel' => $range['start']->format('d/m/Y H:i'),
            'endLabel' => $range['end']->format('d/m/Y H:i'),
        ];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0 && count($parts) < 2) {
            $parts[] = $minutes . 'm';
        }
        if ($parts === []) {
            $parts[] = $seconds . 's';
        }

        return implode(' ', array_slice($parts, 0, 2));
    }

    private function formatPercent(?float $percent): string
    {
        if ($percent === null) {
            return '--';
        }

        if (abs(100.0 - $percent) < 0.0005) {
            return '100%';
        }

        return number_format($percent, $percent >= 99.995 ? 3 : 2, ',', '.') . '%';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
