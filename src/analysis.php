<?php
declare(strict_types=1);

// =============================================================================
// Filter (Konto + Zeitraum) – wird in der Session gemerkt
// =============================================================================

const PRESETS = [
    'monat'    => 'Dieser Monat',
    'vormonat' => 'Letzter Monat',
    '3m'       => 'Letzte 3 Monate',
    '6m'       => 'Letzte 6 Monate',
    '12m'      => 'Letzte 12 Monate',
    'jahr'     => 'Dieses Jahr',
    'vorjahr'  => 'Letztes Jahr',
    'alles'    => 'Gesamter Zeitraum',
    'frei'     => 'Eigener Zeitraum',
];

function current_filter(): array
{
    $f = $_SESSION['filter'] ?? ['konto' => '', 'preset' => '12m', 'von' => '', 'bis' => ''];
    if (isset($_GET['preset']) || isset($_GET['konto'])) {
        $f['konto'] = get('konto');
        $f['preset'] = array_key_exists(get('preset'), PRESETS) ? get('preset') : '12m';
        $f['von'] = parse_input_date(get('von')) ?? '';
        $f['bis'] = parse_input_date(get('bis')) ?? '';
        $_SESSION['filter'] = $f;
    }
    if ($f['konto'] !== '' && !in_array($f['konto'], konten(), true)) {
        $f['konto'] = '';
    }
    [$f['from'], $f['to']] = resolve_period($f['preset'], $f['von'], $f['bis']);
    return $f;
}

function parse_input_date(string $s): ?string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

/** @return array{0:?string,1:?string} inklusive Grenzen (Y-m-d) */
function resolve_period(string $preset, string $von, string $bis): array
{
    $t = new DateTimeImmutable('today');
    $first = $t->modify('first day of this month');
    switch ($preset) {
        case 'monat':
            return [$first->format('Y-m-d'), $t->modify('last day of this month')->format('Y-m-d')];
        case 'vormonat':
            return [$first->modify('-1 month')->format('Y-m-d'), $first->modify('-1 day')->format('Y-m-d')];
        case '3m':
            return [$first->modify('-2 months')->format('Y-m-d'), $t->format('Y-m-d')];
        case '6m':
            return [$first->modify('-5 months')->format('Y-m-d'), $t->format('Y-m-d')];
        case '12m':
            return [$first->modify('-11 months')->format('Y-m-d'), $t->format('Y-m-d')];
        case 'jahr':
            return [$t->format('Y') . '-01-01', $t->format('Y') . '-12-31'];
        case 'vorjahr':
            $y = (int) $t->format('Y') - 1;
            return ["$y-01-01", "$y-12-31"];
        case 'frei':
            return [$von ?: null, $bis ?: null];
        default:
            return [null, null];
    }
}

/**
 * WHERE-Klausel.
 *  mode 'values'   : Summen/Saldo – bei "Alle Konten" Überträge ausblenden,
 *                    bei Einzelkonto beide Seiten zeigen (korrekter Saldo)
 *  mode 'category' : Kategorie-Auswertungen – Überträge immer raus
 *  mode 'list'     : wie 'values', außer Überträge werden explizit eingeblendet
 */
function where_clause(array $f, string $mode = 'values', bool $showTransfers = false): array
{
    $w = [];
    $p = [];
    if ($f['konto'] !== '') {
        $w[] = 'konto = ?';
        $p[] = $f['konto'];
    }
    if (!empty($f['from'])) {
        $w[] = 'substr(datum,1,10) >= ?';
        $p[] = $f['from'];
    }
    if (!empty($f['to'])) {
        $w[] = 'substr(datum,1,10) <= ?';
        $p[] = $f['to'];
    }
    $hideTransfers = match ($mode) {
        'category' => true,
        'list'     => $f['konto'] === '' && !$showTransfers,
        default    => $f['konto'] === '',
    };
    if ($hideTransfers) {
        $w[] = 'ist_uebertrag = 0';
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

// =============================================================================
// Übersicht
// =============================================================================

function totals(array $f): array
{
    [$w, $p] = where_clause($f, 'values');
    $r = db_all("SELECT
            COALESCE(SUM(CASE WHEN betrag > 0 THEN betrag END),0) AS ein,
            COALESCE(SUM(CASE WHEN betrag < 0 THEN betrag END),0) AS aus,
            COUNT(*) AS n
        FROM transactions $w", $p)[0];
    return ['ein' => (float) $r['ein'], 'aus' => (float) $r['aus'], 'saldo' => (float) $r['ein'] + (float) $r['aus'], 'n' => (int) $r['n']];
}

function monthly_series(array $f): array
{
    [$w, $p] = where_clause($f, 'values');
    $rows = db_all("SELECT substr(datum,1,7) AS m,
            SUM(CASE WHEN betrag > 0 THEN betrag ELSE 0 END) AS ein,
            SUM(CASE WHEN betrag < 0 THEN -betrag ELSE 0 END) AS aus
        FROM transactions $w GROUP BY m ORDER BY m", $p);
    return array_map(fn ($r) => [
        'monat' => $r['m'],
        'ein'   => round((float) $r['ein'], 2),
        'aus'   => round((float) $r['aus'], 2),
        'saldo' => round((float) $r['ein'] - (float) $r['aus'], 2),
    ], $rows);
}

/** Ausgaben (oder Einnahmen) nach Kategorie mit Unterkategorien. */
function category_breakdown(array $f, bool $income = false): array
{
    [$w, $p] = where_clause($f, 'category');
    $w .= ($w ? ' AND ' : 'WHERE ') . ($income ? 'betrag > 0' : 'betrag < 0');
    $rows = db_all("SELECT COALESCE(NULLIF(kategorie,''),'Unbekannt') AS kat,
            COALESCE(unterkategorie,'') AS sub, SUM(betrag) AS summe, COUNT(*) AS n
        FROM transactions $w GROUP BY kat, sub", $p);
    $cats = [];
    $total = 0.0;
    foreach ($rows as $r) {
        $k = $r['kat'];
        $cats[$k] ??= ['kategorie' => $k, 'summe' => 0.0, 'n' => 0, 'subs' => []];
        $cats[$k]['summe'] += (float) $r['summe'];
        $cats[$k]['n'] += (int) $r['n'];
        if ($r['sub'] !== '') {
            $cats[$k]['subs'][] = ['name' => $r['sub'], 'summe' => (float) $r['summe'], 'n' => (int) $r['n']];
        }
        $total += (float) $r['summe'];
    }
    foreach ($cats as &$c) {
        $c['anteil'] = $total ? $c['summe'] / $total * 100 : 0;
        usort($c['subs'], fn ($a, $b) => abs($b['summe']) <=> abs($a['summe']));
    }
    unset($c);
    usort($cats, fn ($a, $b) => abs($b['summe']) <=> abs($a['summe']));
    return ['total' => $total, 'cats' => $cats];
}

// =============================================================================
// Übertragserkennung
// =============================================================================

/**
 * Findet Überträge zwischen eigenen Konten: gleicher Absolutbetrag,
 * umgekehrtes Vorzeichen, unterschiedliche Konten, Datum ≤ max_days auseinander.
 * Nächstgelegener Partner gewinnt. Gibt die Zahl neu markierter Paare zurück.
 */
function detect_transfers(): int
{
    $maxDays = (int) cfg('transfer_max_days');
    $rows = db_all("SELECT hash, konto, substr(datum,1,10) AS datum, betrag FROM transactions
        WHERE ist_uebertrag = 0 AND uebertrag_gesperrt = 0 AND betrag <> 0 AND konto <> ''");
    if (count(array_unique(array_column($rows, 'konto'))) < 2) {
        return 0;
    }

    $groups = [];
    foreach ($rows as $r) {
        $groups[sprintf('%.2f', abs((float) $r['betrag']))][] = $r;
    }

    $pairs = [];
    foreach ($groups as $g) {
        if (count($g) < 2) {
            continue;
        }
        $neg = array_values(array_filter($g, fn ($r) => $r['betrag'] < 0));
        $pos = array_values(array_filter($g, fn ($r) => $r['betrag'] > 0));
        if (!$neg || !$pos) {
            continue;
        }
        usort($neg, fn ($a, $b) => strcmp($a['datum'], $b['datum']));
        $posUsed = array_fill(0, count($pos), false);
        foreach ($neg as $n) {
            $best = -1;
            $bestDist = PHP_INT_MAX;
            foreach ($pos as $i => $pr) {
                if ($posUsed[$i] || $pr['konto'] === $n['konto']) {
                    continue;
                }
                $dist = abs(days_between($n['datum'], $pr['datum']));
                if ($dist <= $maxDays && $dist < $bestDist) {
                    $bestDist = $dist;
                    $best = $i;
                }
            }
            if ($best >= 0) {
                $posUsed[$best] = true;
                $pairs[] = [$n['hash'], $pos[$best]['hash']];
            }
        }
    }

    if ($pairs) {
        $pdo = db();
        $st = $pdo->prepare('UPDATE transactions SET ist_uebertrag = 1 WHERE hash = ?');
        $pdo->beginTransaction();
        foreach ($pairs as [$a, $b]) {
            $st->execute([$a]);
            $st->execute([$b]);
        }
        $pdo->commit();
    }
    return count($pairs);
}

/**
 * Vor dem Löschen eines Kontos: Gegenbuchungen seiner Überträge auf den anderen
 * Konten wieder zu normalen Buchungen machen – sonst blieben sie als „Übertrag“
 * ausgeblendet, obwohl das Gegenstück fehlt (z. B. Sparrate aufs Tagesgeld, wenn
 * das Tagesgeldkonto nicht mehr importiert wird). Paarung wie in detect_transfers():
 * gleicher Betrag, umgekehrtes Vorzeichen, ≤ transfer_max_days, nächstgelegener gewinnt.
 * Gibt die Zahl freigegebener Buchungen zurück.
 */
function release_transfer_partners(string $konto): int
{
    $maxDays = (int) cfg('transfer_max_days');
    $own = db_all("SELECT substr(datum,1,10) AS datum, betrag FROM transactions
        WHERE konto = ? AND ist_uebertrag = 1 ORDER BY datum", [$konto]);
    if (!$own) {
        return 0;
    }
    $others = db_all("SELECT hash, substr(datum,1,10) AS datum, betrag FROM transactions
        WHERE konto <> ? AND ist_uebertrag = 1", [$konto]);
    $used = [];
    $release = [];
    foreach ($own as $o) {
        $best = null;
        $bestDist = PHP_INT_MAX;
        foreach ($others as $x) {
            if (isset($used[$x['hash']])
                || round((float) $x['betrag'] + (float) $o['betrag'], 2) != 0.0
                || (float) $x['betrag'] == 0.0) {
                continue;
            }
            $dist = abs(days_between($o['datum'], $x['datum']));
            if ($dist <= $maxDays && $dist < $bestDist) {
                $best = $x['hash'];
                $bestDist = $dist;
            }
        }
        if ($best !== null) {
            $used[$best] = true;
            $release[] = $best;
        }
    }
    if ($release) {
        $pdo = db();
        $st = $pdo->prepare('UPDATE transactions SET ist_uebertrag = 0 WHERE hash = ?');
        $pdo->beginTransaction();
        foreach ($release as $h) {
            $st->execute([$h]);
        }
        $pdo->commit();
    }
    return count($release);
}

// =============================================================================
// Wiederkehrende Zahlungen
// =============================================================================

const FREQUENZEN = [
    'Wöchentlich'     => 7,
    'Zweiwöchentlich' => 14,
    'Monatlich'       => 30.4375,
    'Quartalsweise'   => 91.3125,
    'Halbjährlich'    => 182.625,
    'Jährlich'        => 365.25,
];
const FREQ_MODIFY = [
    'Wöchentlich' => '+1 week', 'Zweiwöchentlich' => '+2 weeks', 'Monatlich' => '+1 month',
    'Quartalsweise' => '+3 months', 'Halbjährlich' => '+6 months', 'Jährlich' => '+1 year',
];

function classify_interval(float $days): ?string
{
    return match (true) {
        $days >= 5 && $days <= 9     => 'Wöchentlich',
        $days >= 12 && $days <= 16   => 'Zweiwöchentlich',
        $days >= 25 && $days <= 36   => 'Monatlich',
        $days >= 80 && $days <= 100  => 'Quartalsweise',
        $days >= 170 && $days <= 195 => 'Halbjährlich',
        $days >= 340 && $days <= 390 => 'Jährlich',
        default => null,
    };
}

function recurring_key(string $empf, float $betrag): string
{
    return mb_strtolower(trim($empf)) . '|' . (int) round($betrag);
}

/** Erkennt regelmäßige Zahlungen pro (Empfänger, gerundeter Betrag). */
function detect_recurring(?string $today = null): array
{
    $today ??= date('Y-m-d');
    $rows = db_all("SELECT substr(datum,1,10) AS datum, betrag, empfaenger, kategorie, konto
        FROM transactions WHERE ist_uebertrag = 0 AND trim(empfaenger) <> '' ORDER BY datum");
    $groups = [];
    $payeeDates = [];
    foreach ($rows as $r) {
        $groups[recurring_key($r['empfaenger'], (float) $r['betrag'])][] = $r;
        $payeeDates[mb_strtolower(trim($r['empfaenger']))][] = $r['datum'];
    }

    $out = [];
    foreach ($groups as $key => $g) {
        $dates = array_values(array_unique(array_column($g, 'datum')));
        if (count($dates) < 2) {
            continue;
        }
        $iv = [];
        for ($i = 1; $i < count($dates); $i++) {
            $iv[] = days_between($dates[$i - 1], $dates[$i]);
        }
        $freq = classify_interval(median($iv));
        if (!$freq) {
            continue;
        }
        $fd = FREQUENZEN[$freq];
        $minCount = $fd >= 180 ? 2 : 3;
        if (count($dates) < $minCount) {
            continue;
        }
        $tol = max(3.0, $fd * 0.2);
        $regular = count(array_filter($iv, fn ($d) => abs($d - $fd) <= $tol));
        if ($regular / count($iv) < 0.6) {
            continue;
        }
        // Bei nur zwei Buchungen muss der Betrag centgenau gleich sein
        $amounts = array_map(fn ($r) => round((float) $r['betrag'], 2), $g);
        if (count($dates) < 3 && count(array_unique($amounts)) > 1) {
            continue;
        }
        // Händler mit vielen unterschiedlichen Beträgen (Supermarkt, Bäcker …) sind kein Abo:
        // im selben Zeitraum deutlich mehr Buchungen beim Empfänger als in dieser Gruppe
        $empfKey = mb_strtolower(trim($g[0]['empfaenger']));
        $first = $dates[0];
        $lastD = end($dates);
        $allAtPayee = $payeeDates[$empfKey] ?? [];
        $inSpan = count(array_filter($allAtPayee, fn ($d) => $d >= $first && $d <= $lastD));
        if ($inSpan > 2 * count($g) + 2) {
            continue;
        }
        $last3 = array_slice($g, -3);
        $betrag = array_sum(array_column($last3, 'betrag')) / count($last3);
        $last = end($dates);
        $next = (new DateTimeImmutable($last))->modify(FREQ_MODIFY[$freq])->format('Y-m-d');
        $kats = array_count_values(array_map(fn ($r) => $r['kategorie'] ?: 'Unbekannt', $g));
        arsort($kats);
        $since = days_between($last, $today);
        $out[] = [
            'key'            => $key,
            'empfaenger'     => end($g)['empfaenger'],
            'betrag'         => round($betrag, 2),
            'frequenz'       => $freq,
            'anzahl'         => count($dates),
            'letztes_datum'  => $last,
            'naechstes_datum'=> $next,
            'summe_pro_jahr' => $betrag * 365.25 / $fd,
            'kategorie'      => array_key_first($kats),
            'konto'          => end($g)['konto'],
            'aktiv'          => $since <= $fd * 2 + 5,
            'source'         => 'auto',
            'override_id'    => null,
            'notiz'          => '',
        ];
    }
    return $out;
}

/**
 * Automatisch erkannte (ohne ausgeblendete) + manuell angelegte.
 *
 * status:
 *   aktiv          – läuft
 *   ausgeblieben   – automatisch erkannt: seit > 2 Intervallen keine Buchung
 *   beendet        – vom Nutzer als gekündigt/beendet markiert
 *   wieder_gebucht – als beendet markiert, aber NACH dem Ende-Datum erneut abgebucht
 */
function recurring_list(bool $onlyActive = false, ?string $today = null): array
{
    $hidden = [];
    foreach (db_all("SELECT empfaenger, betrag FROM recurring_overrides WHERE kind = 'hidden'") as $h) {
        $hidden[recurring_key($h['empfaenger'], (float) $h['betrag'])] = true;
    }
    $ended = [];
    foreach (db_all("SELECT id, schluessel, datum FROM recurring_overrides WHERE kind = 'ended'") as $e) {
        $ended[$e['schluessel']] = $e;
    }
    $notes = [];
    foreach (db_all('SELECT schluessel, notiz FROM recurring_notes') as $n) {
        $notes[$n['schluessel']] = $n['notiz'];
    }

    $apply = function (array $r) use ($ended): array {
        $r['ende_id'] = null;
        $r['ende_datum'] = null;
        $r['status'] = $r['aktiv'] ? 'aktiv' : 'ausgeblieben';
        if (isset($ended[$r['key']])) {
            $e = $ended[$r['key']];
            $r['ende_id'] = (int) $e['id'];
            $r['ende_datum'] = $e['datum'];
            if ($r['letztes_datum'] && $e['datum'] && $r['letztes_datum'] > $e['datum']) {
                $r['status'] = 'wieder_gebucht';
                $r['aktiv'] = true;   // wird ja weiter abgebucht → zählt in Prognose
            } else {
                $r['status'] = 'beendet';
                $r['aktiv'] = false;
            }
        }
        return $r;
    };

    $list = [];
    foreach (detect_recurring($today) as $r) {
        if (isset($hidden[$r['key']])) {
            continue;
        }
        $r = $apply($r);
        if ($onlyActive && !$r['aktiv']) {
            continue;
        }
        $r['notiz'] = $notes[$r['key']] ?? '';
        $list[] = $r;
    }
    foreach (db_all("SELECT * FROM recurring_overrides WHERE kind = 'manual' ORDER BY id") as $m) {
        $freq = isset(FREQUENZEN[$m['frequenz']]) ? $m['frequenz'] : 'Monatlich';
        $b = (float) $m['betrag'];
        $r = $apply([
            'key' => 'manual:' . $m['id'], 'empfaenger' => $m['empfaenger'], 'betrag' => $b,
            'frequenz' => $freq, 'anzahl' => 0, 'letztes_datum' => null, 'naechstes_datum' => null,
            'summe_pro_jahr' => $b * 365.25 / FREQUENZEN[$freq], 'kategorie' => '—', 'konto' => '',
            'aktiv' => true, 'source' => 'manual', 'override_id' => (int) $m['id'], 'notiz' => $m['notiz'] ?? '',
        ]);
        if ($onlyActive && !$r['aktiv']) {
            continue;
        }
        $list[] = $r;
    }
    usort($list, fn ($a, $b) => abs($b['summe_pro_jahr']) <=> abs($a['summe_pro_jahr']));
    return $list;
}

/** Buchungen nach dem Ende-Datum (für den Hinweis „wieder abgebucht“). */
function bookings_after_end(array $r): array
{
    if ($r['source'] !== 'auto' || !$r['ende_datum']) {
        return [];
    }
    // Empfänger kann selbst "|" enthalten → am letzten Trenner teilen
    $pos = strrpos($r['key'], '|');
    $empf = substr($r['key'], 0, $pos);
    $rounded = substr($r['key'], $pos + 1);
    $rows = db_all("SELECT substr(datum,1,10) AS datum, betrag FROM transactions
                    WHERE ulower(trim(empfaenger)) = ? AND substr(datum,1,10) > ? AND ist_uebertrag = 0
                    ORDER BY datum", [$empf, $r['ende_datum']]);
    return array_values(array_filter($rows, fn ($x) => (int) round((float) $x['betrag']) === (int) $rounded));
}

// =============================================================================
// Monatsprognose
// =============================================================================

/**
 * Monatsprognose der Ausgaben, getrennt nach festen und variablen Ausgaben:
 *
 *  fest     = bereits gebuchte wiederkehrende Posten (erkannt über Empfänger + Betrag)
 *             + noch ausstehende wiederkehrende Posten dieses Monats
 *  variabel = alles andere; Hochrechnung gleitend gewichtet nach Monatsfortschritt:
 *             Anteil t = Tag/Monatstage aus dem bisherigen Verlauf (linear),
 *             Anteil 1−t aus dem Schnitt der variablen Ausgaben der letzten 6 Monate.
 *             Mindestens das bereits Ausgegebene.
 *
 * Die Prognose kann so nie unter „bisher + noch erwartet“ liegen. Früher wurde der
 * Gesamtbetrag (inkl. Miete & Co.) linear hochgerechnet und 50/50 gemittelt – am
 * Monatsanfang, bevor die Fixkosten abgebucht sind, lag das deutlich zu niedrig.
 */
function forecast(array $f, ?string $today = null): array
{
    $t = new DateTimeImmutable($today ?? 'today');
    $todayStr = $t->format('Y-m-d');
    $ym = $t->format('Y-m');
    $day = (int) $t->format('j');
    $dim = (int) $t->format('t');
    $monthEnd = $t->format('Y-m-t');
    $first = $t->modify('first day of this month');

    // Wiederkehrende Posten: Schlüssel für "fest", plus noch ausstehende diesen Monat
    $fixKeys = [];
    $expIn = 0.0;
    $expOut = 0.0;
    $pending = [];
    foreach (recurring_list(true, $todayStr) as $rc) {
        if ($rc['source'] !== 'auto' || !$rc['naechstes_datum']) {
            continue;
        }
        if ($f['konto'] !== '' && $rc['konto'] !== $f['konto']) {
            continue;
        }
        $fixKeys[$rc['key']] = true;
        $next = $rc['naechstes_datum'];
        // überfällig (noch nicht gebucht) oder fällig bis Monatsende
        if (substr($rc['letztes_datum'], 0, 7) !== $ym && $next <= $monthEnd && days_between($next, $todayStr) <= 10) {
            $pending[] = $rc;
            $rc['betrag'] < 0 ? $expOut += -$rc['betrag'] : $expIn += $rc['betrag'];
        }
    }

    // Ausgaben eines Zeitraums nach Monat, aufgeteilt in fest/variabel
    $split = function (string $from, string $to) use ($f, $fixKeys): array {
        [$w, $p] = where_clause(['konto' => $f['konto'], 'from' => $from, 'to' => $to], 'category');
        $out = [];
        foreach (db_all("SELECT substr(datum,1,7) AS m, empfaenger, betrag FROM transactions $w", $p) as $r) {
            $out[$r['m']] ??= ['ein' => 0.0, 'aus' => 0.0, 'fix' => 0.0];
            $b = (float) $r['betrag'];
            if ($b > 0) {
                $out[$r['m']]['ein'] += $b;
            } elseif ($b < 0) {
                $out[$r['m']]['aus'] += -$b;
                if (isset($fixKeys[recurring_key((string) $r['empfaenger'], $b)])) {
                    $out[$r['m']]['fix'] += -$b;
                }
            }
        }
        return $out;
    };

    $cur = $split("$ym-01", $monthEnd)[$ym] ?? ['ein' => 0.0, 'aus' => 0.0, 'fix' => 0.0];
    $soFarIn = $cur['ein'];
    $soFarOut = $cur['aus'];
    $fixSoFar = $cur['fix'];
    $varSoFar = $soFarOut - $fixSoFar;

    // Letzte 6 abgeschlossene Monate mit Daten
    $hist = $split($first->modify('-6 months')->format('Y-m-d'), $first->modify('-1 day')->format('Y-m-d'));
    $avg6 = $hist ? array_sum(array_column($hist, 'aus')) / count($hist) : null;
    $varAvg = $hist ? array_sum(array_map(fn ($m) => $m['aus'] - $m['fix'], $hist)) / count($hist) : null;

    $weight = $day / $dim;
    $varLinear = $varSoFar / $day * $dim;
    $varProg = $varAvg === null ? $varLinear : $weight * $varLinear + (1 - $weight) * $varAvg;
    $varProg = max($varProg, $varSoFar);

    $progOut = $fixSoFar + $expOut + $varProg;
    $progIn = $soFarIn + $expIn;

    return [
        'monat' => $ym, 'tag' => $day, 'tage' => $dim,
        'bisher_ein' => $soFarIn, 'bisher_aus' => $soFarOut,
        'erwartet_ein' => $expIn, 'erwartet_aus' => $expOut, 'ausstehend' => $pending,
        'fix_aus' => $fixSoFar + $expOut, 'variabel_bisher' => $varSoFar, 'variabel_prognose' => $varProg,
        'variabel_schnitt' => $varAvg,
        'prognose_aus' => $progOut, 'prognose_ein' => $progIn, 'prognose_saldo' => $progIn - $progOut,
        'schnitt6_aus' => $avg6, 'schnitt6_monate' => count($hist),
        'diff_pct' => $avg6 ? ($progOut - $avg6) / $avg6 * 100 : null,
    ];
}

// =============================================================================
// Insights / Sparpotenzial
// =============================================================================

function monthly_category_expenses(string $konto, string $from, string $to): array
{
    [$w, $p] = where_clause(['konto' => $konto, 'from' => $from, 'to' => $to], 'category');
    $w .= ($w ? ' AND ' : 'WHERE ') . 'betrag < 0';
    $out = [];
    foreach (db_all("SELECT substr(datum,1,7) AS m, COALESCE(NULLIF(kategorie,''),'Unbekannt') AS kat, -SUM(betrag) AS s
                     FROM transactions $w GROUP BY m, kat", $p) as $r) {
        $out[$r['kat']][$r['m']] = (float) $r['s'];
    }
    return $out;
}

function insights(array $f): array
{
    $items = [];
    $konto = $f['konto'];
    $latest = db_one('SELECT MAX(substr(datum,1,7)) FROM transactions');
    if (!$latest) {
        return [];
    }
    $curYm = date('Y-m');
    $refYm = $latest < $curYm ? $latest : $curYm;
    $ref = new DateTimeImmutable("$refYm-01");
    $histFrom = $ref->modify('-6 months')->format('Y-m-d');
    $histTo = $ref->modify('-1 day')->format('Y-m-d');
    $refTo = $ref->format('Y-m-t');

    $hist = monthly_category_expenses($konto, $histFrom, $histTo);
    $cur = monthly_category_expenses($konto, $ref->format('Y-m-d'), $refTo);
    [$w, $p] = where_clause(['konto' => $konto, 'from' => $histFrom, 'to' => $histTo], 'category');
    $histMonths = array_column(db_all("SELECT DISTINCT substr(datum,1,7) AS m FROM transactions $w", $p), 'm');
    $nHist = count($histMonths);

    // 1. Kategorien-Anstieg
    if ($nHist >= 2) {
        $rows = [];
        foreach ($cur as $kat => $byM) {
            $now = $byM[$refYm] ?? 0.0;
            $avg = array_sum($hist[$kat] ?? []) / $nHist;
            if ($avg >= 20 && $now > $avg * 1.3) {
                $pct = ($now - $avg) / $avg * 100;
                $rows[] = ['kat' => $kat, 'now' => $now, 'avg' => $avg, 'pct' => $pct];
            }
        }
        usort($rows, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        foreach ($rows as $r) {
            $items[] = [
                'sev'   => $r['pct'] >= 50 ? 'high' : 'warn',
                'title' => sprintf('%s: %s mehr als im Schnitt', $r['kat'], fmt_pct($r['pct'])),
                'details' => [
                    sprintf('%s bisher: %s', fmt_month($refYm), fmt_eur($r['now'])),
                    sprintf('Schnitt der letzten %d Monate: %s', $nHist, fmt_eur($r['avg'])),
                ],
            ];
        }
    }

    // 2. Top-5-Abos nach Jahressumme
    $rec = array_values(array_filter(recurring_list(true), fn ($r) => $r['betrag'] < 0
        && ($konto === '' || $r['source'] === 'manual' || $r['konto'] === $konto)));
    if ($rec) {
        $sumYear = -array_sum(array_column($rec, 'summe_pro_jahr'));
        $top = array_slice($rec, 0, 5);
        $items[] = [
            'sev' => 'info',
            'title' => sprintf('%d wiederkehrende Ausgaben, zusammen %s pro Jahr (%s/Monat)',
                count($rec), fmt_eur($sumYear), fmt_eur($sumYear / 12)),
            'details' => array_map(fn ($r) => sprintf('%s – %s %s → %s/Jahr', $r['empfaenger'],
                fmt_eur(-$r['betrag']), mb_strtolower($r['frequenz']), fmt_eur(-$r['summe_pro_jahr'])), $top),
        ];
    }

    // 3. Streaming-Stapel
    $streamKw = ['netflix', 'spotify', 'disney', 'prime', 'apple', 'youtube', 'dazn', 'sky', 'paramount', 'wow', 'magenta', 'rtl+', 'joyn', 'audible'];
    $streams = array_filter($rec, function ($r) use ($streamKw) {
        $e = mb_strtolower($r['empfaenger']);
        foreach ($streamKw as $k) {
            if (str_contains($e, $k)) {
                return true;
            }
        }
        return false;
    });
    if (count($streams) >= 3) {
        $sum = -array_sum(array_column($streams, 'summe_pro_jahr'));
        $items[] = [
            'sev' => 'warn',
            'title' => sprintf('%d Streaming-/Medien-Abos parallel (%s/Jahr)', count($streams), fmt_eur($sum)),
            'details' => array_map(fn ($r) => sprintf('%s – %s %s', $r['empfaenger'], fmt_eur(-$r['betrag']), mb_strtolower($r['frequenz'])), array_values($streams)),
        ];
    }

    // 4. Ausreißer: > 3× Median der Kategorie (letzte 6 Monate inkl. aktuellem)
    [$w, $p] = where_clause(['konto' => $konto, 'from' => $histFrom, 'to' => $refTo], 'category');
    $tx = db_all("SELECT substr(datum,1,10) AS datum, empfaenger, betrag, COALESCE(NULLIF(kategorie,''),'Unbekannt') AS kat
                  FROM transactions $w AND betrag < 0", $p);
    $byKat = [];
    foreach ($tx as $t) {
        $byKat[$t['kat']][] = $t;
    }
    $outliers = [];
    foreach ($byKat as $kat => $list) {
        if (count($list) < 5 || $kat === 'Unbekannt') {
            continue;
        }
        $med = median(array_map(fn ($t) => -$t['betrag'], $list));
        foreach ($list as $t) {
            if (-$t['betrag'] > 3 * $med && -$t['betrag'] >= 50) {
                $outliers[] = $t + ['med' => $med];
            }
        }
    }
    usort($outliers, fn ($a, $b) => $a['betrag'] <=> $b['betrag']);
    if ($outliers) {
        $items[] = [
            'sev' => 'info',
            'title' => sprintf('%d auffällig hohe Einzelbuchungen (über 3× Kategorie-Median)', count($outliers)),
            'details' => array_map(fn ($o) => sprintf('%s · %s · %s (%s, Median %s)', fmt_date($o['datum']),
                $o['empfaenger'] ?: '—', fmt_eur(-$o['betrag']), $o['kat'], fmt_eur($o['med'])), array_slice($outliers, 0, 10)),
        ];
    }

    // 5. Volatile Kategorien (Variationskoeffizient > 50 %)
    if ($nHist >= 3) {
        $vol = [];
        foreach ($hist as $kat => $byM) {
            $vals = array_map(fn ($m) => $byM[$m] ?? 0.0, $histMonths);
            $mean = array_sum($vals) / count($vals);
            if ($mean < 30) {
                continue;
            }
            $sd = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $vals)) / count($vals));
            if ($sd / $mean > 0.5) {
                $vol[] = ['kat' => $kat, 'mean' => $mean, 'min' => min($vals), 'max' => max($vals), 'cv' => $sd / $mean];
            }
        }
        usort($vol, fn ($a, $b) => $b['cv'] <=> $a['cv']);
        if ($vol) {
            $items[] = [
                'sev' => 'info',
                'title' => sprintf('%d Kategorien mit stark schwankenden Ausgaben', count($vol)),
                'details' => array_map(fn ($v) => sprintf('%s: Ø %s/Monat, Spanne %s – %s', $v['kat'],
                    fmt_eur($v['mean']), fmt_eur($v['min']), fmt_eur($v['max'])), $vol),
            ];
        }
    }

    $all = array_filter(recurring_list(false), fn ($r) => $konto === '' || $r['konto'] === $konto || $r['source'] === 'manual');

    // 6. Vermutlich beendete Abos (noch nicht bestätigt)
    $ended = array_filter($all, fn ($r) => $r['status'] === 'ausgeblieben'
        && days_between($r['letztes_datum'], date('Y-m-d')) <= 548);
    if ($ended) {
        $items[] = [
            'sev' => 'info',
            'title' => sprintf('%d wiederkehrende Zahlungen sind ausgeblieben – unter „Wiederkehrend“ als beendet bestätigen', count($ended)),
            'details' => array_map(fn ($r) => sprintf('%s – %s %s, zuletzt am %s', $r['empfaenger'],
                fmt_eur($r['betrag']), mb_strtolower($r['frequenz']), fmt_date($r['letztes_datum'])), array_values($ended)),
        ];
    }

    // 7. Gekündigt, aber trotzdem wieder abgebucht
    $again = array_filter($all, fn ($r) => $r['status'] === 'wieder_gebucht' && $r['betrag'] < 0);
    if ($again) {
        $details = [];
        foreach ($again as $r) {
            $after = bookings_after_end($r);
            $details[] = sprintf('%s – beendet zum %s, danach %d× abgebucht (zuletzt %s, %s)', $r['empfaenger'],
                fmt_date($r['ende_datum']), count($after), fmt_date($r['letztes_datum']),
                fmt_eur(array_sum(array_column($after, 'betrag'))));
        }
        $items[] = [
            'sev' => 'high',
            'title' => sprintf('%d gekündigte Zahlung(en) wurden trotzdem wieder abgebucht', count($again)),
            'details' => $details,
        ];
    }

    $order = ['high' => 0, 'warn' => 1, 'info' => 2];
    usort($items, fn ($a, $b) => $order[$a['sev']] <=> $order[$b['sev']]);
    return ['ref' => $refYm, 'n_hist' => $nHist, 'items' => $items];
}

// =============================================================================
// Vorjahresvergleich
// =============================================================================

function available_years(): array
{
    return array_map('intval', array_column(
        db_all("SELECT DISTINCT substr(datum,1,4) AS y FROM transactions WHERE datum IS NOT NULL ORDER BY y DESC"), 'y'));
}

function yoy(int $year, string $konto): array
{
    $prev = $year - 1;
    [$w, $p] = where_clause(['konto' => $konto, 'from' => "$prev-01-01", 'to' => "$year-12-31"], 'category');
    $rows = db_all("SELECT substr(datum,1,4) AS y, CAST(substr(datum,6,2) AS INTEGER) AS m,
            SUM(CASE WHEN betrag<0 THEN -betrag ELSE 0 END) AS aus,
            SUM(CASE WHEN betrag>0 THEN betrag ELSE 0 END) AS ein
        FROM transactions $w GROUP BY y, m", $p);
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $months[$m] = ['aus' => 0.0, 'ein' => 0.0, 'aus_vj' => 0.0, 'ein_vj' => 0.0, 'has' => false, 'has_vj' => false];
    }
    foreach ($rows as $r) {
        $suffix = (int) $r['y'] === $year ? '' : '_vj';
        $months[(int) $r['m']]['aus' . $suffix] = (float) $r['aus'];
        $months[(int) $r['m']]['ein' . $suffix] = (float) $r['ein'];
        $months[(int) $r['m']]['has' . $suffix] = true;
    }

    // Kategorien – vergleichbarer Zeitraum (laufendes Jahr: bis heute vs. gleicher Zeitraum Vorjahr)
    $isCurrent = $year === (int) date('Y');
    $cut = $isCurrent ? date('m-d') : '12-31';
    $cats = [];
    foreach ([[$year, ''], [$prev, '_vj']] as [$y, $suffix]) {
        [$w, $p] = where_clause(['konto' => $konto, 'from' => "$y-01-01", 'to' => "$y-$cut"], 'category');
        foreach (db_all("SELECT COALESCE(NULLIF(kategorie,''),'Unbekannt') AS kat, -SUM(betrag) AS s
                         FROM transactions $w AND betrag < 0 GROUP BY kat", $p) as $r) {
            $cats[$r['kat']] ??= ['kat' => $r['kat'], 'aus' => 0.0, 'aus_vj' => 0.0];
            $cats[$r['kat']]['aus' . $suffix] = (float) $r['s'];
        }
    }
    $cats = array_values($cats);
    usort($cats, fn ($a, $b) => max($b['aus'], $b['aus_vj']) <=> max($a['aus'], $a['aus_vj']));

    return [
        'year' => $year, 'prev' => $prev, 'months' => $months, 'cats' => $cats,
        'is_current' => $isCurrent, 'cut' => $cut,
        'sum_aus' => array_sum(array_column($cats, 'aus')), 'sum_aus_vj' => array_sum(array_column($cats, 'aus_vj')),
    ];
}
