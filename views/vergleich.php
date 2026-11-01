<?php
$y = $yoy;
$withPeriod = false;
$keep = ['jahr' => $y['year']];
require __DIR__ . '/_filter.php';

$delta = function (float $now, float $prev): string {
    if ($prev <= 0.005) {
        return '<span class="muted">—</span>';
    }
    $pct = ($now - $prev) / $prev * 100;
    // bei Ausgaben ist "mehr" schlecht
    return '<span class="' . ($pct > 0 ? 'neg' : 'pos') . '">' . h(fmt_pct($pct)) . '</span>';
};
?>

<?php if ($empty): ?>
    <div class="card empty"><p class="muted">Noch keine Daten.</p></div>
<?php return; endif; ?>

<nav class="chips" aria-label="Jahr">
    <?php foreach ($years as $yr): ?>
        <a href="?p=vergleich&amp;jahr=<?= $yr ?>" class="chip <?= $yr === $y['year'] ? 'active' : '' ?>"><?= $yr ?></a>
    <?php endforeach; ?>
</nav>

<section class="kpis row">
    <div class="card kpi">
        <span class="kpi-label">Ausgaben <?= $y['year'] ?><?= $y['is_current'] ? ' bis heute' : '' ?></span>
        <span class="kpi-value"><?= fmt_eur($y['sum_aus']) ?></span>
    </div>
    <div class="card kpi">
        <span class="kpi-label">Ausgaben <?= $y['prev'] ?><?= $y['is_current'] ? ' bis ' . h(fmt_date($y['prev'] . '-' . $y['cut'])) : '' ?></span>
        <span class="kpi-value"><?= fmt_eur($y['sum_aus_vj']) ?></span>
    </div>
    <div class="card kpi">
        <span class="kpi-label">Veränderung</span>
        <span class="kpi-value"><?= $delta($y['sum_aus'], $y['sum_aus_vj']) ?></span>
        <span class="kpi-sub"><?= fmt_eur($y['sum_aus'] - $y['sum_aus_vj'], true) ?></span>
    </div>
</section>

<section class="card">
    <div class="card-head"><h2>Ausgaben pro Monat</h2></div>
    <div class="chart-box tall">
        <canvas data-chart="yoy" data-series="<?= json_attr([
            'labels' => array_slice(MONATE_KURZ, 1),
            'year' => (string) $y['year'], 'prev' => (string) $y['prev'],
            'now' => array_map(fn ($m) => $m['has'] ? round($m['aus'], 2) : null, array_values($y['months'])),
            'vj' => array_map(fn ($m) => $m['has_vj'] ? round($m['aus_vj'], 2) : null, array_values($y['months'])),
        ]) ?>" role="img" aria-label="Monatliche Ausgaben im Vergleich zum Vorjahr"></canvas>
    </div>
</section>

<div class="grid-2">
    <section class="card flush">
        <div class="card-head pad"><h2>Monate</h2></div>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Monat</th><th class="num">Ausgaben <?= $y['year'] ?></th><th class="num"><?= $y['prev'] ?></th><th class="num">Δ</th><th class="num">Einnahmen <?= $y['year'] ?></th></tr></thead>
            <tbody>
            <?php foreach ($y['months'] as $m => $row): if (!$row['has'] && !$row['has_vj']) continue; ?>
                <tr>
                    <td><?= h(MONATE[$m]) ?></td>
                    <td class="num"><?= $row['has'] ? fmt_eur($row['aus']) : '—' ?></td>
                    <td class="num muted"><?= $row['has_vj'] ? fmt_eur($row['aus_vj']) : '—' ?></td>
                    <td class="num"><?= $row['has'] ? $delta($row['aus'], $row['aus_vj']) : '' ?></td>
                    <td class="num pos"><?= $row['has'] ? fmt_eur($row['ein']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <section class="card flush">
        <div class="card-head pad"><h2>Kategorien</h2><?php if ($y['is_current']): ?><span class="muted small">jeweils 01.01. bis <?= h(substr(fmt_date('2000-' . $y['cut']), 0, 6)) ?></span><?php endif; ?></div>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Kategorie</th><th class="num"><?= $y['year'] ?></th><th class="num"><?= $y['prev'] ?></th><th class="num">Δ</th></tr></thead>
            <tbody>
            <?php foreach ($y['cats'] as $c): ?>
                <tr>
                    <td><?= h($c['kat']) ?></td>
                    <td class="num"><?= fmt_eur($c['aus']) ?></td>
                    <td class="num muted"><?= fmt_eur($c['aus_vj']) ?></td>
                    <td class="num"><?= $delta($c['aus'], $c['aus_vj']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
</div>
