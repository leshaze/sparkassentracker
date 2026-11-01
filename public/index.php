<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$page = get('p', 'uebersicht');
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('a') : '';

// -----------------------------------------------------------------------------
// Login / Ersteinrichtung
// -----------------------------------------------------------------------------
if (!auth_configured()) {
    if ($action === 'setup') {
        csrf_check();
        $pw = $_POST['pw'] ?? '';
        if (!is_string($pw) || mb_strlen($pw) < 8) {
            flash('Das Passwort muss mindestens 8 Zeichen haben.', 'err');
        } elseif ($pw !== ($_POST['pw2'] ?? '')) {
            flash('Die Passwörter stimmen nicht überein.', 'err');
        } else {
            auth_set_password($pw);
            auth_login();
            flash('Passwort gesetzt. Willkommen!');
            redirect('import');
        }
    }
    render('login', ['setup' => true, 'bare' => true]);
    exit;
}

if (!auth_logged_in()) {
    if ($action === 'login') {
        csrf_check();
        if (auth_throttled()) {
            flash('Zu viele Fehlversuche – bitte 5 Minuten warten.', 'err');
        } elseif (auth_verify((string) ($_POST['pw'] ?? ''))) {
            auth_record_attempt(true);
            auth_login();
            redirect('uebersicht');
        } else {
            auth_record_attempt(false);
            flash('Falsches Passwort.', 'err');
        }
    }
    render('login', ['setup' => false, 'bare' => true]);
    exit;
}

// -----------------------------------------------------------------------------
// Aktionen (POST → Redirect)
// -----------------------------------------------------------------------------
if ($action !== '') {
    csrf_check();
    $rules = Rules::instance();
    $back = post('back', 'uebersicht');
    $backParams = [];
    parse_str(post('back_q'), $backParams);
    unset($backParams['p']);

    try {
        switch ($action) {
            case 'logout':
                auth_logout();
                header('Location: ./');
                exit;

            case 'import':
                $konto = post('konto_neu') !== '' ? post('konto_neu') : post('konto');
                if ($konto === '') {
                    throw new RuntimeException('Bitte ein Konto auswählen oder einen neuen Kontonamen eingeben.');
                }
                $files = $_FILES['csv'] ?? null;
                if (!$files || !is_array($files['name'])) {
                    throw new RuntimeException('Keine Datei ausgewählt.');
                }
                $msgs = [];
                foreach ($files['name'] as $i => $name) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                        if ($files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                            $msgs[] = "$name: Upload fehlgeschlagen (Fehler {$files['error'][$i]}).";
                        }
                        continue;
                    }
                    $res = read_sparkasse_csv((string) file_get_contents($files['tmp_name'][$i]));
                    [$ins, $skip] = insert_rows($res['rows'], $konto, $rules);
                    $msgs[] = sprintf('%s (%s): %d neu, %d bereits vorhanden%s.', $name, $res['format'], $ins, $skip,
                        $res['errors'] ? ", {$res['errors']} Zeilen ohne gültiges Datum übersprungen" : '');
                }
                if (!$msgs) {
                    throw new RuntimeException('Keine Datei ausgewählt.');
                }
                $n = detect_transfers();
                if ($n) {
                    $msgs[] = "$n Übertrag-Paar(e) automatisch erkannt.";
                }
                flash(implode(' ', $msgs));
                redirect('import');

            case 'konto_delete':
                $k = post('konto');
                $n = db_exec('DELETE FROM transactions WHERE konto = ?', [$k]);
                flash("Konto „{$k}“ mit $n Umsätzen gelöscht.");
                redirect('import');

            case 'detect_transfers':
                $n = detect_transfers();
                flash($n ? "$n neue(s) Übertrag-Paar(e) erkannt." : 'Keine neuen Überträge erkannt.');
                break;

            case 'tx_update':
                $hash = post('hash');
                [$cat, $sub] = array_pad(explode('::', post('kategorie'), 2), 2, '');
                $cat = $cat !== '' ? $cat : Rules::FALLBACK;
                $old = db_all('SELECT kategorie, unterkategorie FROM transactions WHERE hash = ?', [$hash])[0] ?? null;
                if (!$old) {
                    throw new RuntimeException('Umsatz nicht gefunden.');
                }
                $catChanged = $old['kategorie'] !== $cat || (string) $old['unterkategorie'] !== $sub;
                $ue = post('uebertrag') === '1' ? 1 : 0;
                db_exec('UPDATE transactions SET kategorie = ?, unterkategorie = ?, notiz = ?, ist_uebertrag = ?,
                         uebertrag_gesperrt = ?, kategorie_manuell = CASE WHEN ? THEN 1 ELSE kategorie_manuell END
                         WHERE hash = ?',
                    [$cat, $sub, post('notiz'), $ue, $ue ? 0 : 1, $catChanged ? 1 : 0, $hash]);
                $msg = 'Umsatz gespeichert.';
                if (post('regel') === '1' && post('regel_kw') !== '' && $cat !== Rules::FALLBACK) {
                    $rules->addKeyword($cat, $sub, post('regel_kw'));
                    $rules->save();
                    $n = recategorize_all($rules);
                    $msg .= sprintf(' Regel „%s“ → %s angelegt, %d weitere Umsätze neu kategorisiert.',
                        mb_strtolower(post('regel_kw')), $cat . ($sub !== '' ? " › $sub" : ''), $n);
                }
                flash($msg);
                break;

            // ---- Kategorien ----
            case 'cat_add':
                $rules->addCategory(post('name'));
                $rules->save();
                flash('Kategorie angelegt.');
                break;
            case 'sub_add':
                $rules->addSub(post('cat'), post('name'));
                $rules->save();
                flash('Unterkategorie angelegt.');
                break;
            case 'kw_add':
                foreach (preg_split('/[,\n]+/', $_POST['kw'] ?? '') as $kw) {
                    if (trim($kw) !== '') {
                        $rules->addKeyword(post('cat'), post('sub'), ltrim($kw));
                    }
                }
                $rules->save();
                flash('Stichwort(e) hinzugefügt. „Neu kategorisieren“ wendet sie auf bestehende Umsätze an.');
                break;
            case 'kw_remove':
                $rules->removeKeyword(post('cat'), post('sub'), $_POST['kw'] ?? '');
                $rules->save();
                flash('Stichwort entfernt.');
                break;
            case 'cat_delete':
                $rules->deleteCategory(post('cat'));
                $rules->save();
                flash('Kategorie gelöscht. Betroffene Umsätze werden beim Neu-Kategorisieren zugeordnet.');
                break;
            case 'sub_delete':
                $rules->deleteSub(post('cat'), post('sub'));
                $rules->save();
                flash('Unterkategorie gelöscht.');
                break;
            case 'recategorize':
                $n = recategorize_all($rules, post('alle') === '1');
                flash("$n Umsätze neu kategorisiert.");
                break;
            case 'rules_upload':
                $f = $_FILES['json'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Keine Datei ausgewählt.');
                }
                $data = json_decode((string) file_get_contents($f['tmp_name']), true);
                if (!is_array($data)) {
                    throw new RuntimeException('Die Datei ist kein gültiges JSON.');
                }
                $rules->load($data);
                $rules->save();
                $n = recategorize_all($rules);
                flash('categories.json übernommen, ' . count($data) . " Kategorien, $n Umsätze neu kategorisiert.");
                break;

            // ---- Wiederkehrend ----
            case 'rec_hide':
                db_exec("INSERT INTO recurring_overrides (empfaenger, betrag, frequenz, kind) VALUES (?, ?, ?, 'hidden')",
                    [post('empfaenger'), (float) post('betrag'), post('frequenz')]);
                flash('Ausgeblendet.');
                break;
            case 'rec_override_delete':
                db_exec('DELETE FROM recurring_overrides WHERE id = ?', [(int) post('id')]);
                flash('Entfernt.');
                break;
            case 'rec_end':
                $key = post('key');
                $datum = parse_input_date(post('datum')) ?? date('Y-m-d');
                if ($key === '') {
                    throw new RuntimeException('Eintrag nicht gefunden.');
                }
                db_exec("DELETE FROM recurring_overrides WHERE kind = 'ended' AND schluessel = ?", [$key]);
                db_exec("INSERT INTO recurring_overrides (empfaenger, betrag, frequenz, kind, schluessel, datum)
                         VALUES (?, ?, ?, 'ended', ?, ?)",
                    [post('empfaenger'), (float) post('betrag'), post('frequenz'), $key, $datum]);
                flash('„' . post('empfaenger') . '“ als beendet zum ' . fmt_date($datum)
                    . ' markiert. Wird danach noch einmal abgebucht, weist dich die App darauf hin.');
                break;
            case 'rec_reactivate':
                db_exec("DELETE FROM recurring_overrides WHERE id = ? AND kind = 'ended'", [(int) post('id')]);
                flash('Wieder als aktiv geführt.');
                break;
            case 'rec_manual_add':
                $b = parse_amount_de(post('betrag'));
                if (post('empfaenger') === '' || $b == 0.0) {
                    throw new RuntimeException('Empfänger und Betrag angeben.');
                }
                if (post('richtung') === 'aus') {
                    $b = -abs($b);
                } else {
                    $b = abs($b);
                }
                db_exec("INSERT INTO recurring_overrides (empfaenger, betrag, frequenz, kind, notiz) VALUES (?, ?, ?, 'manual', ?)",
                    [post('empfaenger'), $b, post('frequenz'), post('notiz') ?: null]);
                flash('Wiederkehrende Zahlung „' . post('empfaenger') . '“ hinzugefügt.');
                break;
            case 'rec_note':
                $key = post('key');
                if (str_starts_with($key, 'manual:')) {
                    db_exec('UPDATE recurring_overrides SET notiz = ? WHERE id = ?', [post('notiz'), (int) substr($key, 7)]);
                } else {
                    db_exec('INSERT INTO recurring_notes (schluessel, notiz) VALUES (?, ?)
                             ON CONFLICT(schluessel) DO UPDATE SET notiz = excluded.notiz', [$key, post('notiz')]);
                }
                flash('Notiz gespeichert.');
                break;

            default:
                throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect($back, $backParams);
}

// -----------------------------------------------------------------------------
// Downloads
// -----------------------------------------------------------------------------
if ($page === 'regeln_export') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="categories.json"');
    echo json_encode(Rules::instance()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($page === 'umsaetze_export') {
    $f = current_filter();
    [$w, $p] = where_clause($f, 'list', true);
    $rows = db_all("SELECT datum, valuta, konto, empfaenger, zweck, buchungstext, betrag, waehrung, kategorie,
                    unterkategorie, ist_uebertrag, notiz FROM transactions $w ORDER BY datum", $p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="umsaetze_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Datum', 'Valuta', 'Konto', 'Empfänger', 'Verwendungszweck', 'Buchungstext', 'Betrag',
        'Währung', 'Kategorie', 'Unterkategorie', 'Übertrag', 'Notiz'], ';', '"', '');
    foreach ($rows as $r) {
        $r['datum'] = fmt_date($r['datum']);
        $r['valuta'] = fmt_date($r['valuta']);
        $r['betrag'] = number_format((float) $r['betrag'], 2, ',', '');
        $r['ist_uebertrag'] = $r['ist_uebertrag'] ? 'ja' : '';
        fputcsv($out, array_values($r), ';', '"', '');
    }
    exit;
}

// -----------------------------------------------------------------------------
// Seiten
// -----------------------------------------------------------------------------
$pages = [
    'uebersicht'    => 'Übersicht',
    'umsaetze'      => 'Umsätze',
    'wiederkehrend' => 'Wiederkehrend',
    'insights'      => 'Insights',
    'vergleich'     => 'Vorjahr',
    'kategorien'    => 'Kategorien',
    'import'        => 'Import',
];
if (!isset($pages[$page])) {
    $page = 'uebersicht';
}

$vars = ['page' => $page, 'pages' => $pages, 'title' => $pages[$page]];
$vars['empty'] = (int) db_one('SELECT COUNT(*) FROM transactions') === 0;

switch ($page) {
    case 'uebersicht':
        $f = current_filter();
        $vars += [
            'f'        => $f,
            'totals'   => totals($f),
            'monthly'  => monthly_series($f),
            'aus'      => category_breakdown($f),
            'ein'      => category_breakdown($f, true),
            'forecast' => forecast($f),
        ];
        break;

    case 'umsaetze':
        $f = current_filter();
        $showUe = get('ue') === '1';
        $kat = get('kat');
        $q = get('q');
        $sortCols = ['datum' => 'datum', 'konto' => 'konto', 'empfaenger' => 'ulower(empfaenger)',
            'betrag' => 'betrag', 'kategorie' => 'kategorie'];
        $sort = isset($sortCols[get('sort')]) ? get('sort') : 'datum';
        $dir = get('dir') === 'asc' ? 'ASC' : 'DESC';

        [$w, $p] = where_clause($f, 'list', $showUe);
        $extra = [];
        if ($kat !== '') {
            [$kc, $ks] = array_pad(explode('::', $kat, 2), 2, null);
            $extra[] = "COALESCE(NULLIF(kategorie,''),'Unbekannt') = ?";
            $p[] = $kc;
            if ($ks !== null) {
                $extra[] = 'unterkategorie = ?';
                $p[] = $ks;
            }
        }
        if ($q !== '') {
            $extra[] = "ulower(empfaenger || ' ' || zweck || ' ' || buchungstext || ' ' || COALESCE(notiz,'')) LIKE ?";
            $p[] = '%' . mb_strtolower($q) . '%';
        }
        if ($extra) {
            $w .= ($w ? ' AND ' : 'WHERE ') . implode(' AND ', $extra);
        }
        $count = (int) db_one("SELECT COUNT(*) FROM transactions $w", $p);
        $sum = (float) db_one("SELECT COALESCE(SUM(betrag),0) FROM transactions $w", $p);
        $size = (int) cfg('page_size');
        $pages_n = max(1, (int) ceil($count / $size));
        $pg = min($pages_n, max(1, (int) get('s', '1')));
        $rows = db_all("SELECT * FROM transactions $w ORDER BY {$sortCols[$sort]} $dir, datum DESC, hash
                        LIMIT $size OFFSET " . (($pg - 1) * $size), $p);
        $vars += compact('f', 'showUe', 'kat', 'q', 'sort', 'dir', 'count', 'sum', 'pg', 'pages_n', 'rows');
        $vars['rules'] = Rules::instance()->options();
        break;

    case 'wiederkehrend':
        $vars['list'] = recurring_list(false);
        $vars['hidden'] = db_all("SELECT * FROM recurring_overrides WHERE kind = 'hidden' ORDER BY empfaenger");
        break;

    case 'insights':
        $f = current_filter();
        $vars['f'] = $f;
        $vars['ins'] = insights($f);
        break;

    case 'vergleich':
        $f = current_filter();
        $years = available_years();
        $year = (int) get('jahr', (string) ($years[0] ?? date('Y')));
        $vars += ['f' => $f, 'years' => $years, 'yoy' => yoy($year, $f['konto'])];
        break;

    case 'kategorien':
        $rules = Rules::instance();
        $vars['cats'] = $rules->categories();
        ksort($vars['cats'], SORT_NATURAL | SORT_FLAG_CASE);
        $vars['counts'] = [];
        foreach (db_all("SELECT COALESCE(NULLIF(kategorie,''),'Unbekannt') AS k, COUNT(*) AS n FROM transactions GROUP BY k") as $r) {
            $vars['counts'][$r['k']] = (int) $r['n'];
        }
        $vars['manual'] = (int) db_one('SELECT COUNT(*) FROM transactions WHERE kategorie_manuell = 1');
        break;

    case 'import':
        $vars['konten'] = db_all("SELECT konto, COUNT(*) AS n, MIN(substr(datum,1,10)) AS von, MAX(substr(datum,1,10)) AS bis,
                                  SUM(ist_uebertrag) AS ue FROM transactions GROUP BY konto ORDER BY konto");
        break;
}

render($page, $vars);
