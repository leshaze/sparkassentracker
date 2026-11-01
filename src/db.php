<?php
declare(strict_types=1);

/**
 * SQLite-Datenbank. Schema angelehnt an die Desktop-App (transactions.db),
 * zusätzliche Spalten werden bei Bedarf ergänzt.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . cfg('data_dir') . '/transactions.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    // Unicode-taugliches lower() für die Suche (SQLite-LOWER kann kein ä/ö/ü)
    $ulower = fn ($s) => $s === null ? null : mb_strtolower((string) $s);
    if (method_exists($pdo, 'createFunction')) {
        $pdo->createFunction('ulower', $ulower, 1, PDO::SQLITE_DETERMINISTIC);
    } else {
        $pdo->sqliteCreateFunction('ulower', $ulower, 1, PDO::SQLITE_DETERMINISTIC);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            hash           TEXT PRIMARY KEY,
            konto          TEXT,
            datum          TEXT,
            valuta         TEXT,
            empfaenger     TEXT,
            zweck          TEXT,
            buchungstext   TEXT,
            betrag         REAL,
            waehrung       TEXT,
            kategorie      TEXT,
            unterkategorie TEXT,
            imported_at    TEXT DEFAULT CURRENT_TIMESTAMP,
            ist_uebertrag  INTEGER DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_datum ON transactions(datum);
        CREATE INDEX IF NOT EXISTS idx_kategorie ON transactions(kategorie);
        CREATE TABLE IF NOT EXISTS recurring_overrides (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            empfaenger TEXT,
            betrag     REAL,
            frequenz   TEXT,
            kind       TEXT,
            notiz      TEXT
        );
        CREATE TABLE IF NOT EXISTS recurring_notes (
            schluessel TEXT PRIMARY KEY,
            notiz      TEXT
        );
    ");

    // Zusatzspalten (fehlen in einer vom Desktop kopierten DB)
    $cols = array_column($pdo->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    $extra = [
        'ist_uebertrag'      => 'INTEGER DEFAULT 0',
        'kategorie_manuell'  => 'INTEGER DEFAULT 0',
        'uebertrag_gesperrt' => 'INTEGER DEFAULT 0',
        'notiz'              => 'TEXT',
    ];
    foreach ($extra as $col => $type) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN $col $type");
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_konto ON transactions(konto)');

    // Wiederkehrend: Schlüssel + Datum für "beendet"-Markierungen
    $rcols = array_column($pdo->query('PRAGMA table_info(recurring_overrides)')->fetchAll(), 'name');
    foreach (['schluessel' => 'TEXT', 'datum' => 'TEXT'] as $col => $type) {
        if (!in_array($col, $rcols, true)) {
            $pdo->exec("ALTER TABLE recurring_overrides ADD COLUMN $col $type");
        }
    }
    return $pdo;
}

function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_one(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_NUM);
    return $r ? $r[0] : null;
}

function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** SHA-256 identisch zur Desktop-App (Konto fließt mit ein). */
function tx_hash(string $konto, string $datum, float $betrag, string $empf, string $zweck, string $btext): string
{
    $raw = implode('|', [
        mb_strtolower(trim($konto)),
        $datum,
        sprintf('%.2f', $betrag),
        mb_strtolower(trim($empf)),
        mb_strtolower(trim($zweck)),
        mb_strtolower(trim($btext)),
    ]);
    return hash('sha256', $raw);
}

function konten(): array
{
    return array_column(db_all("SELECT DISTINCT konto FROM transactions WHERE konto <> '' ORDER BY konto"), 'konto');
}

/**
 * Fügt geparste Zeilen ein. Gibt [eingefügt, übersprungen] zurück.
 */
function insert_rows(array $rows, string $konto, Rules $rules): array
{
    $pdo = db();
    $st = $pdo->prepare('INSERT OR IGNORE INTO transactions
        (hash, konto, datum, valuta, empfaenger, zweck, buchungstext, betrag, waehrung, kategorie, unterkategorie)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $ins = 0;
    $skip = 0;
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $h = tx_hash($konto, $r['datum'], $r['betrag'], $r['empfaenger'], $r['zweck'], $r['buchungstext']);
        [$cat, $sub] = $rules->match(search_text($r));
        $st->execute([$h, $konto, $r['datum'], $r['valuta'], $r['empfaenger'], $r['zweck'],
            $r['buchungstext'], $r['betrag'], $r['waehrung'], $cat, $sub]);
        $st->rowCount() ? $ins++ : $skip++;
    }
    $pdo->commit();
    return [$ins, $skip];
}

function search_text(array $r): string
{
    return trim(($r['empfaenger'] ?? '') . ' | ' . ($r['zweck'] ?? '') . ' | ' . ($r['buchungstext'] ?? ''), ' |');
}

/** Alle nicht manuell gesetzten Kategorien neu berechnen. */
function recategorize_all(Rules $rules, bool $includeManual = false): int
{
    $pdo = db();
    $rows = db_all('SELECT hash, empfaenger, zweck, buchungstext, kategorie, unterkategorie FROM transactions'
        . ($includeManual ? '' : ' WHERE kategorie_manuell = 0'));
    $st = $pdo->prepare('UPDATE transactions SET kategorie=?, unterkategorie=?, kategorie_manuell=0 WHERE hash=?');
    $n = 0;
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        [$cat, $sub] = $rules->match(search_text($r));
        if ($cat !== $r['kategorie'] || $sub !== (string) $r['unterkategorie'] || $includeManual) {
            $st->execute([$cat, $sub, $r['hash']]);
            $n++;
        }
    }
    $pdo->commit();
    return $n;
}
