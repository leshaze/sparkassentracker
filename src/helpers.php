<?php
declare(strict_types=1);

/** HTML-Escaping */
function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 1234.5 → "1.234,50 €" */
function fmt_eur(float $v, bool $sign = false): string
{
    $s = number_format(abs($v), 2, ',', '.') . ' €';
    if ($v < 0) {
        return '−' . $s;
    }
    return ($sign && $v > 0 ? '+' : '') . $s;
}

/** "2026-05-12" → "12.05.2026" */
function fmt_date(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $d = substr($iso, 0, 10);
    [$y, $m, $dd] = array_pad(explode('-', $d), 3, '');
    return "$dd.$m.$y";
}

function fmt_pct(float $v, bool $sign = true): string
{
    return ($sign && $v > 0 ? '+' : '') . number_format($v, 1, ',', '.') . ' %';
}

const MONATE = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
    'August', 'September', 'Oktober', 'November', 'Dezember'];
const MONATE_KURZ = ['', 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

/** "2026-05" → "Mai 2026" */
function fmt_month(string $ym, bool $short = false): string
{
    [$y, $m] = explode('-', $ym);
    return ($short ? MONATE_KURZ[(int) $m] : MONATE[(int) $m]) . ' ' . ($short ? substr($y, 2) : $y);
}

function amount_class(float $v): string
{
    return $v < 0 ? 'neg' : ($v > 0 ? 'pos' : '');
}

function url(string $page, array $params = []): string
{
    $params = array_merge(['p' => $page], $params);
    return '?' . http_build_query($params);
}

function redirect(string $page, array $params = []): void
{
    header('Location: ' . url($page, $params));
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden.');
    }
}

/** CSRF + Rücksprung auf die aktuelle Seite inkl. Filter/Sortierung. */
function form_fields(string $action): string
{
    $q = $_GET;
    return csrf_field()
        . '<input type="hidden" name="a" value="' . h($action) . '">'
        . '<input type="hidden" name="back" value="' . h($q['p'] ?? 'uebersicht') . '">'
        . '<input type="hidden" name="back_q" value="' . h(http_build_query($q)) . '">';
}

/** Neutralisiert Zellinhalte, die eine Tabellenkalkulation als Formel interpretieren würde. */
function csv_safe(string $v): string
{
    return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Rendert eine View-Datei innerhalb des Layouts. */
function render(string $view, array $vars = []): void
{
    extract($vars);
    ob_start();
    require APP_ROOT . "/views/$view.php";
    $content = ob_get_clean();
    require APP_ROOT . '/views/layout.php';
}

function json_attr($data): string
{
    return h(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

function median(array $values): float
{
    if (!$values) {
        return 0.0;
    }
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);
    return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

function days_between(string $a, string $b): int
{
    return (int) round((strtotime(substr($b, 0, 10)) - strtotime(substr($a, 0, 10))) / 86400);
}
