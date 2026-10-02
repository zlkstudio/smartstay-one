<?php
declare(strict_types=1);

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['ONE_CONFIG'][$key] ?? $default;
}

function is_dev(): bool
{
    return config('env') === 'development';
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Asset URL with ?v=filemtime cache-busting. */
function asset(string $path): string
{
    $file = ONE_ROOT . '/public/' . ltrim($path, '/');
    $version = is_file($file) ? filemtime($file) : ONE_VERSION;
    return '/' . ltrim($path, '/') . '?v=' . $version;
}

function redirect(string $path, int $status = 303): never
{
    header('Location: ' . $path, true, $status);
    exit;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip(): string
{
    // Shared hosting: REMOTE_ADDR is the real client. X-Forwarded-For is spoofable, ignore it.
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function utc_plus(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

/** UTC DATETIME from the database → local (Bucharest) display string. */
function local_time(?string $utc, string $format = 'd.m.Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone(config('timezone', 'Europe/Bucharest')))->format($format);
}

function csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

/**
 * Normalizes a Romanian/international phone to digits with country code.
 * 0712 345 678 → 40712345678 · +40 712… → 40712… · 0040… → 40…
 */
function normalize_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    } elseif (str_starts_with($digits, '0')) {
        $digits = '40' . substr($digits, 1);
    }
    return $digits;
}

function greeting(): string
{
    $hour = (int) date('G');
    return match (true) {
        $hour >= 5 && $hour < 12  => 'Bună dimineața',
        $hour >= 12 && $hour < 18 => 'Bună ziua',
        default                   => 'Bună seara',
    };
}

function ro_date(?DateTimeInterface $date = null): string
{
    $date ??= new DateTimeImmutable();
    $days = ['duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă'];
    $months = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie',
        'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
    return sprintf(
        '%s, %d %s %s',
        ucfirst($days[(int) $date->format('w')]),
        (int) $date->format('j'),
        $months[(int) $date->format('n') - 1],
        $date->format('Y')
    );
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = array_map(static fn(string $p): string => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));
    return implode('', $letters) ?: '?';
}

/**
 * Renders views/{view}.php inside views/{layout}.php (or bare when $layout = null).
 * Internal variables are prefixed with __ so extract() can never collide with view data.
 */
function view(string $__view, array $__vars = [], ?string $__layout = 'layout'): never
{
    extract($__vars, EXTR_SKIP);
    ob_start();
    require ONE_ROOT . '/views/' . $__view . '.php';
    $content = (string) ob_get_clean();

    if ($__layout !== null) {
        require ONE_ROOT . '/views/' . $__layout . '.php';
    } else {
        echo $content;
    }
    exit;
}

function icon(string $name, string $class = 'icon'): string
{
    return '<svg class="' . h($class) . '" aria-hidden="true"><use href="#i-' . h($name) . '"/></svg>';
}

/** Decoded JSON request body (POST from ONE.api). Empty array when absent/invalid. */
function request_json(): array
{
    static $body = null;
    if ($body === null) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($decoded) ? $decoded : [];
    }
    return $body;
}
