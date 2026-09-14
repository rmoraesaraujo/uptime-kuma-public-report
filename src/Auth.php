<?php

declare(strict_types=1);

final class Auth
{
    public function __construct(private readonly AdminStore $store)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/admin',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
        session_name('kuma_admin_session');
        session_start();
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['admin_user_id']) && $this->store->findUser((int) $_SESSION['admin_user_id']) !== null;
    }

    public function currentUsername(): ?string
    {
        if (!isset($_SESSION['admin_user_id'])) {
            return null;
        }

        $user = $this->store->findUser((int) $_SESSION['admin_user_id']);

        return $user['username'] ?? null;
    }

    public function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = $userId;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public function checkCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
