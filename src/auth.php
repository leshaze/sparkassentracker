<?php
declare(strict_types=1);

/** Einfacher Passwortschutz. Hash liegt in data/auth.json. */
function auth_file(): string
{
    return cfg('data_dir') . '/auth.json';
}

function auth_configured(): bool
{
    return is_file(auth_file());
}

function auth_set_password(string $pw): void
{
    $data = ['hash' => password_hash($pw, PASSWORD_DEFAULT)];
    file_put_contents(auth_file(), json_encode($data), LOCK_EX);
    @chmod(auth_file(), 0660);
}

function auth_verify(string $pw): bool
{
    $data = json_decode((string) @file_get_contents(auth_file()), true);
    return is_array($data) && isset($data['hash']) && password_verify($pw, $data['hash']);
}

function auth_logged_in(): bool
{
    return !empty($_SESSION['auth']);
}

function auth_login(): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
}

function auth_logout(): void
{
    $_SESSION = [];
    session_destroy();
}

/** Einfache Bremse gegen Durchprobieren: Sperre nach 5 Fehlversuchen für 5 Minuten. */
function auth_throttled(): bool
{
    $f = cfg('data_dir') . '/login_attempts.json';
    $d = json_decode((string) @file_get_contents($f), true) ?: ['n' => 0, 't' => 0];
    return $d['n'] >= 5 && time() - $d['t'] < 300;
}

function auth_record_attempt(bool $ok): void
{
    $f = cfg('data_dir') . '/login_attempts.json';
    $d = json_decode((string) @file_get_contents($f), true) ?: ['n' => 0, 't' => 0];
    if ($ok) {
        $d = ['n' => 0, 't' => 0];
    } else {
        if (time() - $d['t'] >= 300) {
            $d['n'] = 0;
        }
        $d['n']++;
        $d['t'] = time();
    }
    file_put_contents($f, json_encode($d), LOCK_EX);
}
