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

if (!function_exists('json_payload')) {
    /**
     * Encodes a value as JSON safe to embed inside an HTML attribute (e.g. data-payload="...").
     */
    function json_payload(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
