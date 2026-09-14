<?php

declare(strict_types=1);

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string
    {
        $file = dirname(__DIR__) . '/public' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return $path . '?v=' . rawurlencode($version);
    }
}
