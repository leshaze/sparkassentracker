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
    <p class="hint">Gewichtete Hochrechnung: 50 % linear aus dem bisherigen Verlauf, 50 % bisher + noch ausstehende wiederkehrende Zahlungen. Überträge zählen nicht mit.</p>
</section>

<section class="kpis row">
    <div class="card kpi">
        <span class="kpi-label">Einnahmen</span>
        <span class="kpi-value pos"><?= fmt_eur($totals['ein']) ?></span>
    </div>
    <div class="card kpi">
        <span class="kpi-label">Ausgaben</span>
        <span class="kpi-value neg"><?= fmt_eur($totals['aus']) ?></span>
        <?php if ($totals['gespart'] !== null): ?>
            <span class="kpi-sub"><?= number_format($totals['n'], 0, ',', '.') ?> Umsätze, Überträge ausgeblendet</span>
        <?php endif; ?>
    </div>
    <?php if ($totals['gespart'] !== null): ?>
        <div class="card kpi">
            <span class="kpi-label">Gespart</span>
            <span class="kpi-value <?= $totals['gespart'] < 0 ? 'neg' : 'save' ?>"><?= fmt_eur($totals['gespart'], true) ?></span>
            <span class="kpi-sub">
                <?= $totals['quote'] !== null ? 'Sparquote ' . h(fmt_pct($totals['quote'], false)) . ' · ' : '' ?>netto auf <?= h(implode(', ', spar_konten())) ?>
            </span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Übrig nach Sparen</span>
            <span class="kpi-value <?= amount_class($totals['uebrig']) ?>"><?= fmt_eur($totals['uebrig'], true) ?></span>
            <span class="kpi-sub">Vermögen gesamt <?= fmt_eur($totals['saldo'], true) ?></span>
        </div>
    <?php else: ?>
        <div class="card kpi">
            <span class="kpi-label">Saldo</span>
            <span class="kpi-value <?= amount_class($totals['saldo']) ?>"><?= fmt_eur($totals['saldo'], true) ?></span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Umsätze</span>
            <span class="kpi-value"><?= number_format($totals['n'], 0, ',', '.') ?></span>
            <span class="kpi-sub"><?= $f['konto'] === '' ? 'Überträge zwischen Konten ausgeblendet' : 'inkl. Überträge (für korrekten Saldo)' ?></span>
        </div>
    <?php endif; ?>
</section>

<?php if ($f['konto'] === '' && $totals['gespart'] === null && count(konten()) > 1): ?>
    <p class="hint">Tipp: Tagesgeld oder Depot unter <a href="?p=import">Import → Konten</a> als <em>Sparkonto</em> markieren – dann erscheinen Einzahlungen dort als „Gespart“ statt sich mit dem Übertrag aufzuheben.</p>
<?php endif; ?>

<?php if ($monthly): ?>
<section class="card">
    <div class="card-head"><h2>Monatsverlauf</h2></div>
    <div class="chart-box tall">
        <canvas data-chart="monthly" data-series="<?= json_attr(array_map(fn ($m) => $m + ['label' => fmt_month($m['monat'], true)], $monthly)) ?>"
                role="img" aria-label="Einnahmen, Ausgaben<?= $totals['gespart'] !== null ? ', Gespart' : '' ?> pro Monat"></canvas>
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
