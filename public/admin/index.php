<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/DatabaseLocator.php';
require_once __DIR__ . '/../../src/SqliteConnectionFactory.php';
require_once __DIR__ . '/../../src/SchemaDetector.php';
require_once __DIR__ . '/../../src/FileCache.php';
require_once __DIR__ . '/../../src/StatsService.php';
require_once __DIR__ . '/../../src/ReportBuilder.php';
require_once __DIR__ . '/../../src/AdminStore.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/helpers.php';

function status_class_admin(string $status): string
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

$config = Config::fromEnvironment();
date_default_timezone_set($config->appTimezone);

$store = new AdminStore($config->adminDataPath);
$auth = new Auth($store);
$auth->start();

$errors = [];
$notice = null;

// ---------------------------------------------------------------------------
// First-run setup wizard: create the initial admin account.
// ---------------------------------------------------------------------------
if (!$store->hasUsers()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if (mb_strlen($username) < 3) {
            $errors[] = 'Usuario deve ter ao menos 3 caracteres.';
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'Senha deve ter ao menos 8 caracteres.';
        }
        if ($password !== $confirm) {
            $errors[] = 'As senhas nao coincidem.';
        }

        if ($errors === []) {
            $store->createUser($username, $password);
            $user = $store->verifyUser($username, $password);
            if ($user !== null) {
                $auth->login($user['id']);
                header('Location: /admin/');
                exit;
            }
        }
    }

    render_setup_page($errors);
    exit;
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!$auth->isLoggedIn()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $user = $store->verifyUser($username, $password);

        if ($user === null) {
            $errors[] = 'Usuario ou senha invalidos.';
        } else {
            $auth->login($user['id']);
            header('Location: /admin/');
            exit;
        }
    }

    render_login_page($errors);
    exit;
}

// ---------------------------------------------------------------------------
// Authenticated actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $csrfOk = $auth->checkCsrf($_POST['csrf'] ?? null);

    if (!$csrfOk) {
        $errors[] = 'Sessao expirada, tente novamente.';
    } elseif ($action === 'logout') {
        $auth->logout();
        header('Location: /admin/');
        exit;
    } elseif ($action === 'save_settings') {
        $period = in_array($_POST['default_period'] ?? '', ['today', '7d', '30d'], true) ? $_POST['default_period'] : '7d';
        $status = in_array($_POST['default_status'] ?? '', ['all', 'up', 'down', 'pending', 'maintenance', 'unknown'], true) ? $_POST['default_status'] : 'all';
        $density = in_array($_POST['layout_density'] ?? '', ['comfortable', 'compact'], true) ? $_POST['layout_density'] : 'comfortable';
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['accent_color'] ?? '')) === 1 ? $_POST['accent_color'] : '#4f8cff';
        $popupEnabled = ($_POST['popup_enabled'] ?? '') === '1' ? '1' : '0';
        $popupDuration = max(2000, min(30000, (int) ($_POST['popup_duration_ms'] ?? 6000)));
        $requestedMonitor = trim((string) ($_POST['default_monitor'] ?? 'all'));
        $defaultMonitor = ($requestedMonitor === 'all' || str_starts_with($requestedMonitor, 'group:'))
            ? $requestedMonitor
            : 'all';
        $incidentsPlacement = ($_POST['incidents_placement'] ?? '') === 'per_card' ? 'per_card' : 'global';

        $store->setSettings([
            'default_period' => $period,
            'default_status' => $status,
            'default_monitor' => $defaultMonitor,
            'layout_density' => $density,
            'accent_color' => $accent,
            'popup_enabled' => $popupEnabled,
            'popup_duration_ms' => (string) $popupDuration,
            'incidents_placement' => $incidentsPlacement,
            'text_brand_eyebrow' => trim((string) ($_POST['text_brand_eyebrow'] ?? '')) ?: 'RB PLAY - Central operacional',
            'text_footer' => trim((string) ($_POST['text_footer'] ?? '')) ?: 'Monitorado por RB PLAY',
            'text_status_ok' => trim((string) ($_POST['text_status_ok'] ?? '')) ?: 'Todos os sistemas operacionais',
            'text_status_down' => trim((string) ($_POST['text_status_down'] ?? '')) ?: 'Instabilidade detectada',
        ]);
        $notice = 'Configuracoes de exibicao salvas.';
    } elseif ($action === 'save_announcement') {
        $enabled = ($_POST['announcement_enabled'] ?? '') === '1' ? '1' : '0';
        $text = trim((string) ($_POST['announcement_text'] ?? ''));
        $duration = max(2000, min(60000, (int) ($_POST['announcement_duration_ms'] ?? 8000)));
        $mode = ($_POST['announcement_mode'] ?? '') === 'always' ? 'always' : 'once_per_session';

        $store->setSettings([
            'announcement_enabled' => $enabled,
            'announcement_text' => $text,
            'announcement_duration_ms' => (string) $duration,
            'announcement_mode' => $mode,
            'announcement_version' => (string) time(),
        ]);
        $notice = 'Aviso em tela cheia atualizado.';
    } elseif ($action === 'save_whatsapp') {
        $enabled = ($_POST['whatsapp_enabled'] ?? '') === '1' ? '1' : '0';
        $number = preg_replace('/\D+/', '', (string) ($_POST['whatsapp_number'] ?? '')) ?? '';
        $message = trim((string) ($_POST['whatsapp_message'] ?? ''));

        $store->setSettings([
            'whatsapp_enabled' => $enabled,
            'whatsapp_number' => $number,
            'whatsapp_message' => $message,
        ]);
        $notice = 'Configuracoes do WhatsApp salvas.';
    } elseif ($action === 'save_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        $username = $auth->currentUsername() ?? '';
        $verified = $store->verifyUser($username, $current);

        if ($verified === null) {
            $errors[] = 'Senha atual incorreta.';
        } elseif (mb_strlen($new) < 8) {
            $errors[] = 'A nova senha deve ter ao menos 8 caracteres.';
        } elseif ($new !== $confirm) {
            $errors[] = 'As senhas nao coincidem.';
        } else {
            $store->updatePassword($verified['id'], $new);
            $notice = 'Senha atualizada com sucesso.';
        }
    } elseif ($action === 'save_display') {
        $hiddenMonitors = array_map('intval', (array) ($_POST['hidden_monitors'] ?? []));
        $hiddenGroups = array_map('strval', (array) ($_POST['hidden_groups'] ?? []));
        $groupModes = (array) ($_POST['group_mode'] ?? []);

        foreach ($store->hiddenMonitorIds() as $id) {
            $store->setMonitorHidden($id, in_array($id, $hiddenMonitors, true));
        }
        foreach ($hiddenMonitors as $id) {
            $store->setMonitorHidden($id, true);
        }
        foreach ($store->hiddenGroupIds() as $id) {
            $store->setGroupHidden($id, in_array($id, $hiddenGroups, true));
        }
        foreach ($hiddenGroups as $id) {
            $store->setGroupHidden($id, true);
        }
        foreach ($groupModes as $groupId => $mode) {
            $store->setGroupMode((string) $groupId, $mode === 'aggregated' ? 'aggregated' : 'detailed');
        }

        $notice = 'Visibilidade e modo de exibicao dos grupos atualizados.';
    } elseif ($action === 'move_group') {
        $groupId = (string) ($_POST['group_id'] ?? '');
        $direction = (string) ($_POST['direction'] ?? '');
        move_group_order($store, $groupId, $direction);
        $notice = 'Ordem dos grupos atualizada.';
    }
}

// ---------------------------------------------------------------------------
// Data for the dashboard (unfiltered - the admin always sees everything)
// ---------------------------------------------------------------------------
$rawReportError = null;
$rawReport = null;
try {
    $rawReport = (new ReportBuilder($config))->build(['monitor' => 'all', 'period' => '7d', 'status' => 'all']);
} catch (Throwable $exception) {
    $rawReportError = $exception->getMessage();
}

$settings = $store->getSettings();
$hiddenMonitorIds = $store->hiddenMonitorIds();
$hiddenGroupIds = $store->hiddenGroupIds();
$groupModes = $store->groupModes();
$groupOrder = $store->groupOrder();

$orderedGroups = [];
if ($rawReport !== null) {
    $orderedGroups = $rawReport['monitorGroups'];
    usort($orderedGroups, static function (array $a, array $b) use ($groupOrder): int {
        $posA = $groupOrder[(string) $a['id']] ?? null;
        $posB = $groupOrder[(string) $b['id']] ?? null;
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
}

render_dashboard($auth, $rawReport, $rawReportError, $settings, $hiddenMonitorIds, $hiddenGroupIds, $groupModes, $orderedGroups, $errors, $notice);
exit;

// ===========================================================================
// Helper mutations
// ===========================================================================

function move_group_order(AdminStore $store, string $groupId, string $direction): void
{
    $config = Config::fromEnvironment();
    try {
        $report = (new ReportBuilder($config))->build(['monitor' => 'all', 'period' => '7d', 'status' => 'all']);
    } catch (Throwable) {
        return;
    }

    $allIds = array_map(static fn (array $group): string => (string) $group['id'], $report['monitorGroups']);
    $order = $store->groupOrder();
    usort($allIds, static function (string $a, string $b) use ($order): int {
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

    $index = array_search($groupId, $allIds, true);
    if ($index === false) {
        return;
    }

    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapWith < 0 || $swapWith >= count($allIds)) {
        return;
    }

    [$allIds[$index], $allIds[$swapWith]] = [$allIds[$swapWith], $allIds[$index]];
    $store->setGroupOrder($allIds);
}

// ===========================================================================
// Views
// ===========================================================================

function admin_layout_start(string $title): void
{
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> &middot; Painel administrativo</title>
        <meta name="robots" content="noindex,nofollow">
        <link rel="stylesheet" href="<?= e(asset_url('/assets/styles.css')) ?>">
        <link rel="stylesheet" href="<?= e(asset_url('/assets/admin.css')) ?>">
    </head>
    <body class="admin-body">
    <?php
}

function admin_layout_end(): void
{
    ?>
    <script src="<?= e(asset_url('/assets/admin.js')) ?>" defer></script>
    </body>
    </html>
    <?php
}

/**
 * @param list<string> $errors
 */
function render_setup_page(array $errors): void
{
    admin_layout_start('Configuracao inicial');
    ?>
    <main class="admin-auth-shell">
        <form class="admin-auth-card" method="post">
            <input type="hidden" name="action" value="setup">
            <span class="admin-eyebrow">Primeiro acesso</span>
            <h1>Crie a conta administradora</h1>
            <p class="admin-hint">Essa conta controla o painel de configuracoes do relatorio publico. Guarde bem a senha.</p>
            <?php foreach ($errors as $error): ?>
                <div class="admin-alert"><?= e($error) ?></div>
            <?php endforeach; ?>
            <label>
                <span>Usuario</span>
                <input type="text" name="username" minlength="3" required autofocus>
            </label>
            <label>
                <span>Senha</span>
                <input type="password" name="password" minlength="8" required>
            </label>
            <label>
                <span>Confirmar senha</span>
                <input type="password" name="password_confirm" minlength="8" required>
            </label>
            <button type="submit">Criar conta e entrar</button>
        </form>
    </main>
    <?php
    admin_layout_end();
}

/**
 * @param list<string> $errors
 */
function render_login_page(array $errors): void
{
    admin_layout_start('Entrar');
    ?>
    <main class="admin-auth-shell">
        <form class="admin-auth-card" method="post">
            <input type="hidden" name="action" value="login">
            <span class="admin-eyebrow">Painel administrativo</span>
            <h1>Entrar</h1>
            <?php foreach ($errors as $error): ?>
                <div class="admin-alert"><?= e($error) ?></div>
            <?php endforeach; ?>
            <label>
                <span>Usuario</span>
                <input type="text" name="username" required autofocus>
            </label>
            <label>
                <span>Senha</span>
                <input type="password" name="password" required>
            </label>
            <button type="submit">Entrar</button>
        </form>
        <a class="admin-back-link" href="/">&larr; Voltar ao relatorio publico</a>
    </main>
    <?php
    admin_layout_end();
}

/**
 * @param array<string, mixed>|null $rawReport
 * @param array<string, string> $settings
 * @param list<int> $hiddenMonitorIds
 * @param list<string> $hiddenGroupIds
 * @param array<string, string> $groupModes
 * @param list<array<string, mixed>> $orderedGroups
 * @param list<string> $errors
 */
function render_dashboard(
    Auth $auth,
    ?array $rawReport,
    ?string $rawReportError,
    array $settings,
    array $hiddenMonitorIds,
    array $hiddenGroupIds,
    array $groupModes,
    array $orderedGroups,
    array $errors,
    ?string $notice
): void {
    $csrf = $auth->csrfToken();
    $hiddenMonitorSet = array_fill_keys($hiddenMonitorIds, true);
    $hiddenGroupSet = array_fill_keys($hiddenGroupIds, true);

    admin_layout_start('Painel');
    ?>
    <div class="admin-topbar">
        <div class="admin-topbar-inner">
            <div class="admin-brand">
                <span class="admin-eyebrow">RB PLAY &middot; Painel administrativo</span>
                <span class="admin-title">Configuracoes do relatorio publico</span>
            </div>
            <div class="admin-topbar-actions">
                <span class="admin-user">Ola, <?= e($auth->currentUsername() ?? '') ?></span>
                <a class="admin-ghost-link" href="/">Ver relatorio publico</a>
                <form method="post">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="admin-ghost-btn">Sair</button>
                </form>
            </div>
        </div>
    </div>

    <main class="admin-main">
        <?php foreach ($errors as $error): ?>
            <div class="admin-alert"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if ($notice !== null): ?>
            <div class="admin-notice"><?= e($notice) ?></div>
        <?php endif; ?>
        <?php if ($rawReportError !== null): ?>
            <div class="admin-alert">Nao foi possivel ler os dados do Uptime Kuma agora: <?= e($rawReportError) ?></div>
        <?php endif; ?>

        <div class="admin-tabs" data-admin-tabs>
            <button type="button" class="admin-tab is-active" data-tab-target="tab-appearance">Aparencia &amp; filtros</button>
            <button type="button" class="admin-tab" data-tab-target="tab-monitors">Monitores &amp; grupos</button>
            <button type="button" class="admin-tab" data-tab-target="tab-announce">Aviso &amp; WhatsApp</button>
            <button type="button" class="admin-tab" data-tab-target="tab-logs">Logs internos</button>
            <button type="button" class="admin-tab" data-tab-target="tab-account">Conta</button>
        </div>

        <section id="tab-appearance" class="admin-panel is-active">
            <form method="post" class="admin-card">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <h2>Filtros padrao da tela inicial</h2>
                <p class="admin-hint">Usados quando um visitante abre o relatorio sem escolher filtros na URL.</p>
                <div class="admin-grid-3">
                    <label>
                        <span>Periodo padrao</span>
                        <select name="default_period">
                            <option value="today" <?= $settings['default_period'] === 'today' ? 'selected' : '' ?>>Hoje</option>
                            <option value="7d" <?= $settings['default_period'] === '7d' ? 'selected' : '' ?>>Ultimos 7 dias</option>
                            <option value="30d" <?= $settings['default_period'] === '30d' ? 'selected' : '' ?>>Ultimos 30 dias</option>
                        </select>
                    </label>
                    <label>
                        <span>Status padrao</span>
                        <select name="default_status">
                            <?php foreach (['all' => 'Todos', 'up' => 'UP', 'down' => 'DOWN', 'pending' => 'Pendente', 'maintenance' => 'Manutencao', 'unknown' => 'Desconhecido'] as $value => $labelText): ?>
                                <option value="<?= e($value) ?>" <?= $settings['default_status'] === $value ? 'selected' : '' ?>><?= e($labelText) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Densidade dos cards</span>
                        <select name="layout_density">
                            <option value="comfortable" <?= $settings['layout_density'] === 'comfortable' ? 'selected' : '' ?>>Confortavel</option>
                            <option value="compact" <?= $settings['layout_density'] === 'compact' ? 'selected' : '' ?>>Compacta</option>
                        </select>
                    </label>
                    <label>
                        <span>Monitor/grupo padrao</span>
                        <select name="default_monitor">
                            <option value="all" <?= $settings['default_monitor'] === 'all' ? 'selected' : '' ?>>Todos</option>
                            <?php foreach ($orderedGroups as $group): ?>
                                <?php $groupValue = 'group:' . $group['id']; ?>
                                <option value="<?= e($groupValue) ?>" <?= $settings['default_monitor'] === $groupValue ? 'selected' : '' ?>>Grupo: <?= e($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <h2>Cor de destaque</h2>
                <div class="admin-grid-3">
                    <label>
                        <span>Cor de acento (marca, botoes, links)</span>
                        <input type="color" name="accent_color" value="<?= e($settings['accent_color']) ?>">
                    </label>
                </div>

                <h2>Notificacoes pop-up na tela inicial</h2>
                <p class="admin-hint">Quando um monitor ou grupo mudar de status, um pop-up aparece para quem estiver com a pagina aberta.</p>
                <div class="admin-grid-3">
                    <label class="admin-checkbox">
                        <input type="checkbox" name="popup_enabled" value="1" <?= $settings['popup_enabled'] === '1' ? 'checked' : '' ?>>
                        <span>Ativar pop-ups de notificacao</span>
                    </label>
                    <label>
                        <span>Tempo de exibicao (ms)</span>
                        <input type="number" name="popup_duration_ms" min="2000" max="30000" step="500" value="<?= e($settings['popup_duration_ms']) ?>">
                    </label>
                </div>

                <h2>Onde mostrar os incidentes</h2>
                <p class="admin-hint">
                    "Lista global" mantem a secao "Incidentes recentes" no topo da pagina, como hoje. "Dentro de cada
                    servidor" remove essa lista e o historico passa a aparecer no pop-up que abre ao clicar em cada card.
                </p>
                <div class="admin-grid-3">
                    <label>
                        <span>Local dos incidentes</span>
                        <select name="incidents_placement">
                            <option value="global" <?= $settings['incidents_placement'] === 'global' ? 'selected' : '' ?>>Lista global (como hoje)</option>
                            <option value="per_card" <?= $settings['incidents_placement'] === 'per_card' ? 'selected' : '' ?>>Dentro de cada servidor (ao clicar)</option>
                        </select>
                    </label>
                </div>

                <h2>Textos da pagina</h2>
                <div class="admin-grid-3">
                    <label>
                        <span>Texto acima do titulo</span>
                        <input type="text" name="text_brand_eyebrow" value="<?= e($settings['text_brand_eyebrow']) ?>" maxlength="120">
                    </label>
                    <label>
                        <span>Texto do rodape</span>
                        <input type="text" name="text_footer" value="<?= e($settings['text_footer']) ?>" maxlength="160">
                    </label>
                    <label>
                        <span>Status: tudo operacional</span>
                        <input type="text" name="text_status_ok" value="<?= e($settings['text_status_ok']) ?>" maxlength="120">
                    </label>
                    <label>
                        <span>Status: instabilidade</span>
                        <input type="text" name="text_status_down" value="<?= e($settings['text_status_down']) ?>" maxlength="120">
                    </label>
                </div>

                <button type="submit" class="admin-primary-btn">Salvar configuracoes</button>
            </form>
        </section>

        <section id="tab-announce" class="admin-panel">
            <form method="post" class="admin-card">
                <input type="hidden" name="action" value="save_announcement">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <h2>Aviso em tela cheia</h2>
                <p class="admin-hint">
                    Cobre a tela inteira por um tempo definido e some sozinho (o visitante tambem pode clicar em
                    "Pular"). Se a mensagem mudar, ela volta a aparecer para todo mundo.
                </p>
                <label class="admin-checkbox">
                    <input type="checkbox" name="announcement_enabled" value="1" <?= $settings['announcement_enabled'] === '1' ? 'checked' : '' ?>>
                    <span>Ativar aviso em tela cheia</span>
                </label>
                <label style="margin-top: 12px;">
                    <span>Mensagem</span>
                    <textarea name="announcement_text" rows="4" maxlength="600" placeholder="Ex: Manutencao programada hoje as 22h..."><?= e($settings['announcement_text']) ?></textarea>
                </label>
                <div class="admin-grid-3">
                    <label>
                        <span>Tempo em tela (ms)</span>
                        <input type="number" name="announcement_duration_ms" min="2000" max="60000" step="500" value="<?= e($settings['announcement_duration_ms']) ?>">
                    </label>
                    <label>
                        <span>Frequencia</span>
                        <select name="announcement_mode">
                            <option value="once_per_session" <?= $settings['announcement_mode'] === 'once_per_session' ? 'selected' : '' ?>>Uma vez por visita</option>
                            <option value="always" <?= $settings['announcement_mode'] === 'always' ? 'selected' : '' ?>>Sempre que a pagina carregar</option>
                        </select>
                    </label>
                </div>
                <button type="submit" class="admin-primary-btn">Salvar aviso</button>
            </form>

            <form method="post" class="admin-card">
                <input type="hidden" name="action" value="save_whatsapp">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <h2>Botao do WhatsApp</h2>
                <p class="admin-hint">Mostra um botao flutuante no canto da tela que abre uma conversa no WhatsApp.</p>
                <label class="admin-checkbox">
                    <input type="checkbox" name="whatsapp_enabled" value="1" <?= $settings['whatsapp_enabled'] === '1' ? 'checked' : '' ?>>
                    <span>Ativar botao do WhatsApp</span>
                </label>
                <div class="admin-grid-3" style="margin-top: 12px;">
                    <label>
                        <span>Numero (com DDI e DDD, so digitos)</span>
                        <input type="text" name="whatsapp_number" value="<?= e($settings['whatsapp_number']) ?>" placeholder="5511999999999" maxlength="20">
                    </label>
                    <label>
                        <span>Mensagem pre-preenchida (opcional)</span>
                        <input type="text" name="whatsapp_message" value="<?= e($settings['whatsapp_message']) ?>" maxlength="200">
                    </label>
                </div>
                <button type="submit" class="admin-primary-btn">Salvar WhatsApp</button>
            </form>
        </section>

        <section id="tab-monitors" class="admin-panel">
            <?php if ($rawReport === null): ?>
                <div class="admin-card">Nao foi possivel carregar os monitores agora.</div>
            <?php else: ?>
                <form method="post" class="admin-card">
                    <input type="hidden" name="action" value="save_display">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <h2>Visibilidade e agregacao por grupo</h2>
                    <p class="admin-hint">
                        Oculte grupos ou registros especificos do relatorio publico, ou troque um grupo para
                        <strong>agregado</strong> (mostra so um card com status geral online/offline, escondendo os
                        registros individuais do publico - eles continuam visiveis na aba "Logs internos").
                    </p>

                    <?php foreach ($orderedGroups as $index => $group): ?>
                        <?php $groupId = (string) $group['id']; ?>
                        <div class="admin-group-block">
                            <div class="admin-group-head">
                                <label class="admin-checkbox">
                                    <input type="checkbox" name="hidden_groups[]" value="<?= e($groupId) ?>" <?= isset($hiddenGroupSet[$groupId]) ? 'checked' : '' ?>>
                                    <span>Ocultar grupo inteiro</span>
                                </label>
                                <h3><?= e($group['name']) ?></h3>
                                <span class="admin-pill <?= e($group['down'] > 0 ? 'is-down' : 'is-up') ?>">
                                    <?= e($group['down']) ?>/<?= e($group['total']) ?> offline
                                </span>
                                <label class="admin-mode-select">
                                    <span>Exibicao publica</span>
                                    <select name="group_mode[<?= e($groupId) ?>]">
                                        <option value="detailed" <?= ($groupModes[$groupId] ?? 'detailed') === 'detailed' ? 'selected' : '' ?>>Detalhado (todos os registros)</option>
                                        <option value="aggregated" <?= ($groupModes[$groupId] ?? 'detailed') === 'aggregated' ? 'selected' : '' ?>>Agregado (so online/offline)</option>
                                    </select>
                                </label>
                                <div class="admin-reorder-btns">
                                    <button type="submit" form="move-<?= e($groupId) ?>-up" title="Mover para cima" <?= $index === 0 ? 'disabled' : '' ?>>&uarr;</button>
                                    <button type="submit" form="move-<?= e($groupId) ?>-down" title="Mover para baixo" <?= $index === count($orderedGroups) - 1 ? 'disabled' : '' ?>>&darr;</button>
                                </div>
                            </div>
                            <div class="admin-monitor-rows">
                                <?php foreach ($group['monitors'] as $monitor): ?>
                                    <label class="admin-monitor-row">
                                        <input type="checkbox" name="hidden_monitors[]" value="<?= e($monitor['id']) ?>" <?= isset($hiddenMonitorSet[(int) $monitor['id']]) ? 'checked' : '' ?>>
                                        <span class="admin-dot <?= e(status_class_admin($monitor['status'])) ?>"></span>
                                        <span class="admin-monitor-name"><?= e($monitor['name']) ?></span>
                                        <span class="admin-monitor-status"><?= e($monitor['statusLabel']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="admin-primary-btn">Salvar exibicao</button>
                </form>

                <?php foreach ($orderedGroups as $group): ?>
                    <?php $groupId = (string) $group['id']; ?>
                    <form id="move-<?= e($groupId) ?>-up" method="post" class="admin-hidden-form">
                        <input type="hidden" name="action" value="move_group">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="group_id" value="<?= e($groupId) ?>">
                        <input type="hidden" name="direction" value="up">
                    </form>
                    <form id="move-<?= e($groupId) ?>-down" method="post" class="admin-hidden-form">
                        <input type="hidden" name="action" value="move_group">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="group_id" value="<?= e($groupId) ?>">
                        <input type="hidden" name="direction" value="down">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section id="tab-logs" class="admin-panel">
            <div class="admin-card">
                <h2>Status real de cada registro (mesmo dentro de grupos agregados)</h2>
                <p class="admin-hint">Esta lista sempre mostra todos os registros monitorados, independente da configuracao de exibicao publica.</p>
                <?php if ($rawReport === null): ?>
                    <p>Sem dados disponiveis agora.</p>
                <?php else: ?>
                    <?php foreach ($orderedGroups as $group): ?>
                        <?php $groupId = (string) $group['id']; ?>
                        <div class="admin-log-group">
                            <h3>
                                <?= e($group['name']) ?>
                                <?php if (($groupModes[$groupId] ?? 'detailed') === 'aggregated'): ?>
                                    <span class="admin-pill is-info">agregado no publico</span>
                                <?php endif; ?>
                                <?php if (isset($hiddenGroupSet[$groupId])): ?>
                                    <span class="admin-pill is-down">oculto no publico</span>
                                <?php endif; ?>
                            </h3>
                            <table class="admin-table">
                                <thead>
                                <tr><th>Registro</th><th>Status</th><th>Incidentes (7d)</th><th>Indisponivel (7d)</th><th>Ultima leitura</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($group['monitors'] as $monitor): ?>
                                    <tr>
                                        <td>
                                            <?= e($monitor['name']) ?>
                                            <?php if (isset($hiddenMonitorSet[(int) $monitor['id']])): ?>
                                                <span class="admin-pill is-down">oculto</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="admin-dot <?= e(status_class_admin($monitor['status'])) ?>"></span> <?= e($monitor['statusLabel']) ?></td>
                                        <td><?= e($monitor['incidentsSelected']) ?></td>
                                        <td><?= e($monitor['downtimeSelectedLabel']) ?></td>
                                        <td><?= e($monitor['lastEventLabel']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>

                    <h2>Incidentes recentes (todos, incluindo ocultos)</h2>
                    <table class="admin-table">
                        <thead>
                        <tr><th>Registro</th><th>Caiu</th><th>Voltou</th><th>Duracao</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($rawReport['recentIncidents'], 0, 40) as $incident): ?>
                            <tr class="<?= $incident['ongoing'] ? 'is-ongoing' : '' ?>">
                                <td><?= e($incident['monitorName']) ?></td>
                                <td><?= e($incident['downAtLabel']) ?></td>
                                <td><?= e($incident['upAtLabel']) ?></td>
                                <td><?= e($incident['durationLabel']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>

        <section id="tab-account" class="admin-panel">
            <form method="post" class="admin-card">
                <input type="hidden" name="action" value="save_password">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <h2>Alterar senha</h2>
                <div class="admin-grid-3">
                    <label>
                        <span>Senha atual</span>
                        <input type="password" name="current_password" required>
                    </label>
                    <label>
                        <span>Nova senha</span>
                        <input type="password" name="new_password" minlength="8" required>
                    </label>
                    <label>
                        <span>Confirmar nova senha</span>
                        <input type="password" name="new_password_confirm" minlength="8" required>
                    </label>
                </div>
                <button type="submit" class="admin-primary-btn">Atualizar senha</button>
            </form>
        </section>
    </main>
    <?php
    admin_layout_end();
}
