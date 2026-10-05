<?php
declare(strict_types=1);

// Everything below runs after declare(strict_types=1) — never before (fatal error otherwise).

define('ONE_ROOT', dirname(__DIR__));
define('ONE_VERSION', '1.5.2');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'One\\')) {
        return;
    }
    $path = ONE_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require ONE_ROOT . '/src/helpers.php';

$appConfigPath = ONE_ROOT . '/config/app.php';
if (!is_file($appConfigPath)) {
    http_response_code(500);
    exit('Configurare incompletă: lipsește config/app.php (copiază config/app.example.php).');
}
$GLOBALS['ONE_CONFIG'] = require $appConfigPath;

date_default_timezone_set(config('timezone', 'Europe/Bucharest'));
mb_internal_encoding('UTF-8');

$logDir = ONE_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/app.log');
ini_set('display_errors', is_dev() ? '1' : '0');
error_reporting(E_ALL);
