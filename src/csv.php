<?php
declare(strict_types=1);

/**
 * Sparkassen-CSV-Import (Giro/Tagesgeld im CSV-CAMT-Format und Kreditkarte).
 * Format wird automatisch an den Spaltenköpfen erkannt.
 *
 * @return array{format:string, rows:array<int,array>, errors:int}
 */
function read_sparkasse_csv(string $raw): array
{
    // Encoding: UTF-8 (mit/ohne BOM) oder Windows-1252
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    } elseif (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $header = null;
    $lines = [];
    while (($row = fgetcsv($fh, 0, ';', '"', '')) !== false) {
        if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue;
        }
        if ($header === null) {
            $header = array_map(fn ($c) => trim((string) $c), $row);
            continue;
        }
        $lines[] = $row;
    }
    fclose($fh);

    if (!$header) {
        throw new RuntimeException('Die Datei ist leer.');
    }

    $lower = array_map('mb_strtolower', $header);
    $isCreditCard = (bool) array_intersect(['buchungsbetrag', 'transaktionsbeschreibung', 'belegdatum'], $lower);

    $rows = $isCreditCard ? parse_creditcard($header, $lines) : parse_giro($header, $lines);

    // Vorgemerkte Umsätze (Spalte "Info" = "Umsatz vorgemerkt") nicht übernehmen: sie stehen
    // im nächsten Export als gebuchte Umsätze, oft mit anderem Buchungstag – würden sie jetzt
    // importiert, entstünden später Dubletten.
    $booked = array_values(array_filter($rows, fn ($r) => empty($r['vorgemerkt'])));
    $pending = count($rows) - count($booked);

    $valid = array_values(array_filter($booked, fn ($r) => $r['datum'] !== null));
    usort($valid, fn ($a, $b) => strcmp($a['datum'], $b['datum']));
    return [
        'format'     => $isCreditCard ? 'Kreditkarte' : 'Giro/Tagesgeld',
        'rows'       => $valid,
        'errors'     => count($booked) - count($valid),
        'vorgemerkt' => $pending,
    ];
}

/** Spaltenindex über mehrere mögliche Namen (case-insensitiv). */
function csv_col(array $header, string ...$candidates): ?int
{
    $lookup = [];
    foreach ($header as $i => $c) {
        $lookup[mb_strtolower($c)] ??= $i;
    }
    foreach ($candidates as $cand) {
        $k = mb_strtolower($cand);
        if (isset($lookup[$k])) {
            return $lookup[$k];
        }
    }
    return null;
}

/** "12.05.26" / "12.05.2026" → "2026-05-12" */
function parse_german_date(?string $s): ?string
{
    $s = trim((string) $s);
    if ($s === '') {
        return null;
    }
    foreach (['!d.m.y', '!d.m.Y', '!Y-m-d'] as $fmt) {
        $d = DateTimeImmutable::createFromFormat($fmt, $s);
        if ($d && $d->format(substr($fmt, 1)) === $s) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

/** "1.234,56" / "-2,31" → float */
function parse_amount_de(?string $s): float
{
    $s = trim((string) $s);
    if ($s === '') {
        return 0.0;
    }
    $s = str_replace(['.', ' ', "\u{00A0}", '€'], '', $s);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float) $s : 0.0;
}

function cell(array $line, ?int $idx): string
{
    return $idx === null ? '' : trim((string) ($line[$idx] ?? ''));
}

function parse_giro(array $header, array $lines): array
{
    $cDatum = csv_col($header, 'Buchungstag', 'Buchungsdatum');
    $cValuta = csv_col($header, 'Valutadatum', 'Wertstellung');
    $cEmpf = csv_col($header, 'Beguenstigter/Zahlungspflichtiger', 'Begünstigter/Zahlungspflichtiger',
        'Auftraggeber/Empfänger', 'Name Zahlungsbeteiligter');
    $cZweck = csv_col($header, 'Verwendungszweck');
    $cBtext = csv_col($header, 'Buchungstext');
    $cBetrag = csv_col($header, 'Betrag', 'Umsatz');
    $cWaehr = csv_col($header, 'Waehrung', 'Währung');
    $cInfo = csv_col($header, 'Info', 'Status');

    if ($cDatum === null || $cBetrag === null) {
        throw new RuntimeException('Fehlende Pflichtspalten (Buchungstag/Betrag). Gefunden: ' . implode(', ', $header));
    }

    $rows = [];
    foreach ($lines as $l) {
        $rows[] = [
            'datum'        => parse_german_date(cell($l, $cDatum)),
            'valuta'       => parse_german_date(cell($l, $cValuta)),
            'empfaenger'   => cell($l, $cEmpf),
            'zweck'        => cell($l, $cZweck),
            'buchungstext' => cell($l, $cBtext),
            'betrag'       => parse_amount_de(cell($l, $cBetrag)),
            'waehrung'     => cell($l, $cWaehr) ?: 'EUR',
            'vorgemerkt'   => is_pending(cell($l, $cInfo)),
        ];
    }
    return $rows;
}

/** "Umsatz vorgemerkt" / "vorgemerkt" / "Vormerkung" → true; "Umsatz gebucht" oder leer → false. */
function is_pending(string $info): bool
{
    return preg_match('/vorgemerkt|vormerkung/iu', $info) === 1;
}

function parse_creditcard(array $header, array $lines): array
{
    $cBeleg = csv_col($header, 'Belegdatum');
    $cBuch = csv_col($header, 'Buchungsdatum', 'Buchungstag');
    $cBetrag = csv_col($header, 'Buchungsbetrag');
    $cWaehr = csv_col($header, 'Buchungswährung', 'Buchungswaehrung');
    $cBeschr = csv_col($header, 'Transaktionsbeschreibung');
    $cZusatz = csv_col($header, 'Transaktionsbeschreibung Zusatz', 'Transaktionsbeschreibung_Zusatz');
    $cOrigB = csv_col($header, 'Originalbetrag');
    $cOrigW = csv_col($header, 'Originalwährung', 'Originalwaehrung', 'Originaläwhrung');
    $cLand = csv_col($header, 'Länderkennzeichen', 'Laenderkennzeichen');

    if ($cBetrag === null) {
        throw new RuntimeException("Kreditkarten-CSV: Spalte 'Buchungsbetrag' nicht gefunden. Gefunden: " . implode(', ', $header));
    }

    $rows = [];
    foreach ($lines as $l) {
        $waehrung = cell($l, $cWaehr) ?: 'EUR';
        $zweck = cell($l, $cZusatz);
        // Fremdwährung an den Zweck anhängen, z.B. "PARIS (5,50 USD)"
        $origW = cell($l, $cOrigW);
        $origB = parse_amount_de(cell($l, $cOrigB));
        if ($origW !== '' && strcasecmp($origW, $waehrung) !== 0 && abs($origB) >= 0.001) {
            $orig = str_replace('.', ',', sprintf('%.2f', $origB)) . ' ' . $origW;
            $zweck = trim($zweck . " ($orig)");
        }
        $datum = parse_german_date(cell($l, $cBeleg)) ?? parse_german_date(cell($l, $cBuch));
        $rows[] = [
            'datum'        => $datum,
            'valuta'       => parse_german_date(cell($l, $cBuch)),
            'empfaenger'   => cell($l, $cBeschr),
            'zweck'        => $zweck,
            'buchungstext' => cell($l, $cLand),
            'betrag'       => parse_amount_de(cell($l, $cBetrag)),
            'waehrung'     => $waehrung,
        ];
    }
    return $rows;
}
