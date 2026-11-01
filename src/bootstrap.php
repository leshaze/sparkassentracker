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
