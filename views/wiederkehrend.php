<?php
$by = fn (string ...$s) => array_values(array_filter($list, fn ($r) => in_array($r['status'], $s, true)));
$active  = $by('wieder_gebucht', 'aktiv');
$missing = $by('ausgeblieben');
$ended   = $by('beendet');
$again   = $by('wieder_gebucht');

$yearOut = -array_sum(array_map(fn ($r) => $r['betrag'] < 0 ? $r['summe_pro_jahr'] : 0, $active));
$yearIn  = array_sum(array_map(fn ($r) => $r['betrag'] > 0 ? $r['summe_pro_jahr'] : 0, $active));
$saved   = -array_sum(array_map(fn ($r) => $r['betrag'] < 0 ? $r['summe_pro_jahr'] : 0, $ended));

$endButton = function (array $r, string $label = 'Beenden') { ?>
    <button class="btn ghost small" type="button" data-end="<?= json_attr([
        'key' => $r['key'], 'empf' => $r['empfaenger'], 'betrag' => $r['betrag'], 'freq' => $r['frequenz'],
        'betrag_fmt' => fmt_eur($r['betrag']), 'letzte' => fmt_date($r['letztes_datum']),
        'default' => $r['letztes_datum'] && $r['status'] === 'ausgeblieben' ? $r['letztes_datum'] : date('Y-m-d'),
    ]) ?>"><?= h($label) ?></button>
<?php };

$renderRows = function (array $rows, string $mode) use ($endButton) { ?>
    <div class="table-scroll">
    <table class="rec">
        <thead><tr>
            <th>Empfänger</th><th>Rhythmus</th><th class="num">Betrag</th><th class="num">Pro Jahr</th>
            <th>Zuletzt</th><th><?= $mode === 'beendet' ? 'Beendet zum' : 'Nächste' ?></th><th>Notiz</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['status'] === 'wieder_gebucht' ? 'row-alert' : '' ?>">
                <td>
                    <div class="empf"><?= h($r['empfaenger']) ?>
                        <?php if ($r['source'] === 'manual'): ?><span class="badge">manuell</span><?php endif; ?>
                        <?php if (!empty($r['flex'])): ?><span class="badge" title="Betrag hat sich geändert – es zählt die letzte Zahlung">Betrag angepasst</span><?php endif; ?>
                        <?php if ($r['status'] === 'wieder_gebucht'): ?><span class="badge alert">trotz Kündigung abgebucht</span><?php endif; ?>
                    </div>
                    <div class="muted small"><?= h($r['kategorie']) ?><?= $r['anzahl'] ? ' · ' . $r['anzahl'] . '× gebucht' : '' ?><?= $r['konto'] ? ' · ' . h($r['konto']) : '' ?></div>
                </td>
                <td><?= h($r['frequenz']) ?></td>
                <td class="num amount <?= amount_class($r['betrag']) ?>"><?= fmt_eur($r['betrag']) ?></td>
                <td class="num <?= $mode === 'beendet' ? 'muted strike' : amount_class($r['summe_pro_jahr']) ?>"><?= fmt_eur($r['summe_pro_jahr']) ?></td>
                <td class="nowrap"><?= h(fmt_date($r['letztes_datum'])) ?: '—' ?></td>
                <td class="nowrap">
                    <?php if ($mode === 'beendet'): ?>
                        <?= h(fmt_date($r['ende_datum'])) ?>
                    <?php elseif ($r['status'] === 'wieder_gebucht'): ?>
                        <span class="neg small">beendet zum <?= h(fmt_date($r['ende_datum'])) ?></span>
                    <?php else: ?>
                        <?= h(fmt_date($r['naechstes_datum'])) ?: '—' ?>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" class="inline-note">
                        <?= form_fields('rec_note') ?>
                        <input type="hidden" name="key" value="<?= h($r['key']) ?>">
                        <input type="text" name="notiz" value="<?= h($r['notiz']) ?>" placeholder="Notiz" aria-label="Notiz">
                    </form>
                </td>
                <td class="row-actions">
                    <?php if ($mode === 'beendet' || $r['status'] === 'wieder_gebucht'): ?>
                        <form method="post">
                            <?= form_fields('rec_reactivate') ?>
                            <input type="hidden" name="id" value="<?= (int) $r['ende_id'] ?>">
                            <button class="btn ghost small" type="submit" title="Läuft doch weiter">Reaktivieren</button>
                        </form>
                        <?php if ($r['status'] === 'wieder_gebucht'): $endButton($r, 'Datum ändern'); endif; ?>
                    <?php else: ?>
                        <?php $endButton($r, $mode === 'ausgeblieben' ? 'Als beendet bestätigen' : 'Beenden'); ?>
                        <?php if ($r['source'] === 'auto'): ?>
                            <form method="post">
                                <?= form_fields('rec_hide') ?>
                                <input type="hidden" name="empfaenger" value="<?= h($r['empfaenger']) ?>">
                                <input type="hidden" name="betrag" value="<?= h($r['betrag']) ?>">
                                <input type="hidden" name="frequenz" value="<?= h($r['frequenz']) ?>">
                                <button class="btn ghost small" type="submit" title="Ist gar keine wiederkehrende Zahlung">Ausblenden</button>
                            </form>
                        <?php else: ?>
                            <form method="post" data-confirm="Manuellen Eintrag löschen?">
                                <?= form_fields('rec_override_delete') ?>
                                <input type="hidden" name="id" value="<?= (int) $r['override_id'] ?>">
                                <button class="btn ghost small" type="submit">Löschen</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php }; ?>

<?php if ($again): ?>
    <div class="flash err" role="alert">
        <strong><?= count($again) ?> gekündigte Zahlung<?= count($again) > 1 ? 'en wurden' : ' wurde' ?> nach dem Ende-Datum erneut abgebucht:</strong>
        <?= h(implode(', ', array_map(fn ($r) => $r['empfaenger'] . ' (' . fmt_date($r['letztes_datum']) . ')', $again))) ?>.
        Kündigung prüfen oder ggf. Lastschrift zurückgeben.
    </div>
<?php endif; ?>

<section class="kpis row">
    <div class="card kpi">
        <span class="kpi-label">Wiederkehrende Ausgaben</span>
        <span class="kpi-value neg"><?= fmt_eur($yearOut) ?> / Jahr</span>
        <span class="kpi-sub"><?= fmt_eur($yearOut / 12) ?> pro Monat</span>
    </div>
    <div class="card kpi">
        <span class="kpi-label">Wiederkehrende Einnahmen</span>
        <span class="kpi-value pos"><?= fmt_eur($yearIn) ?> / Jahr</span>
        <span class="kpi-sub"><?= fmt_eur($yearIn / 12) ?> pro Monat</span>
    </div>
    <div class="card kpi">
        <span class="kpi-label">Durch Beenden gespart</span>
        <span class="kpi-value <?= $saved > 0 ? 'pos' : '' ?>"><?= fmt_eur($saved) ?> / Jahr</span>
        <span class="kpi-sub"><?= count($ended) ?> beendet · <?= count($missing) ?> ausgeblieben</span>
    </div>
</section>

<section class="card flush">
    <div class="card-head pad"><h2>Aktiv</h2><span class="muted">Erkannt ab 3 Buchungen im festen Rhythmus (halbjährlich/jährlich ab 2; bei langen Rhythmen darf sich der Betrag um bis zu 25 % ändern)</span></div>
    <?php if ($active): $renderRows($active, 'aktiv'); else: ?><p class="muted pad">Keine aktiven wiederkehrenden Zahlungen.</p><?php endif; ?>
</section>

<?php if ($missing): ?>
<section class="card flush">
    <div class="card-head pad">
        <h2>Ausgeblieben</h2>
        <span class="muted">Seit über zwei Intervallen keine Buchung – gekündigt? Dann bestätigen.</span>
    </div>
    <?php $renderRows($missing, 'ausgeblieben'); ?>
</section>
<?php endif; ?>

<?php if ($ended): ?>
<section class="card flush">
    <div class="card-head pad">
        <h2>Beendet</h2>
        <span class="muted">Zählt nicht mehr in Prognose und Summen. Neue Abbuchungen nach dem Ende-Datum werden gemeldet.</span>
    </div>
    <?php $renderRows($ended, 'beendet'); ?>
</section>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <h2>Manuell hinzufügen</h2>
        <p class="muted small">
            Für Posten, die nicht automatisch erkannt werden – z.&nbsp;B. eine jährliche Versicherung, solange erst
            ein Jahr importiert ist. Mit Fälligkeit zählt der Eintrag in der Monatsprognose. Den Empfänger so
            eintragen, wie er im Umsatz steht (ein Teil reicht, z.&nbsp;B. „HUK“): dann erkennt die Prognose,
            wenn die Zahlung schon abgebucht wurde.
        </p>
        <form method="post" class="form-grid">
            <?= form_fields('rec_manual_add') ?>
            <label class="span-2">Empfänger <input type="text" name="empfaenger" required></label>
            <label>Betrag <input type="text" name="betrag" inputmode="decimal" placeholder="12,99" required></label>
            <label>Art
                <select name="richtung"><option value="aus">Ausgabe</option><option value="ein">Einnahme</option></select>
            </label>
            <label>Rhythmus
                <select name="frequenz">
                    <?php foreach (array_keys(FREQUENZEN) as $fq): ?>
                        <option <?= $fq === 'Monatlich' ? 'selected' : '' ?>><?= h($fq) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Nächste Fälligkeit <input type="date" name="faellig"></label>
            <label class="span-2">Notiz <input type="text" name="notiz"></label>
            <div class="span-2"><button class="btn primary" type="submit">Hinzufügen</button></div>
        </form>
    </section>
    <section class="card">
        <h2>Ausgeblendet</h2>
        <p class="muted small">Fälschlich als wiederkehrend erkannte Buchungen.</p>
        <?php if (!$hidden): ?>
            <p class="muted small">Nichts ausgeblendet.</p>
        <?php else: ?>
            <ul class="plain">
                <?php foreach ($hidden as $hd): ?>
                    <li>
                        <span><?= h($hd['empfaenger']) ?> <span class="muted">· <?= fmt_eur((float) $hd['betrag']) ?> · <?= h($hd['frequenz']) ?></span></span>
                        <form method="post">
                            <?= form_fields('rec_override_delete') ?>
                            <input type="hidden" name="id" value="<?= (int) $hd['id'] ?>">
                            <button class="btn ghost small" type="submit">Wieder einblenden</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<dialog id="end-dialog" class="dialog">
    <form method="post" class="stack">
        <?= form_fields('rec_end') ?>
        <input type="hidden" name="key">
        <input type="hidden" name="empfaenger">
        <input type="hidden" name="betrag">
        <input type="hidden" name="frequenz">
        <div class="dialog-head">
            <div>
                <div class="d-empf"></div>
                <div class="muted small d-meta"></div>
            </div>
            <div class="d-betrag num neg"></div>
        </div>
        <label>Beendet zum
            <input type="date" name="datum" required>
            <span class="hint">Letzter Tag, an dem noch abgebucht werden darf – z.B. das Ende der Kündigungsfrist. Kommt danach noch eine Buchung, wirst du gewarnt.</span>
        </label>
        <div class="dialog-actions">
            <button class="btn ghost" type="button" data-close>Abbrechen</button>
            <button class="btn primary" type="submit">Als beendet markieren</button>
        </div>
    </form>
</dialog>
