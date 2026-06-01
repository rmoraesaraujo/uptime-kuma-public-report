<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/DatabaseLocator.php';
require_once __DIR__ . '/../src/SqliteConnectionFactory.php';
require_once __DIR__ . '/../src/SchemaDetector.php';
require_once __DIR__ . '/../src/FileCache.php';
require_once __DIR__ . '/../src/StatsService.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function selected(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

function status_class(string $status): string
{
    return match ($status) {
        'up' => 'status-up',
        'down' => 'status-down',
        'pending' => 'status-pending',
        'maintenance' => 'status-maintenance',
        default => 'status-unknown',
    };
}

function uptime_class(?float $percent): string
{
    if ($percent === null) {
        return 'uptime-unknown';
    }
    if ($percent >= 99.5) {
        return 'uptime-good';
    }
    if ($percent >= 95.0) {
        return 'uptime-warn';
    }

    return 'uptime-bad';
}

function numeric_percent(?float $percent): string
{
    return $percent === null ? '0' : number_format($percent, 2, '.', '');
}

function request_path(): string
{
    return parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
}

$config = Config::fromEnvironment();
date_default_timezone_set($config->appTimezone);

$allowedPeriods = ['today', '7d', '30d'];
$allowedStatuses = ['all', 'up', 'down', 'pending', 'maintenance', 'unknown'];
$requestedPeriod = (string) ($_GET['period'] ?? '7d');
$requestedStatus = (string) ($_GET['status'] ?? 'all');
$filters = [
    'monitor' => trim((string) ($_GET['monitor'] ?? 'all')),
    'period' => in_array($requestedPeriod, $allowedPeriods, true) ? $requestedPeriod : '7d',
    'status' => in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : 'all',
];

try {
    $locator = new DatabaseLocator();
    $database = $locator->locate($config);
    $cache = new FileCache($config->cachePath);
    $cacheKey = implode('|', [
        'report-v1',
        $database['path'],
        $config->appTimezone,
        $config->dbTimezone,
        $config->sqliteImmutable ? 'immutable' : 'ro',
        $filters['monitor'],
        $filters['period'],
        $filters['status'],
    ]);

    $report = $cache->remember($cacheKey, $config->cacheTtl, static function () use ($config, $database, $filters): array {
        $connectionFactory = new SqliteConnectionFactory();
        $pdo = $connectionFactory->openReadOnly($database['path'], $config);
        $schema = (new SchemaDetector())->detect($pdo);
        $report = (new StatsService($config))->buildReport($pdo, $schema, $filters);
        $report['meta'] = [
            'databaseSource' => $database['source'],
            'cacheTtl' => $config->cacheTtl,
        ];

        return $report;
    });
} catch (Throwable $exception) {
    if (request_path() === '/api/stats') {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Nao foi possivel gerar o relatorio publico neste momento.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(503);
    $publicTitle = $config->publicTitle;
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($publicTitle) ?></title>
        <link rel="stylesheet" href="/assets/styles.css">
    </head>
    <body>
        <main class="error-shell">
            <section class="error-panel">
                <p class="eyebrow">Relatorio indisponivel</p>
                <h1><?= e($publicTitle) ?></h1>
                <p>Nao foi possivel ler os dados publicos do Uptime Kuma agora. Verifique o volume read-only e as permissoes do container.</p>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if (request_path() === '/api/stats') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'data' => $report,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$summary = $report['summary'];
$statusText = ((int) $summary['downMonitors']) > 0 ? 'Instabilidade detectada' : 'Operacional';
$globalStatus = ((int) $summary['downMonitors']) > 0 ? 'down' : 'up';
$currentPeriod = $report['filters']['period'];
$currentStatus = $report['filters']['status'];
$currentMonitor = $report['filters']['monitor'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($report['title']) ?></title>
    <meta name="robots" content="index,follow">
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
    <header class="site-header">
        <div>
            <a class="brand" href="/">
                <span class="brand-mark" aria-hidden="true"></span>
                <span><?= e($report['title']) ?></span>
            </a>
            <p class="header-subtitle">Estatisticas publicas de incidentes por monitor</p>
        </div>
        <div class="global-status <?= e(status_class($globalStatus)) ?>">
            <span class="status-dot" aria-hidden="true"></span>
            <span><?= e($statusText) ?></span>
        </div>
    </header>

    <main class="page-shell">
        <section class="toolbar" aria-label="Filtros do relatorio">
            <form id="filters" class="filter-form" method="get" action="/">
                <label>
                    <span>Monitor</span>
                    <select name="monitor">
                        <option value="all"<?= selected($currentMonitor, 'all') ?>>Todos os monitores</option>
                        <?php foreach ($report['monitors'] as $monitor): ?>
                            <option value="<?= e($monitor['id']) ?>"<?= selected($currentMonitor, (string) $monitor['id']) ?>>
                                <?= e($monitor['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Periodo</span>
                    <select name="period">
                        <option value="today"<?= selected($currentPeriod, 'today') ?>>Hoje</option>
                        <option value="7d"<?= selected($currentPeriod, '7d') ?>>Ultimos 7 dias</option>
                        <option value="30d"<?= selected($currentPeriod, '30d') ?>>Ultimos 30 dias</option>
                    </select>
                </label>

                <label>
                    <span>Status atual</span>
                    <select name="status">
                        <option value="all"<?= selected($currentStatus, 'all') ?>>Todos</option>
                        <option value="up"<?= selected($currentStatus, 'up') ?>>UP</option>
                        <option value="down"<?= selected($currentStatus, 'down') ?>>DOWN</option>
                        <option value="pending"<?= selected($currentStatus, 'pending') ?>>Pendente</option>
                        <option value="maintenance"<?= selected($currentStatus, 'maintenance') ?>>Manutencao</option>
                        <option value="unknown"<?= selected($currentStatus, 'unknown') ?>>Desconhecido</option>
                    </select>
                </label>

                <button type="submit">Aplicar</button>
            </form>
            <div class="toolbar-meta">
                <span><?= e($report['ranges']['selected']['label']) ?></span>
                <span>Atualizado em <?= e($report['generatedAtLabel']) ?></span>
            </div>
        </section>

        <section class="summary-grid" aria-label="Resumo de incidentes">
            <article class="metric-card">
                <span class="metric-label">Incidentes hoje</span>
                <strong><?= e($summary['incidentsToday']) ?></strong>
                <small>Transicoes UP para DOWN</small>
            </article>
            <article class="metric-card">
                <span class="metric-label">Incidentes 7 dias</span>
                <strong><?= e($summary['incidents7d']) ?></strong>
                <small>Eventos consolidados</small>
            </article>
            <article class="metric-card">
                <span class="metric-label">Incidentes 30 dias</span>
                <strong><?= e($summary['incidents30d']) ?></strong>
                <small>Sem duplicar DOWN seguido</small>
            </article>
            <article class="metric-card">
                <span class="metric-label">Downtime no periodo</span>
                <strong><?= e($summary['downtimeSelectedLabel']) ?></strong>
                <small><?= e($report['ranges']['selected']['startLabel']) ?> ate agora</small>
            </article>
            <article class="metric-card">
                <span class="metric-label">Uptime hoje</span>
                <strong><?= e($summary['uptimeToday']) ?></strong>
                <small>Media dos monitores filtrados</small>
            </article>
            <article class="metric-card">
                <span class="metric-label">Uptime 7 dias</span>
                <strong><?= e($summary['uptime7d']) ?></strong>
                <small>Disponibilidade semanal</small>
            </article>
            <article class="metric-card">
                <span class="metric-label">Uptime 30 dias</span>
                <strong><?= e($summary['uptime30d']) ?></strong>
                <small>Disponibilidade mensal</small>
            </article>
            <article class="metric-card metric-status">
                <span class="metric-label">Monitores exibidos</span>
                <strong><?= e($summary['totalMonitors']) ?></strong>
                <small><?= e($summary['downMonitors']) ?> em DOWN agora</small>
            </article>
        </section>

        <section class="content-grid">
            <article class="panel chart-panel">
                <div class="panel-header">
                    <div>
                        <h1>Uptime percentual diario</h1>
                        <p><?= e($summary['uptimeSelected']) ?> no periodo filtrado</p>
                    </div>
                </div>

                <?php if ($report['series'] === []): ?>
                    <div class="empty-state">Nenhum dado publico encontrado para os filtros atuais.</div>
                <?php else: ?>
                    <div class="chart" role="img" aria-label="Grafico de uptime diario">
                        <?php foreach ($report['series'] as $point): ?>
                            <?php $percent = $point['uptimePercent']; ?>
                            <div class="chart-column">
                                <span class="chart-value"><?= e($point['uptimeLabel']) ?></span>
                                <div class="bar-track">
                                    <span
                                        class="bar-fill <?= e(uptime_class($percent)) ?>"
                                        style="height: <?= e($point['barHeight']) ?>%"
                                        title="<?= e($point['label'] . ' - ' . $point['uptimeLabel'] . ' uptime, downtime ' . $point['downtimeLabel']) ?>"
                                    ></span>
                                </div>
                                <span class="chart-label"><?= e($point['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <article class="panel incidents-panel">
                <div class="panel-header">
                    <div>
                        <h1>Incidentes recentes</h1>
                        <p>Somente transicoes UP para DOWN</p>
                    </div>
                </div>

                <?php if ($report['recentIncidents'] === []): ?>
                    <div class="empty-state">Nenhum incidente no periodo selecionado.</div>
                <?php else: ?>
                    <ol class="incident-list">
                        <?php foreach ($report['recentIncidents'] as $incident): ?>
                            <?php
                                $monitorName = 'Monitor #' . $incident['monitorId'];
                                foreach ($report['monitors'] as $monitor) {
                                    if ((int) $monitor['id'] === (int) $incident['monitorId']) {
                                        $monitorName = $monitor['name'];
                                        break;
                                    }
                                }
                            ?>
                            <li>
                                <span class="incident-dot" aria-hidden="true"></span>
                                <div>
                                    <strong><?= e($monitorName) ?></strong>
                                    <small><?= e($incident['atLabel']) ?></small>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </article>
        </section>

        <section class="panel table-panel">
            <div class="panel-header">
                <div>
                    <h1>Incidentes por monitor</h1>
                    <p>Periodo: <?= e($report['ranges']['selected']['label']) ?></p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Monitor</th>
                            <th>Status</th>
                            <th>Incidentes</th>
                            <th>Downtime</th>
                            <th>Uptime</th>
                            <th>Hoje</th>
                            <th>7 dias</th>
                            <th>30 dias</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($report['visibleMonitorRows'] === []): ?>
                            <tr>
                                <td colspan="8" class="table-empty">Nenhum monitor corresponde aos filtros atuais.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report['visibleMonitorRows'] as $row): ?>
                                <tr>
                                    <td>
                                        <div class="monitor-name">
                                            <strong><?= e($row['name']) ?></strong>
                                            <?php if ($row['active'] === false): ?>
                                                <small>Pausado</small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-pill <?= e(status_class($row['status'])) ?>">
                                            <span class="status-dot" aria-hidden="true"></span>
                                            <?= e($row['statusLabel']) ?>
                                        </span>
                                    </td>
                                    <td><?= e($row['incidentsSelected']) ?></td>
                                    <td><?= e($row['downtimeSelectedLabel']) ?></td>
                                    <td><?= e($row['uptimeSelected']) ?></td>
                                    <td><?= e($row['incidentsToday']) ?></td>
                                    <td><?= e($row['incidents7d']) ?></td>
                                    <td><?= e($row['incidents30d']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <span>Leitura publica em modo somente leitura. URLs, tokens, senhas e configuracoes internas nao sao exibidos.</span>
        <span>Cache: <?= e($report['meta']['cacheTtl'] ?? 60) ?>s<?= ($report['_cache']['hit'] ?? false) ? ' ativo' : ' renovado' ?></span>
    </footer>

    <script src="/assets/app.js" defer></script>
</body>
</html>
