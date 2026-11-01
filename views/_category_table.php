<div class="cat-list">
    <div class="cat-row head"><span>Kategorie</span><span class="num">Anz.</span><span class="num">Summe</span><span class="num">Anteil</span></div>
    <?php foreach ($breakdown['cats'] as $c): ?>
        <?php $hasSubs = (bool) $c['subs']; ?>
        <details class="cat-item" <?= $hasSubs ? '' : 'data-nosubs' ?>>
            <summary class="cat-row">
                <span class="cat-name">
                    <span class="chev" aria-hidden="true"></span>
                    <a href="?p=umsaetze&amp;kat=<?= urlencode($c['kategorie']) ?>"><?= h($c['kategorie']) ?></a>
                </span>
                <span class="num muted"><?= $c['n'] ?></span>
                <span class="num <?= amount_class($c['summe']) ?>"><?= fmt_eur($c['summe']) ?></span>
                <span class="num share"><span class="bar"><span style="width: <?= min(100, round($c['anteil'])) ?>%"></span></span><?= number_format($c['anteil'], 1, ',', '') ?> %</span>
            </summary>
            <?php foreach ($c['subs'] as $s): ?>
                <div class="cat-row sub">
                    <span><a href="?p=umsaetze&amp;kat=<?= urlencode($c['kategorie'] . '::' . $s['name']) ?>"><?= h($s['name']) ?></a></span>
                    <span class="num muted"><?= $s['n'] ?></span>
                    <span class="num"><?= fmt_eur($s['summe']) ?></span>
                    <span class="num muted"><?= $c['summe'] ? number_format($s['summe'] / $c['summe'] * 100, 0, ',', '') . ' %' : '' ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($hasSubs):
                $rest = $c['summe'] - array_sum(array_column($c['subs'], 'summe'));
                $restN = $c['n'] - array_sum(array_column($c['subs'], 'n'));
                if ($restN > 0): ?>
                <div class="cat-row sub">
                    <span class="muted">ohne Unterkategorie</span>
                    <span class="num muted"><?= $restN ?></span>
                    <span class="num"><?= fmt_eur($rest) ?></span>
                    <span class="num muted"><?= $c['summe'] ? number_format($rest / $c['summe'] * 100, 0, ',', '') . ' %' : '' ?></span>
                </div>
            <?php endif; endif; ?>
        </details>
    <?php endforeach; ?>
</div>
