<?php $withPeriod = $withPeriod ?? true; ?>
<form method="get" class="filterbar" data-autosubmit>
    <input type="hidden" name="p" value="<?= h($page) ?>">
    <?php foreach ($keep ?? [] as $k => $v): ?>
        <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
    <?php endforeach; ?>
    <label class="field">
        <span>Konto</span>
        <select name="konto">
            <option value="">Alle Konten</option>
            <?php foreach (konten() as $k): ?>
                <option value="<?= h($k) ?>" <?= $f['konto'] === $k ? 'selected' : '' ?>><?= h($k) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if ($withPeriod): ?>
        <label class="field">
            <span>Zeitraum</span>
            <select name="preset" data-preset>
                <?php foreach (PRESETS as $k => $l): ?>
                    <option value="<?= h($k) ?>" <?= $f['preset'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field custom-range" <?= $f['preset'] !== 'frei' ? 'hidden' : '' ?>>
            <span>Von</span><input type="date" name="von" value="<?= h($f['von']) ?>">
        </label>
        <label class="field custom-range" <?= $f['preset'] !== 'frei' ? 'hidden' : '' ?>>
            <span>Bis</span><input type="date" name="bis" value="<?= h($f['bis']) ?>">
        </label>
    <?php else: ?>
        <input type="hidden" name="preset" value="<?= h($f['preset']) ?>">
    <?php endif; ?>
    <?php if ($f['from'] || $f['to']): ?>
        <span class="range-hint muted"><?= h($f['from'] ? fmt_date($f['from']) : '…') ?> – <?= h($f['to'] ? fmt_date($f['to']) : '…') ?></span>
    <?php endif; ?>
    <noscript><button class="btn" type="submit">Anwenden</button></noscript>
</form>
