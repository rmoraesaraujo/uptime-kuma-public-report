<?php

declare(strict_types=1);

/**
 * Applies admin-configured presentation rules on top of the raw report produced by
 * StatsService: hidden monitors/groups, per-group aggregated vs detailed display and
 * custom group ordering. The underlying Uptime Kuma data itself is never touched -
 * this only changes what gets rendered on the public page.
 */
final class PublicViewFilter
{
    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function apply(array $report, AdminStore $store): array
    {
        $hiddenMonitors = array_fill_keys($store->hiddenMonitorIds(), true);
        $hiddenGroups = array_fill_keys($store->hiddenGroupIds(), true);
        $modes = $store->groupModes();
        $order = $store->groupOrder();

        $report['monitors'] = array_values(array_filter(
            $report['monitors'] ?? [],
            static function (array $monitor) use ($hiddenMonitors, $hiddenGroups): bool {
                if (isset($hiddenMonitors[(int) $monitor['id']])) {
                    return false;
                }
                $parent = $monitor['parent'] ?? null;
                $groupKey = $parent === null ? 'ungrouped' : (string) $parent;

                return !isset($hiddenGroups[$groupKey]);
            }
        ));
        unset($report['visibleMonitorRows']);

        $groups = [];
        foreach ($report['monitorGroups'] ?? [] as $group) {
            $groupKey = (string) $group['id'];
            if (isset($hiddenGroups[$groupKey])) {
                continue;
            }

            $monitors = array_values(array_filter(
                $group['monitors'] ?? [],
                static fn (array $monitor): bool => !isset($hiddenMonitors[(int) $monitor['id']])
            ));

            if ($monitors === []) {
                continue;
            }

            $group['monitors'] = $monitors;
            $group['total'] = count($monitors);
            $group['online'] = count(array_filter($monitors, static fn (array $m): bool => $m['status'] === 'up'));
            $group['down'] = count(array_filter($monitors, static fn (array $m): bool => $m['status'] === 'down'));

            $mode = $modes[$groupKey] ?? 'detailed';
            $group['displayMode'] = $mode;

            if ($mode === 'aggregated' && count($monitors) > 1) {
                $group['monitors'] = [$this->buildAggregateCard($group, $monitors)];
            }

            $groups[$groupKey] = $group;
        }

        $groups = $this->sortGroups($groups, $order);

        $visibleMonitorTotal = 0;
        $visibleUp = 0;
        $visibleDown = 0;
        foreach ($groups as $group) {
            $visibleMonitorTotal += (int) $group['total'];
            $visibleUp += (int) $group['online'];
            $visibleDown += (int) $group['down'];
        }

        $report['monitorGroups'] = array_values($groups);
        $report['summary']['totalGroups'] = count($groups);
        $report['summary']['totalMonitors'] = $visibleMonitorTotal;
        $report['summary']['upMonitors'] = $visibleUp;
        $report['summary']['downMonitors'] = $visibleDown;
        $report['summary']['upGroups'] = count(array_filter($groups, static fn (array $g): bool => (int) $g['down'] === 0));
        $report['summary']['downGroups'] = count(array_filter($groups, static fn (array $g): bool => (int) $g['down'] > 0));

        $report['recentIncidents'] = array_values(array_filter(
            $report['recentIncidents'] ?? [],
            static fn (array $incident): bool => !isset($hiddenMonitors[(int) ($incident['monitorId'] ?? 0)])
        ));

        $report['availableMonitorGroups'] = array_values(array_filter(array_map(
            static function (array $availableGroup) use ($hiddenMonitors, $hiddenGroups): ?array {
                if (isset($hiddenGroups[(string) $availableGroup['id']])) {
                    return null;
                }

                $availableGroup['monitors'] = array_values(array_filter(
                    $availableGroup['monitors'] ?? [],
                    static fn (array $m): bool => !isset($hiddenMonitors[(int) $m['id']])
                ));
                $availableGroup['total'] = count($availableGroup['monitors']);

                return $availableGroup['monitors'] === [] ? null : $availableGroup;
            },
            $report['availableMonitorGroups'] ?? []
        )));

        return $report;
    }

    /**
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $monitors
     * @return array<string, mixed>
     */
    private function buildAggregateCard(array $group, array $monitors): array
    {
        $down = (int) $group['down'];
        $total = (int) $group['total'];
        $status = $down > 0 ? 'down' : 'up';

        $incidentsSelected = array_sum(array_map(static fn (array $m): int => (int) ($m['incidentsSelected'] ?? 0), $monitors));
        $downtimeSelectedSeconds = array_sum(array_map(static fn (array $m): int => (int) ($m['downtimeSelectedSeconds'] ?? 0), $monitors));
        $uptimeValues = array_filter(array_map(
            static fn (array $m): ?float => is_numeric($m['uptimeSelectedRaw'] ?? null) ? (float) $m['uptimeSelectedRaw'] : null,
            $monitors
        ), static fn (?float $v): bool => $v !== null);
        $uptimeSelected = $uptimeValues === [] ? '--' : number_format(min($uptimeValues), 2, ',', '.') . '%';

        $lastEventLabel = $monitors[0]['lastEventLabel'] ?? '';
        foreach ($monitors as $monitor) {
            if (($monitor['lastEventLabel'] ?? '') > $lastEventLabel) {
                $lastEventLabel = $monitor['lastEventLabel'];
            }
        }

        return [
            'id' => 'aggregate-' . $group['id'],
            'name' => $group['name'],
            'initial' => mb_substr((string) $group['name'], 0, 1),
            'status' => $status,
            'statusLabel' => $down > 0 ? 'DOWN' : 'UP',
            'cardStatusLabel' => $down > 0
                ? sprintf('%d de %d registros indisponiveis', $down, $total)
                : 'Todos os registros operacionais',
            'groupId' => $group['id'],
            'groupName' => $group['name'],
            'incidentsSelected' => $incidentsSelected,
            'downtimeSelectedSeconds' => $downtimeSelectedSeconds,
            'downtimeSelectedLabel' => $this->formatDuration($downtimeSelectedSeconds),
            'uptimeSelected' => $uptimeSelected,
            'lastEventLabel' => $lastEventLabel,
            'historyHourBars' => $this->mergeHistoryBars(array_column($monitors, 'historyHourBars')),
            'isAggregate' => true,
            'aggregateTotal' => $total,
        ];
    }

    /**
     * @param list<list<array{status: string, label: string, title: string}>> $bars
     * @return list<array{status: string, label: string, title: string}>
     */
    private function mergeHistoryBars(array $bars): array
    {
        if ($bars === []) {
            return [];
        }

        $rank = ['down' => 3, 'partial' => 2, 'pending' => 2, 'maintenance' => 2, 'unknown' => 1, 'up' => 0];
        $bucketCount = count($bars[0]);
        $merged = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $worst = 'up';
            $label = $bars[0][$i]['label'] ?? '';
            foreach ($bars as $series) {
                $status = $series[$i]['status'] ?? 'unknown';
                if (($rank[$status] ?? 0) > ($rank[$worst] ?? 0)) {
                    $worst = $status;
                }
            }

            $merged[] = [
                'status' => $worst,
                'label' => $label,
                'title' => $label . ' - ' . ($worst === 'down' ? 'Indisponivel' : ($worst === 'up' ? 'Operacional' : 'Instavel')),
            ];
        }

        return $merged;
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, int> $order
     * @return array<string, array<string, mixed>>
     */
    private function sortGroups(array $groups, array $order): array
    {
        $keys = array_keys($groups);
        usort($keys, static function (string $a, string $b) use ($order): int {
            $posA = $order[$a] ?? null;
            $posB = $order[$b] ?? null;

            if ($posA !== null && $posB !== null) {
                return $posA <=> $posB;
            }
            if ($posA !== null) {
                return -1;
            }
            if ($posB !== null) {
                return 1;
            }

            return 0;
        });

        $sorted = [];
        foreach ($keys as $key) {
            $sorted[$key] = $groups[$key];
        }

        return $sorted;
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
}
