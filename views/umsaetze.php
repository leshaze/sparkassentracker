<?php
$keep = array_filter(['kat' => $kat, 'q' => $q, 'ue' => $showUe ? '1' : '', 'sort' => $sort, 'dir' => strtolower($dir)], fn ($v) => $v !== '');
require __DIR__ . '/_filter.php';

$qs = fn (array $over) => '?' . http_build_query(array_filter(array_merge(['p' => 'umsaetze'], $keep, $over), fn ($v) => $v !== '' && $v !== null));
$sortLink = function (string $col, string $label) use ($sort, $dir, $qs) {
    $active = $sort === $col;
    $next = $active && $dir === 'DESC' ? 'asc' : 'desc';
    $arrow = $active ? ($dir === 'DESC' ? ' ↓' : ' ↑') : '';
    return '<a href="' . h($qs(['sort' => $col, 'dir' => $next, 's' => null])) . '"' . ($active ? ' class="sorted"' : '') . '>' . h($label) . $arrow . '</a>';
};
?>

<form method="get" class="toolbar" data-autosubmit>
    <input type="hidden" name="p" value="umsaetze">
    <input type="hidden" name="sort" value="<?= h($sort) ?>">
    <input type="hidden" name="dir" value="<?= h(strtolower($dir)) ?>">
    <label class="field grow">
        <span>Suche</span>
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Empfänger, Verwendungszweck, Notiz …" data-nosubmit>
    </label>
    <label class="field">
        <span>Kategorie</span>
        <select name="kat">
            <option value="">Alle</option>
            <option value="Unbekannt" <?= $kat === 'Unbekannt' ? 'selected' : '' ?>>Unbekannt</option>
            <?php foreach ($rules as $c => $subs): ?>
                <option value="<?= h($c) ?>" <?= $kat === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                <?php foreach ($subs as $s): ?>
                    <option value="<?= h("$c::$s") ?>" <?= $kat === "$c::$s" ? 'selected' : '' ?>>&nbsp;&nbsp;› <?= h($s) ?></option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if ($f['konto'] === ''): ?>
        <label class="check">
            <input type="checkbox" name="ue" value="1" <?= $showUe ? 'checked' : '' ?>> Überträge zeigen
        </label>
    <?php endif; ?>
    <button class="btn" type="submit">Suchen</button>
</form>

<div class="list-head">
    <div>
        <strong><?= number_format($count, 0, ',', '.') ?></strong> Umsätze ·
        Summe <strong class="<?= amount_class($sum) ?>"><?= fmt_eur($sum, true) ?></strong>
    </div>
    <div class="actions">
        <form method="post"><?= form_fields('detect_transfers') ?><button class="btn small" type="submit">Überträge erkennen</button></form>
        <a class="btn small" href="?p=umsaetze_export">CSV-Export</a>
    </div>
</div>

<?php if (!$rows): ?>
    <div class="card empty"><p class="muted"><?= $empty ? 'Noch keine Umsätze importiert.' : 'Keine Umsätze für diese Filter.' ?></p></div>
<?php else: ?>
<div class="card flush">
    <div class="table-scroll">
        <table class="tx">
            <thead>
                <tr>
                    <th class="c-date"><?= $sortLink('datum', 'Datum') ?></th>
                    <th class="c-konto"><?= $sortLink('konto', 'Konto') ?></th>
                    <th><?= $sortLink('empfaenger', 'Empfänger / Zweck') ?></th>
                    <th class="c-kat"><?= $sortLink('kategorie', 'Kategorie') ?></th>
                    <th class="num"><?= $sortLink('betrag', 'Betrag') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                // In "Alle Konten" kommen die Zeilen aus der Auswertungs-Sicht: Sparkonto-Überträge
                // sind dort umgebucht. Der Dialog bearbeitet aber immer die echte Buchung.
                $sparen = !empty($r['ist_sparen']);
                $o = $sparen ? ['kategorie' => $r['kategorie_orig'], 'unterkategorie' => $r['unterkategorie_orig'],
                    'ist_uebertrag' => $r['ist_uebertrag_orig'], 'betrag' => $r['betrag_orig']] + $r : $r;
            ?>
                <tr class="<?= $r['ist_uebertrag'] ? 'is-transfer' : '' ?>" tabindex="0" data-edit="<?= json_attr([
                    'hash' => $o['hash'], 'kategorie' => $o['kategorie'] . ($o['unterkategorie'] ? '::' . $o['unterkategorie'] : ''),
                    'notiz' => $o['notiz'] ?? '', 'ue' => (int) $o['ist_uebertrag'], 'empf' => $o['empfaenger'],
                    'zweck' => $o['zweck'], 'btext' => $o['buchungstext'], 'datum' => fmt_date($o['datum']),
                    'betrag' => fmt_eur((float) $o['betrag']), 'konto' => $o['konto'],
                ]) ?>">
                    <td class="c-date"><?= h(fmt_date($r['datum'])) ?></td>
                    <td class="c-konto"><span class="konto-tag"><?= h($r['konto']) ?></span></td>
                    <td class="c-text">
                        <div class="empf"><?= h($r['empfaenger'] ?: $r['buchungstext'] ?: '—') ?>
                            <?php if ($r['ist_uebertrag']): ?><span class="badge transfer">Übertrag</span><?php endif; ?>
                            <?php if ($sparen): ?><span class="badge transfer" title="Übertrag auf ein Sparkonto – zählt in „Alle Konten“ als <?= $r['betrag'] < 0 ? 'Ausgabe' : 'Einnahme' ?>"><?= $r['betrag'] < 0 ? 'Sparen' : 'Entnahme' ?></span><?php endif; ?>
                        </div>
                        <div class="zweck muted"><?= h($r['zweck']) ?></div>
                        <?php if (!empty($r['notiz'])): ?><div class="notiz">✎ <?= h($r['notiz']) ?></div><?php endif; ?>
                    </td>
                    <td class="c-kat">
                        <span class="pill <?= ($r['kategorie'] ?: 'Unbekannt') === 'Unbekannt' ? 'unknown' : '' ?>"><?= h($r['kategorie'] ?: 'Unbekannt') ?><?php if ($r['unterkategorie']): ?> <span class="sub">› <?= h($r['unterkategorie']) ?></span><?php endif; ?></span>
                        <?php if ($r['kategorie_manuell']): ?><span class="manual" title="Manuell zugeordnet">●</span><?php endif; ?>
                    </td>
                    <td class="num amount <?= amount_class((float) $r['betrag']) ?>"><?= fmt_eur((float) $r['betrag']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages_n > 1): ?>
    <nav class="pager" aria-label="Seiten">
        <?php if ($pg > 1): ?><a class="btn small" href="<?= h($qs(['s' => $pg - 1])) ?>">← Zurück</a><?php endif; ?>
        <span class="muted">Seite <?= $pg ?> von <?= $pages_n ?></span>
        <?php if ($pg < $pages_n): ?><a class="btn small" href="<?= h($qs(['s' => $pg + 1])) ?>">Weiter →</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>

<dialog id="tx-dialog" class="dialog">
    <form method="post" class="stack">
        <?= form_fields('tx_update') ?>
        <input type="hidden" name="hash">
        <div class="dialog-head">
            <div>
                <div class="d-empf"></div>
                <div class="muted small d-meta"></div>
            </div>
            <div class="d-betrag num"></div>
        </div>
        <div class="d-zweck muted small"></div>
        <label>Kategorie
            <select name="kategorie">
                <option value="Unbekannt">Unbekannt</option>
                <?php foreach ($rules as $c => $subs): ?>
                    <option value="<?= h($c) ?>"><?= h($c) ?></option>
                    <?php foreach ($subs as $s): ?>
                        <option value="<?= h("$c::$s") ?>">&nbsp;&nbsp;<?= h($c) ?> › <?= h($s) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="check">
            <input type="checkbox" name="regel" value="1" data-rule-toggle> Als Regel speichern (Stichwort für künftige Umsätze)
        </label>
        <label class="rule-kw" hidden>Stichwort
            <input type="text" name="regel_kw" placeholder="z.B. rewe">
            <span class="hint">Durchsucht Empfänger, Verwendungszweck und Buchungstext. Groß-/Kleinschreibung egal,
                Leerzeichen passen auch auf Text dazwischen. Mehrere Begriffe mit <code>+</code> verbinden
                (z.&nbsp;B. <code>paypal + steam</code>): dann müssen alle vorkommen, und die Regel hat Vorrang.</span>
        </label>
        <label>Notiz <input type="text" name="notiz" maxlength="500"></label>
        <label class="check"><input type="checkbox" name="uebertrag" value="1"> Übertrag zwischen eigenen Konten</label>
        <div class="dialog-actions">
            <button class="btn ghost" type="button" data-close>Abbrechen</button>
            <button class="btn primary" type="submit">Speichern</button>
        </div>
    </form>
</dialog>
