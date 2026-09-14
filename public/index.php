<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/DatabaseLocator.php';
require_once __DIR__ . '/../src/SqliteConnectionFactory.php';
require_once __DIR__ . '/../src/SchemaDetector.php';
require_once __DIR__ . '/../src/FileCache.php';
require_once __DIR__ . '/../src/StatsService.php';
require_once __DIR__ . '/../src/ReportBuilder.php';
require_once __DIR__ . '/../src/AdminStore.php';
require_once __DIR__ . '/../src/PublicViewFilter.php';
require_once __DIR__ . '/../src/helpers.php';

function selected(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

function status_class(string $status): string
{
    return match ($status) {
        'up' => 'status-up',
        'down' => 'status-down',
        'partial' => 'status-partial',
        'pending' => 'status-pending',
        'maintenance' => 'status-maintenance',
        default => 'status-unknown',
    };
}

function metric_tone(int $incidents, int $downtimeSeconds): string
{
    return ($incidents > 0 || $downtimeSeconds > 0) ? 'metric-bad' : 'metric-good';
}

function chart_level(?float $percent): string
{
    if ($percent === null) {
        return 'level-none';
    }
    if ($percent >= 99.5) {
        return 'level-good';
    }
    if ($percent >= 95.0) {
        return 'level-warn';
    }

    return 'level-bad';
}

function request_path(): string
{
    return parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
}

$config = Config::fromEnvironment();
date_default_timezone_set($config->appTimezone);

$adminStore = new AdminStore($config->adminDataPath);
$viewSettings = $adminStore->getSettings();

$allowedPeriods = ['today', '7d', '30d'];
$allowedStatuses = ['all', 'up', 'down', 'pending', 'maintenance', 'unknown'];
$requestedPeriod = (string) ($_GET['period'] ?? $viewSettings['default_period']);
$requestedStatus = (string) ($_GET['status'] ?? $viewSettings['default_status']);
$filters = [
    'monitor' => trim((string) ($_GET['monitor'] ?? $viewSettings['default_monitor'])),
    'period' => in_array($requestedPeriod, $allowedPeriods, true) ? $requestedPeriod : '7d',
    'status' => in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : 'all',
];

try {
    $report = (new ReportBuilder($config))->build($filters);
    $report = (new PublicViewFilter())->apply($report, $adminStore);
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
        <link rel="stylesheet" href="<?= e(asset_url('/assets/styles.css')) ?>">
    </head>
    <body>
        <main class="error-shell">
            <section class="error-panel">
                <span class="eyebrow">Relatorio indisponivel</span>
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
$statusText = ((int) ($summary['downGroups'] ?? 0)) > 0 ? 'Instabilidade detectada' : 'Todos os sistemas operacionais';
$globalStatus = ((int) ($summary['downGroups'] ?? 0)) > 0 ? 'down' : 'up';
$currentPeriod = $report['filters']['period'];
$currentStatus = $report['filters']['status'];
$currentMonitor = $report['filters']['monitor'];
$metricSuffix = match ($currentPeriod) {
    'today' => 'hoje',
    '7d' => 'em 7 dias',
    '30d' => 'em 30 dias',
    default => 'no periodo',
};
$offlineGroup = null;
foreach ($report['monitorGroups'] as $group) {
    if ((int) ($group['down'] ?? 0) > 0) {
        $offlineGroup = $group;
        break;
    }
}
$offlineHref = $offlineGroup === null
    ? '/?monitor=all&period=' . rawurlencode($currentPeriod) . '&status=all'
    : '/?monitor=' . rawurlencode('group:' . $offlineGroup['id']) . '&period=' . rawurlencode($currentPeriod) . '&status=all';

$layoutDensity = in_array($viewSettings['layout_density'] ?? 'comfortable', ['comfortable', 'compact'], true)
    ? $viewSettings['layout_density']
    : 'comfortable';
$accentColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($viewSettings['accent_color'] ?? '')) === 1
    ? $viewSettings['accent_color']
    : '#4f8cff';
$popupEnabled = ($viewSettings['popup_enabled'] ?? '1') === '1';
$popupDurationMs = max(2000, (int) ($viewSettings['popup_duration_ms'] ?? 6000));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($report['title']) ?></title>
    <meta name="robots" content="index,follow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/styles.css')) ?>">
    <style>:root { --accent: <?= e($accentColor) ?>; --accent-soft: <?= e($accentColor) ?>24; }</style>
</head>
<body class="density-<?= e($layoutDensity) ?>"
    data-popup-enabled="<?= $popupEnabled ? '1' : '0' ?>"
    data-popup-duration="<?= e($popupDurationMs) ?>"
    data-stats-url="/api/stats?monitor=<?= e(rawurlencode($currentMonitor)) ?>&period=<?= e($currentPeriod) ?>&status=<?= e($currentStatus) ?>"
>
    <div class="topbar">
        <div class="wrap topbar-inner">
            <a class="brand" href="/">
                <span class="brand-eyebrow">RB PLAY &middot; Central operacional</span>
                <span class="brand-title"><?= e($report['title']) ?></span>
            </a>
            <div class="global-badge <?= e($globalStatus) ?>">
                <span class="dot" aria-hidden="true"></span>
                <span><?= e($statusText) ?></span>
            </div>
            <div class="updated-at">
                <div>Atualizado em <?= e($report['generatedAtLabel']) ?></div>
                <div><?= e($report['ranges']['selected']['label']) ?></div>
            </div>
        </div>
    </div>

    <div class="popup-stack" id="popup-stack" aria-live="polite"></div>

    <main class="wrap">
        <div class="stat-grid" aria-label="Resumo dos servidores">
            <a class="stat-tile" href="/?monitor=all&period=<?= e($currentPeriod) ?>&status=all">
                <span class="value"><?= e($summary['totalGroups'] ?? 0) ?></span>
                <span class="label">Servidores</span>
            </a>
            <a class="stat-tile" href="/?monitor=all&period=<?= e($currentPeriod) ?>&status=all">
                <span class="value"><?= e($summary['totalMonitors'] ?? 0) ?></span>
                <span class="label">Monitores</span>
            </a>
            <a class="stat-tile accent-up" href="/?monitor=all&period=<?= e($currentPeriod) ?>&status=up">
                <span class="value"><?= e($summary['upGroups'] ?? 0) ?></span>
                <span class="label">Servidores online</span>
            </a>
            <a class="stat-tile accent-down" href="<?= e($offlineHref) ?>">
                <span class="value"><?= e($summary['downGroups'] ?? 0) ?></span>
                <span class="label">Servidores offline</span>
            </a>
            <div class="stat-tile accent-blue">
                <span class="value"><?= e($summary['uptimeSelected'] ?? '--') ?></span>
                <span class="label">Uptime <?= e($metricSuffix) ?></span>
            </div>
            <div class="stat-tile">
                <span class="value"><?= e($summary['incidentsToday'] ?? 0) ?></span>
                <span class="label">Incidentes hoje</span>
            </div>
        </div>

        <section class="toolbar" aria-label="Filtros do relatorio">
            <form id="filters" class="filter-form" method="get" action="/">
                <label>
                    <span>Monitor ou grupo</span>
                    <select name="monitor">
                        <option value="all"<?= selected($currentMonitor, 'all') ?>>Todos os monitores</option>
                        <?php if (($report['availableMonitorGroups'] ?? []) !== []): ?>
                            <optgroup label="Grupos">
                                <?php foreach ($report['availableMonitorGroups'] as $group): ?>
                                    <option value="<?= e($group['value']) ?>"<?= selected($currentMonitor, (string) $group['value']) ?>>
                                        Grupo: <?= e($group['name']) ?> (<?= e($group['total']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php foreach (($report['availableMonitorGroups'] ?? $report['monitorGroups']) as $group): ?>
                            <optgroup label="<?= e($group['name']) ?>">
                                <?php foreach ($group['monitors'] as $monitor): ?>
                                    <option value="<?= e($monitor['id']) ?>"<?= selected($currentMonitor, (string) $monitor['id']) ?>>
                                        <?= e($monitor['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
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
                <div><?= e($summary['totalMonitors'] ?? 0) ?> monitores exibidos</div>
            </div>
        </section>

        <?php if (($report['series'] ?? []) !== []): ?>
            <section class="section">
                <div class="section-head">
                    <h2>Uptime diario</h2>
                    <span class="hint">Passe o mouse sobre uma barra para ver os detalhes do dia</span>
                </div>
                <div class="chart-card">
                    <div class="chart-bars">
                        <?php foreach ($report['series'] as $day): ?>
                            <?php
                                $tooltip = $day['label'] . ' - Uptime ' . $day['uptimeLabel']
                                    . ' - ' . $day['incidents'] . ' incidente(s)'
                                    . ' - Indisponivel ' . $day['downtimeLabel'];
                            ?>
                            <div class="chart-col">
                                <span
                                    class="chart-bar <?= e(chart_level($day['uptimePercent'])) ?>"
                                    style="height: <?= e(max(3, (int) round($day['barHeight']))) ?>%"
                                    data-tooltip="<?= e($tooltip) ?>"
                                    aria-label="<?= e($tooltip) ?>"
                                ></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="chart-labels">
                        <?php foreach ($report['series'] as $day): ?>
                            <span><?= e($day['label']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="section">
            <div class="section-head">
                <h2>Incidentes recentes</h2>
                <span class="hint"><?= e(count($report['recentIncidents'] ?? [])) ?> registrado(s) no periodo</span>
            </div>
            <?php
                $incidentVisibleCount = 6;
                $incidentTotal = count($report['recentIncidents'] ?? []);
            ?>
            <div class="incident-list" id="incident-list" data-visible-count="<?= e($incidentVisibleCount) ?>">
                <?php if (($report['recentIncidents'] ?? []) === []): ?>
                    <div class="incident-empty">Nenhum incidente registrado no periodo selecionado.</div>
                <?php else: ?>
                    <?php foreach ($report['recentIncidents'] as $index => $incident): ?>
                        <div class="incident-row<?= $incident['ongoing'] ? ' is-ongoing' : '' ?><?= $index >= $incidentVisibleCount ? ' is-extra' : '' ?>">
                            <span class="incident-monitor"><?= e($incident['monitorName']) ?></span>
                            <span class="incident-times">
                                <span class="incident-time-item down">
                                    <span class="incident-time-label">Caiu</span>
                                    <span class="incident-time-value"><?= e($incident['downAtLabel']) ?></span>
                                </span>
                                <span class="incident-arrow" aria-hidden="true">&rarr;</span>
                                <span class="incident-time-item <?= $incident['ongoing'] ? 'ongoing' : 'up' ?>">
                                    <span class="incident-time-label">Voltou</span>
                                    <span class="incident-time-value"><?= e($incident['upAtLabel']) ?></span>
                                </span>
                            </span>
                            <span class="pill <?= $incident['ongoing'] ? 'status-down' : 'status-up' ?>">
                                <span class="dot" aria-hidden="true"></span>
                                <?= e($incident['durationLabel']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($incidentTotal > $incidentVisibleCount): ?>
                <button type="button" class="toggle-list" data-target="incident-list" data-count="<?= e($incidentTotal - $incidentVisibleCount) ?>">
                    Mostrar mais <?= e($incidentTotal - $incidentVisibleCount) ?> incidente(s)
                </button>
            <?php endif; ?>
        </section>

        <section class="section">
            <div class="section-head">
                <h2>Monitores</h2>
                <span class="hint">Agrupados por servidor</span>
            </div>

            <?php if ($report['monitorGroups'] === []): ?>
                <div class="empty-state">Nenhum monitor corresponde aos filtros atuais.</div>
            <?php else: ?>
                <?php foreach ($report['monitorGroups'] as $group): ?>
                    <?php
                        $groupHasAlert = (int) ($group['down'] ?? 0) > 0;
                        $groupStateClass = $groupHasAlert ? 'group-alert' : 'group-ok';
                    ?>
                    <details class="monitor-group <?= e($groupStateClass) ?>"<?= $groupHasAlert ? ' open' : '' ?>>
                        <summary class="group-heading" id="group-<?= e($group['id']) ?>">
                            <span class="group-chevron" aria-hidden="true"></span>
                            <div class="group-heading-text">
                                <h3><?= e($group['name']) ?></h3>
                                <span class="count"><?= e($group['total']) ?> monitor(es), <?= e($group['online']) ?> online</span>
                            </div>
                            <span class="pill <?= e($group['down'] > 0 ? 'status-down' : 'status-up') ?>">
                                <span class="dot" aria-hidden="true"></span>
                                <?= e($group['down'] > 0 ? $group['down'] . ' em alerta' : 'Operacional') ?>
                            </span>
                        </summary>

                        <div class="monitor-card-grid">
                            <?php foreach ($group['monitors'] as $monitor): ?>
                                <?php $tone = metric_tone((int) $monitor['incidentsSelected'], (int) $monitor['downtimeSelectedSeconds']); ?>
                                <article class="server-card <?= e(status_class($monitor['status'])) ?>" data-monitor-id="<?= e($monitor['id']) ?>" data-monitor-name="<?= e($monitor['name']) ?>">
                                    <div class="card-head">
                                        <h4><?= e($monitor['name']) ?><?php if ($monitor['isAggregate'] ?? false): ?> <span class="aggregate-tag" title="Exibindo status agregado de <?= e($monitor['aggregateTotal']) ?> registros">agregado</span><?php endif; ?></h4>
                                        <span class="pill sm <?= e(status_class($monitor['status'])) ?>">
                                            <span class="dot" aria-hidden="true"></span>
                                            <?= e($monitor['statusLabel']) ?>
                                        </span>
                                    </div>

                                    <dl class="card-metrics">
                                        <div>
                                            <dt>Uptime <?= e($metricSuffix) ?></dt>
                                            <dd class="<?= e($tone) ?>"><?= e($monitor['uptimeSelected']) ?></dd>
                                        </div>
                                        <div>
                                            <dt>Incidentes</dt>
                                            <dd class="<?= e($tone) ?>"><?= e($monitor['incidentsSelected']) ?></dd>
                                        </div>
                                        <div>
                                            <dt>Tempo off</dt>
                                            <dd class="<?= e($tone) ?>"><?= e($monitor['downtimeSelectedLabel']) ?></dd>
                                        </div>
                                    </dl>

                                    <div class="history-block">
                                        <div class="history-label">Ultimas 24h</div>
                                        <div class="history-bars history-hours">
                                            <?php foreach ($monitor['historyHourBars'] as $bar): ?>
                                                <span class="history-bar <?= e(status_class($bar['status'])) ?>" data-tooltip="<?= e($bar['title']) ?>" aria-label="<?= e($bar['title']) ?>"></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <time class="card-footline" datetime="<?= e($monitor['lastEventLabel']) ?>">Ultima leitura <?= e($monitor['lastEventLabel']) ?></time>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <footer class="wrap site-footer">
        <span>Monitorado por RB PLAY</span>
        <span class="footer-right">
            Cache: <?= e($report['meta']['cacheTtl'] ?? 60) ?>s
            <a class="admin-link" href="/admin/">Painel administrativo</a>
        </span>
    </footer>

    <script src="<?= e(asset_url('/assets/app.js')) ?>" defer></script>
</body>
</html>
