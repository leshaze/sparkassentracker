<?php require __DIR__ . '/_filter.php'; ?>

<?php if ($empty): ?>
    <div class="card empty">
        <h2>Noch keine Umsätze</h2>
        <p class="muted">Importiere zuerst eine CSV-Datei aus dem Sparkassen-Onlinebanking.</p>
        <a class="btn primary" href="?p=import">Zum Import</a>
    </div>
<?php return; endif; ?>

<?php $fc = $forecast; ?>
<section class="card forecast">
    <div class="card-head">
        <h2>Prognose <?= h(fmt_month($fc['monat'])) ?></h2>
        <span class="muted">Tag <?= $fc['tag'] ?> von <?= $fc['tage'] ?></span>
    </div>
    <div class="progress" aria-hidden="true"><span style="width: <?= round($fc['tag'] / $fc['tage'] * 100) ?>%"></span></div>
    <div class="kpis">
        <div class="kpi">
            <span class="kpi-label">Bisher ausgegeben</span>
            <span class="kpi-value"><?= fmt_eur($fc['bisher_aus']) ?></span>
            <span class="kpi-sub">+ <?= fmt_eur($fc['erwartet_aus']) ?> erwartet (wiederkehrend)</span>
        </div>
        <div class="kpi">
            <span class="kpi-label">Ausgaben Monatsende</span>
            <span class="kpi-value">≈ <?= fmt_eur($fc['prognose_aus']) ?></span>
            <?php if ($fc['diff_pct'] !== null): ?>
                <span class="kpi-sub <?= $fc['diff_pct'] > 0 ? 'neg' : 'pos' ?>">
                    <?= fmt_pct($fc['diff_pct']) ?> ggü. Ø <?= $fc['schnitt6_monate'] ?> Monate (<?= fmt_eur($fc['schnitt6_aus']) ?>)
                </span>
            <?php endif; ?>
        </div>
        <div class="kpi">
            <span class="kpi-label">Einnahmen Monatsende</span>
            <span class="kpi-value">≈ <?= fmt_eur($fc['prognose_ein']) ?></span>
            <span class="kpi-sub">davon <?= fmt_eur($fc['erwartet_ein']) ?> noch erwartet</span>
        </div>
        <div class="kpi">
            <span class="kpi-label">Saldo Monatsende</span>
            <span class="kpi-value <?= amount_class($fc['prognose_saldo']) ?>">≈ <?= fmt_eur($fc['prognose_saldo'], true) ?></span>
            <?php if ($fc['sparen_bisher'] !== null && ($fc['sparen_bisher'] || $fc['sparen_erwartet'])): ?>
                <span class="kpi-sub">vor Sparen · Sparraten <?= fmt_eur($fc['sparen_bisher'] + $fc['sparen_erwartet']) ?><?= $fc['sparen_erwartet'] ? ' (davon ' . fmt_eur($fc['sparen_erwartet']) . ' noch erwartet)' : '' ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($fc['ausstehend']): ?>
        <details class="pending">
            <summary><?= count($fc['ausstehend']) ?> wiederkehrende Posten stehen diesen Monat noch aus</summary>
            <ul class="plain">
                <?php foreach ($fc['ausstehend'] as $r): ?>
                    <li><span><?= h($r['empfaenger']) ?> <span class="muted">· fällig <?= h(fmt_date($r['naechstes_datum'])) ?></span></span>
                        <span class="num <?= amount_class($r['betrag']) ?>"><?= fmt_eur($r['betrag']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
    <p class="hint">
        Prognose = feste Ausgaben (wiederkehrende Posten: <?= fmt_eur($fc['fix_aus']) ?>, gebucht + noch ausstehend)
        + variable Ausgaben (≈ <?= fmt_eur($fc['variabel_prognose']) ?>, bisher <?= fmt_eur($fc['variabel_bisher']) ?><?php if ($fc['variabel_schnitt'] !== null): ?>,
        Median der Vormonate <?= fmt_eur($fc['variabel_schnitt']) ?> – dort sind Ø <?= fmt_eur($fc['fix_schnitt']) ?> Fixkosten pro Monat bereits herausgerechnet<?php endif; ?>).
        Die variablen Ausgaben werden am Monatsanfang vor allem aus dem Median der Vormonate geschätzt, gegen Monatsende
        zunehmend aus dem bisherigen Verlauf. Überträge und Sparen zählen nicht mit.
    </p>
</section>

<section class="kpis row">
    <div class="card kpi">
        <span class="kpi-label">Einnahmen</span>
        <span class="kpi-value pos"><?= fmt_eur($totals['ein']) ?></span>
        <?php if ($totals['gespart'] !== null): ?><span class="kpi-sub">ohne Entnahmen aus Erspartem</span><?php endif; ?>
    </div>
    <div class="card kpi">
        <span class="kpi-label">Ausgaben</span>
        <span class="kpi-value neg"><?= fmt_eur($totals['aus']) ?></span>
        <?php if ($totals['gespart'] !== null): ?><span class="kpi-sub">ohne Sparen</span><?php endif; ?>
    </div>
    <div class="card kpi">
        <span class="kpi-label"><?= $totals['saldo'] < 0 ? 'Fehlbetrag' : 'Überschuss' ?></span>
        <span class="kpi-value <?= amount_class($totals['saldo']) ?>"><?= fmt_eur($totals['saldo'], true) ?></span>
        <span class="kpi-sub"><?= number_format($totals['n'], 0, ',', '.') ?> Umsätze · <?= $f['konto'] === '' ? 'Überträge zwischen Konten ausgeblendet' : 'inkl. Überträge' ?></span>
    </div>
    <?php if ($totals['gespart'] !== null): ?>
        <div class="card kpi">
            <span class="kpi-label">Gespart (netto)</span>
            <span class="kpi-value <?= $totals['gespart'] < 0 ? 'neg' : '' ?>"><?= fmt_eur($totals['gespart'], true) ?></span>
            <span class="kpi-sub">Kontoveränderung <?= fmt_eur($totals['konto_veraenderung'], true) ?><?= $totals['gespart'] < 0 ? ' · mehr entnommen als eingezahlt' : '' ?></span>
        </div>
    <?php else: ?>
        <div class="card kpi">
            <span class="kpi-label">Umsätze</span>
            <span class="kpi-value"><?= number_format($totals['n'], 0, ',', '.') ?></span>
        </div>
    <?php endif; ?>
</section>

<?php if ($monthly): ?>
<section class="card">
    <div class="card-head"><h2>Monatsverlauf</h2></div>
    <div class="chart-box tall">
        <canvas data-chart="monthly" data-series="<?= json_attr(array_map(fn ($m) => $m + ['label' => fmt_month($m['monat'], true)], $monthly)) ?>"
                role="img" aria-label="Einnahmen und Ausgaben pro Monat"></canvas>
    </div>
</section>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h2>Ausgaben nach Kategorie</h2>
            <span class="muted"><?= fmt_eur($aus['total']) ?></span>
        </div>
        <?php if ($aus['cats']): ?>
            <div class="chart-box donut">
                <canvas data-chart="donut" data-series="<?= json_attr(array_map(fn ($c) => ['label' => $c['kategorie'], 'value' => round(-$c['summe'], 2)], $aus['cats'])) ?>"
                        role="img" aria-label="Anteil der Ausgaben nach Kategorie"></canvas>
            </div>
            <?php $breakdown = $aus; require __DIR__ . '/_category_table.php'; ?>
        <?php else: ?>
            <p class="muted">Keine Ausgaben im Zeitraum.</p>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card-head">
            <h2>Einnahmen nach Kategorie</h2>
            <span class="muted"><?= fmt_eur($ein['total']) ?></span>
        </div>
        <?php if ($ein['cats']): ?>
            <?php $breakdown = $ein; require __DIR__ . '/_category_table.php'; ?>
        <?php else: ?>
            <p class="muted">Keine Einnahmen im Zeitraum.</p>
        <?php endif; ?>
    </section>
</div>

<?php if ($sparen): ?>
<section class="card">
    <div class="card-head">
        <h2>Sparen &amp; Vermögensaufbau</h2>
        <span class="muted">netto <?= fmt_eur(array_sum(array_column($sparen, 'netto')), true) ?></span>
    </div>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Wohin</th><th class="num">Eingezahlt</th><th class="num">Entnommen</th><th class="num">Netto</th></tr></thead>
            <tbody>
            <?php foreach ($sparen as $sp): $kq = $sp['kategorie'] . ($sp['sub'] !== '' ? '::' . $sp['sub'] : ''); ?>
                <tr>
                    <td><a href="?p=umsaetze&amp;kat=<?= urlencode($kq) ?>"><?= h($sp['sub'] !== '' ? $sp['sub'] : $sp['kategorie'] . ' (ohne Unterkategorie)') ?></a>
                        <?php if (count(array_unique(array_column($sparen, 'kategorie'))) > 1): ?><span class="muted small">· <?= h($sp['kategorie']) ?></span><?php endif; ?></td>
                    <td class="num"><?= fmt_eur($sp['eingezahlt']) ?></td>
                    <td class="num"><?= $sp['entnommen'] ? fmt_eur(-$sp['entnommen']) : '—' ?></td>
                    <td class="num <?= $sp['netto'] < 0 ? 'neg' : '' ?>"><?= fmt_eur($sp['netto'], true) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Buchungen in Spar-Kategorien (<?= h(implode(', ', spar_kategorien())) ?>) zählen nicht als Ausgabe oder Einnahme, sondern als Vermögensaufbau. Einstellbar auf der Seite <a href="?p=kategorien">Kategorien</a>.</p>
</section>
<?php endif; ?>
