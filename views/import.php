<?php $kontoNamen = array_column($konten, 'konto'); ?>
<div class="grid-2">
    <section class="card">
        <h2>CSV importieren</h2>
        <p class="muted small">
            Sparkassen-Export als „CSV-CAMT“ (Giro, Tagesgeld) oder Kreditkarten-Umsätze.
            Das Format wird automatisch erkannt, doppelte Buchungen werden übersprungen.
        </p>
        <form method="post" enctype="multipart/form-data" class="stack">
            <?= form_fields('import') ?>
            <label class="dropzone">
                <input type="file" name="csv[]" accept=".csv,text/csv" multiple required>
                <span class="dz-text">CSV-Datei(en) auswählen oder hierher ziehen</span>
            </label>
            <div class="form-grid">
                <label>Konto
                    <select name="konto">
                        <option value="">– neues Konto –</option>
                        <?php foreach ($kontoNamen as $k): ?><option><?= h($k) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>oder neuer Name
                    <input type="text" name="konto_neu" placeholder="z.B. Giro, Tagesgeld, Kreditkarte" list="konto-vorschlaege">
                    <datalist id="konto-vorschlaege"><option value="Giro"><option value="Tagesgeld"><option value="Kreditkarte"></datalist>
                </label>
            </div>
            <button class="btn primary" type="submit">Importieren</button>
        </form>
    </section>

    <section class="card">
        <h2>Konten</h2>
        <?php if (!$konten): ?>
            <p class="muted small">Noch keine Konten. Beim ersten Import legst du den Namen fest.</p>
        <?php else: ?>
            <div class="table-scroll">
            <table>
                <thead><tr><th>Konto</th><th>Typ</th><th class="num">Umsätze</th><th>Zeitraum</th><th class="num">Überträge</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($konten as $k): ?>
                    <tr>
                        <td><span class="konto-tag"><?= h($k['konto']) ?></span></td>
                        <td>
                            <form method="post" data-autosubmit class="inline-form">
                                <?= form_fields('konto_typ') ?>
                                <input type="hidden" name="konto" value="<?= h($k['konto']) ?>">
                                <select name="typ" aria-label="Kontotyp von <?= h($k['konto']) ?>">
                                    <?php foreach (KONTO_TYPEN as $tk => $tl): ?>
                                        <option value="<?= h($tk) ?>" <?= $k['typ'] === $tk ? 'selected' : '' ?>><?= h($tl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <noscript><button class="btn small" type="submit">OK</button></noscript>
                            </form>
                        </td>
                        <td class="num"><?= number_format((int) $k['n'], 0, ',', '.') ?></td>
                        <td class="nowrap small"><?= h(fmt_date($k['von'])) ?> – <?= h(fmt_date($k['bis'])) ?></td>
                        <td class="num"><?= (int) $k['ue'] ?></td>
                        <td>
                            <form method="post" data-confirm="Alle <?= (int) $k['n'] ?> Umsätze von „<?= h($k['konto']) ?>“ endgültig löschen?">
                                <?= form_fields('konto_delete') ?>
                                <input type="hidden" name="konto" value="<?= h($k['konto']) ?>">
                                <button class="btn ghost small danger" type="submit">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <form method="post" class="row-form">
                <?= form_fields('detect_transfers') ?>
                <button class="btn small" type="submit">Überträge jetzt erkennen</button>
                <span class="muted small">läuft nach jedem Import automatisch (Fenster <?= (int) cfg('transfer_max_days') ?> Tage)</span>
            </form>
            <p class="muted small">
                <strong>Kontotyp:</strong> Überträge zwischen <em>Alltag</em>-Konten (z.&nbsp;B. Giro → Kreditkarte)
                heben sich auf. Überträge auf ein <em>Sparkonto</em> (Tagesgeld, Depot) erscheinen in der
                Übersicht als „Gespart“, Entnahmen verringern den Wert.
            </p>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <h2>Woher kommt die CSV?</h2>
    <ol class="steps">
        <li>Im Sparkassen-Onlinebanking das Konto öffnen → <em>Umsätze</em>.</li>
        <li>Zeitraum wählen, dann <em>Exportieren</em> → Format <strong>CSV-CAMT V2/V8</strong> (Giro/Tagesgeld) bzw. CSV bei der Kreditkarte.</li>
        <li>Datei hier hochladen. Mehrfaches Hochladen überlappender Zeiträume ist unproblematisch.</li>
    </ol>
</section>
