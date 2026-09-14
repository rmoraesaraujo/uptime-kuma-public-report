<?php

declare(strict_types=1);

/**
 * Owns the admin panel's own writable SQLite database (separate from Uptime Kuma's
 * read-only database). Stores admin accounts, display settings, hidden
 * monitors/groups, per-group aggregation mode and group ordering.
 */
final class AdminStore
{
    private ?PDO $pdo = null;

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SETTINGS = [
        'default_period' => '7d',
        'default_status' => 'all',
        'default_monitor' => 'all',
        'popup_enabled' => '1',
        'popup_duration_ms' => '6000',
        'layout_density' => 'comfortable',
        'accent_color' => '#4f8cff',
    ];

    public function __construct(private readonly string $directory)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Nao foi possivel criar o diretorio de dados do painel admin: ' . $this->directory);
        }

        $path = $this->directory . DIRECTORY_SEPARATOR . 'admin.sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 2000');
        $this->migrate($pdo);

        $this->pdo = $pdo;

        return $pdo;
    }

    private function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS hidden_monitors (
            monitor_id INTEGER PRIMARY KEY,
            hidden_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS hidden_groups (
            group_id TEXT PRIMARY KEY,
            hidden_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS group_modes (
            group_id TEXT PRIMARY KEY,
            mode TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS group_order (
            group_id TEXT PRIMARY KEY,
            position INTEGER NOT NULL
        )');
    }

    // -- Users / auth ------------------------------------------------------

    public function hasUsers(): bool
    {
        $count = (int) $this->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();

        return $count > 0;
    }

    public function createUser(string $username, string $password): void
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (username, password_hash, created_at) VALUES (:username, :hash, :created_at)'
        );
        $statement->execute([
            ':username' => $username,
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @return array{id: int, username: string}|null
     */
    public function verifyUser(string $username, string $password): ?array
    {
        $statement = $this->pdo()->prepare('SELECT id, username, password_hash FROM users WHERE username = :username');
        $statement->execute([':username' => $username]);
        $row = $statement->fetch();

        if (!is_array($row) || !password_verify($password, (string) $row['password_hash'])) {
            return null;
        }

        return ['id' => (int) $row['id'], 'username' => (string) $row['username']];
    }

    /**
     * @return array{id: int, username: string}|null
     */
    public function findUser(int $id): ?array
    {
        $statement = $this->pdo()->prepare('SELECT id, username FROM users WHERE id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'username' => (string) $row['username']] : null;
    }

    public function updatePassword(int $userId, string $newPassword): void
    {
        $statement = $this->pdo()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $statement->execute([
            ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $userId,
        ]);
    }

    // -- Settings ------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public function getSettings(): array
    {
        $settings = self::DEFAULT_SETTINGS;
        $rows = $this->pdo()->query('SELECT key, value FROM settings')->fetchAll();

        foreach ($rows as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }

        return $settings;
    }

    public function getSetting(string $key): string
    {
        return $this->getSettings()[$key] ?? (self::DEFAULT_SETTINGS[$key] ?? '');
    }

    /**
     * @param array<string, string> $values
     */
    public function setSettings(array $values): void
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO settings (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );

        foreach ($values as $key => $value) {
            $statement->execute([':key' => (string) $key, ':value' => (string) $value]);
        }
    }

    // -- Hidden monitors / groups ------------------------------------------------------

    /**
     * @return list<int>
     */
    public function hiddenMonitorIds(): array
    {
        $rows = $this->pdo()->query('SELECT monitor_id FROM hidden_monitors')->fetchAll();

        return array_map(static fn (array $row): int => (int) $row['monitor_id'], $rows);
    }

    /**
     * @return list<string>
     */
    public function hiddenGroupIds(): array
    {
        $rows = $this->pdo()->query('SELECT group_id FROM hidden_groups')->fetchAll();

        return array_map(static fn (array $row): string => (string) $row['group_id'], $rows);
    }

    public function setMonitorHidden(int $monitorId, bool $hidden): void
    {
        if ($hidden) {
            $statement = $this->pdo()->prepare(
                'INSERT INTO hidden_monitors (monitor_id, hidden_at) VALUES (:id, :at)
                 ON CONFLICT(monitor_id) DO NOTHING'
            );
            $statement->execute([':id' => $monitorId, ':at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);

            return;
        }

        $statement = $this->pdo()->prepare('DELETE FROM hidden_monitors WHERE monitor_id = :id');
        $statement->execute([':id' => $monitorId]);
    }

    public function setGroupHidden(string $groupId, bool $hidden): void
    {
        if ($hidden) {
            $statement = $this->pdo()->prepare(
                'INSERT INTO hidden_groups (group_id, hidden_at) VALUES (:id, :at)
                 ON CONFLICT(group_id) DO NOTHING'
            );
            $statement->execute([':id' => $groupId, ':at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);

            return;
        }

        $statement = $this->pdo()->prepare('DELETE FROM hidden_groups WHERE group_id = :id');
        $statement->execute([':id' => $groupId]);
    }

    // -- Group display mode (detailed vs aggregated) ------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public function groupModes(): array
    {
        $rows = $this->pdo()->query('SELECT group_id, mode FROM group_modes')->fetchAll();
        $modes = [];

        foreach ($rows as $row) {
            $modes[(string) $row['group_id']] = (string) $row['mode'];
        }

        return $modes;
    }

    public function setGroupMode(string $groupId, string $mode): void
    {
        if ($mode === 'detailed') {
            $statement = $this->pdo()->prepare('DELETE FROM group_modes WHERE group_id = :id');
            $statement->execute([':id' => $groupId]);

            return;
        }

        $statement = $this->pdo()->prepare(
            'INSERT INTO group_modes (group_id, mode) VALUES (:id, :mode)
             ON CONFLICT(group_id) DO UPDATE SET mode = excluded.mode'
        );
        $statement->execute([':id' => $groupId, ':mode' => $mode]);
    }

    // -- Group order ------------------------------------------------------

    /**
     * @return array<string, int>
     */
    public function groupOrder(): array
    {
        $rows = $this->pdo()->query('SELECT group_id, position FROM group_order ORDER BY position ASC')->fetchAll();
        $order = [];

        foreach ($rows as $row) {
            $order[(string) $row['group_id']] = (int) $row['position'];
        }

        return $order;
    }

    /**
     * @param list<string> $orderedGroupIds
     */
    public function setGroupOrder(array $orderedGroupIds): void
    {
        $pdo = $this->pdo();
        $pdo->exec('DELETE FROM group_order');
        $statement = $pdo->prepare('INSERT INTO group_order (group_id, position) VALUES (:id, :position)');

        foreach (array_values($orderedGroupIds) as $position => $groupId) {
            $statement->execute([':id' => (string) $groupId, ':position' => $position]);
        }
    }
}
