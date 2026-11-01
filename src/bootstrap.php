<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

$GLOBALS['CONFIG'] = require APP_ROOT . '/config.php';

function cfg(string $key)
{
    return $GLOBALS['CONFIG'][$key] ?? null;
}

$dataDir = cfg('data_dir');
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0770, true);
}

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

// Basis-Pfad der App, z.B. "/" oder "/tracker/" – Cookie gilt nur dort
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

// Sicherheits-Header (keine Inline-Skripte im Projekt → strikte CSP möglich)
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

// Sessions im eigenen data/-Ordner: nicht mit anderen PHP-Apps geteilt und nicht
// vom System-Cronjob (Debian: gc_maxlifetime aus php.ini = 24 min) vorzeitig gelöscht.
$sessDir = $dataDir . '/sessions';
if (!is_dir($sessDir)) {
    mkdir($sessDir, 0770, true);
}
session_save_path($sessDir);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');

session_name('spktracker');
session_set_cookie_params([
    'lifetime' => cfg('session_lifetime'),
    'path'     => $basePath,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
ini_set('session.gc_maxlifetime', (string) cfg('session_lifetime'));
session_start();

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/rules.php';
require __DIR__ . '/csv.php';
require __DIR__ . '/analysis.php';
require __DIR__ . '/auth.php';
