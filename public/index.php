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
        'partial' => 'status-partial',
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

function asset_url(string $path): string
{
    $file = __DIR__ . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';

    return $path . '?v=' . rawurlencode($version);
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
        'report-v3',
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
        <link rel="stylesheet" href="<?= e(asset_url('/assets/styles.css')) ?>">
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
$statusText = ((int) ($summary['downGroups'] ?? 0)) > 0 ? 'Instabilidade detectada' : 'Todos os sistemas operacionais';
$globalStatus = ((int) ($summary['downGroups'] ?? 0)) > 0 ? 'down' : 'up';
$currentPeriod = $report['filters']['period'];
$currentStatus = $report['filters']['status'];
$currentMonitor = $report['filters']['monitor'];
$metricSuffix = match ($currentPeriod) {
    'today' => 'DE HOJE',
    '7d' => 'DOS ULTIMOS 7 DIAS',
    '30d' => 'DOS ULTIMOS 30 DIAS',
    default => 'DO PERIODO',
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
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($report['title']) ?></title>
    <meta name="robots" content="index,follow">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/styles.css')) ?>">
</head>
<body class="status-board">
    <header class="status-hero">
        <p class="hero-subtitle">Central operacional RB PLAY</p>
        <a class="hero-title" href="/"><?= e($report['title']) ?></a>
        <div class="global-status <?= e(status_class($globalStatus)) ?>">
            <span class="status-dot" aria-hidden="true"></span>
            <span><?= e($statusText) ?></span>
        </div>
        <div class="hero-counters" aria-label="Resumo dos servidores">
            <a href="/?monitor=all&period=<?= e($currentPeriod) ?>&status=all">
                <strong><?= e($summary['totalGroups'] ?? 0) ?></strong>
                <span>Servidores</span>
            </a>
            <a href="/?monitor=all&period=<?= e($currentPeriod) ?>&status=up">
                <strong><?= e($summary['upGroups'] ?? 0) ?></strong>
                <span>Online</span>
            </a>
            <a href="<?= e($offlineHref) ?>" title="Ver grupo com servidor offline">
                <strong><?= e($summary['downGroups'] ?? 0) ?></strong>
                <span>Offline</span>
            </a>
        </div>
    </header>

    <main class="page-shell">
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
                <span><?= e($report['ranges']['selected']['label']) ?></span>
                <span>Atualizado em <?= e($report['generatedAtLabel']) ?></span>
            </div>
        </section>

        <?php if ($report['monitorGroups'] === []): ?>
            <section class="empty-state">Nenhum monitor corresponde aos filtros atuais.</section>
        <?php else: ?>
            <?php foreach ($report['monitorGroups'] as $group): ?>
                <?php $groupTone = abs(crc32((string) $group['id'])) % 6; ?>
                <section class="monitor-group group-tone-<?= e((string) $groupTone) ?>" aria-labelledby="group-<?= e($group['id']) ?>">
                    <div class="group-heading">
                        <div>
                            <h1 id="group-<?= e($group['id']) ?>"><?= e($group['name']) ?></h1>
                            <p><?= e($group['total']) ?> monitoramentos, <?= e($group['online']) ?> online</p>
                        </div>
                        <span class="group-health <?= e($group['down'] > 0 ? 'status-down' : 'status-up') ?>">
                            <span class="status-dot" aria-hidden="true"></span>
                            <?= e($group['down'] > 0 ? $group['down'] . ' em alerta' : 'Grupo operacional') ?>
                        </span>
                    </div>

                    <div class="monitor-card-grid">
                        <?php foreach ($group['monitors'] as $monitor): ?>
                            <article class="server-card <?= e(status_class($monitor['status'])) ?>">
                                <div class="card-topline">
                                    <span class="group-chip"><?= e($monitor['groupName']) ?></span>
                                    <span class="server-avatar"><?= e($monitor['initial']) ?></span>
                                    <div class="server-identity">
                                        <span>Monitoramento</span>
                                        <h2><?= e($monitor['name']) ?></h2>
                                        <time datetime="<?= e($monitor['lastEventLabel']) ?>"><?= e($monitor['lastEventLabel']) ?></time>
                                    </div>
                                </div>

                                <div class="card-status <?= e(status_class($monitor['status'])) ?>">
                                    <span class="status-dot" aria-hidden="true"></span>
                                    <?= e($monitor['cardStatusLabel']) ?>
                                </div>

                                <div class="card-divider"></div>

                                <div class="history-block">
                                    <div class="history-label">Historico por hora</div>
                                    <div class="history-bars history-hours">
                                        <?php foreach ($monitor['historyHourBars'] as $bar): ?>
                                            <span class="history-bar <?= e(status_class($bar['status'])) ?>" data-tooltip="<?= e($bar['title']) ?>" aria-label="<?= e($bar['title']) ?>"></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="history-axis">
                                        <span><?= e($monitor['historyHourBars'][0]['label'] ?? '') ?></span>
                                        <span><?= e($monitor['historyHourBars'][array_key_last($monitor['historyHourBars'])]['label'] ?? '') ?></span>
                                    </div>
                                </div>

                                <div class="history-block">
                                    <div class="history-label">Ultimos 60 minutos</div>
                                    <div class="history-bars history-minutes">
                                        <?php foreach ($monitor['historyMinuteBars'] as $bar): ?>
                                            <span class="history-bar <?= e(status_class($bar['status'])) ?>" data-tooltip="<?= e($bar['title']) ?>" aria-label="<?= e($bar['title']) ?>"></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="history-axis">
                                        <span><?= e($monitor['historyMinuteBars'][0]['label'] ?? '') ?></span>
                                        <span><?= e($monitor['historyMinuteBars'][array_key_last($monitor['historyMinuteBars'])]['label'] ?? '') ?></span>
                                    </div>
                                </div>

                                <dl class="card-metrics">
                                    <div>
                                        <dt>Uptime <?= e($metricSuffix) ?></dt>
                                        <dd><?= e($monitor['uptimeSelected']) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Incidentes <?= e($metricSuffix) ?></dt>
                                        <dd><?= e($monitor['incidentsSelected']) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Tempo off <?= e($metricSuffix) ?></dt>
                                        <dd><?= e($monitor['downtimeSelectedLabel']) ?></dd>
                                    </div>
                                </dl>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <span>Monitorado por RB PLAY</span>
        <span>Cache: <?= e($report['meta']['cacheTtl'] ?? 60) ?>s<?= ($report['_cache']['hit'] ?? false) ? ' ativo' : ' renovado' ?></span>
    </footer>

    <script src="<?= e(asset_url('/assets/app.js')) ?>" defer></script>
</body>
</html>
