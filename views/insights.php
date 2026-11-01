<?php $withPeriod = false; require __DIR__ . '/_filter.php'; ?>

<?php if ($empty || !$ins): ?>
    <div class="card empty"><p class="muted">Noch keine Daten für Auswertungen.</p></div>
<?php return; endif; ?>

<p class="muted lead">
    Bezugsmonat <strong><?= h(fmt_month($ins['ref'])) ?></strong>, verglichen mit
    <?= $ins['n_hist'] ?> Vormonat<?= $ins['n_hist'] === 1 ? '' : 'en' ?>. Überträge zählen nicht mit.
    <?php if ($ins['n_hist'] < 3): ?>Mit mehr Monaten Historie werden die Hinweise genauer.<?php endif; ?>
</p>

<?php if (!$ins['items']): ?>
    <div class="card empty"><p>Nichts Auffälliges gefunden.</p></div>
<?php endif; ?>

<div class="insights">
    <?php foreach ($ins['items'] as $i => $it): ?>
        <details class="card insight sev-<?= h($it['sev']) ?>" <?= $i < 3 ? 'open' : '' ?>>
            <summary>
                <span class="sev-dot" aria-hidden="true"></span>
                <span class="sev-label"><?= ['high' => 'Wichtig', 'warn' => 'Hinweis', 'info' => 'Info'][$it['sev']] ?></span>
                <span class="insight-title"><?= h($it['title']) ?></span>
            </summary>
            <ul>
                <?php foreach ($it['details'] as $d): ?><li><?= h($d) ?></li><?php endforeach; ?>
            </ul>
        </details>
    <?php endforeach; ?>
</div>
