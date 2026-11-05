<?php
$chips = function (string $cat, string $sub, array $kws) { ?>
    <div class="kw-chips">
        <?php foreach ($kws as $kw): ?>
            <form method="post" class="kw-chip">
                <?= form_fields('kw_remove') ?>
                <input type="hidden" name="cat" value="<?= h($cat) ?>">
                <input type="hidden" name="sub" value="<?= h($sub) ?>">
                <input type="hidden" name="kw" value="<?= h($kw) ?>">
                <span><?= h($kw) ?></span>
                <button type="submit" title="Stichwort „<?= h($kw) ?>“ entfernen" aria-label="Entfernen">×</button>
            </form>
        <?php endforeach; ?>
        <form method="post" class="kw-add">
            <?= form_fields('kw_add') ?>
            <input type="hidden" name="cat" value="<?= h($cat) ?>">
            <input type="hidden" name="sub" value="<?= h($sub) ?>">
            <input type="text" name="kw" placeholder="+ Stichwort" aria-label="Stichwort hinzufügen" required>
        </form>
    </div>
<?php }; ?>

<div class="grid-2 top-actions">
    <section class="card">
        <h2>Regeln anwenden</h2>
        <p class="muted small">Nach Änderungen an den Stichworten alle Umsätze neu zuordnen. Manuell zugeordnete (<?= $manual ?>) bleiben unverändert, außer du setzt den Haken.</p>
        <form method="post" class="row-form">
            <?= form_fields('recategorize') ?>
            <label class="check"><input type="checkbox" name="alle" value="1"> auch manuelle überschreiben</label>
            <button class="btn primary" type="submit">Neu kategorisieren</button>
        </form>
        <p class="muted small">
            Stichworte werden in Empfänger, Verwendungszweck und Buchungstext gesucht; das längste passende gewinnt.
            <strong>Kombi-Regeln</strong> wie <code>paypal + steam</code> greifen nur, wenn alle Teile vorkommen,
            und haben Vorrang – praktisch für PayPal, Klarna &amp; Co., bei denen der Händler im Verwendungszweck steht.
        </p>
        <p class="muted small"><?= number_format($counts['Unbekannt'] ?? 0, 0, ',', '.') ?> Umsätze sind noch <a href="?p=umsaetze&amp;kat=Unbekannt">ohne Kategorie</a>.</p>
    </section>
    <section class="card">
        <h2>categories.json</h2>
        <p class="muted small">Gleiches Format wie die Desktop-App – du kannst deine bestehende Datei direkt hochladen.</p>
        <form method="post" enctype="multipart/form-data" class="row-form" data-confirm="Alle aktuellen Regeln werden durch die Datei ersetzt. Fortfahren?">
            <?= form_fields('rules_upload') ?>
            <input type="file" name="json" accept=".json,application/json" required>
            <button class="btn" type="submit">Hochladen</button>
        </form>
        <a class="btn ghost small" href="?p=regeln_export">Aktuelle Regeln herunterladen</a>
    </section>
</div>

<section class="card">
    <p class="muted small">
        <strong>Sparen statt Ausgabe:</strong> Bei einer Kategorie den Haken „Zählt als Sparen“ setzen, wenn das
        Geld eine Rücklage bleibt, auf die du bei Bedarf zurückgreifst (z.&nbsp;B. Tagesgeld). Einzahlungen sind
        dann keine Ausgabe, Entnahmen keine Einnahme, und die Übersicht zeigt deinen echten Überschuss bzw.
        Fehlbetrag. Depot, Bausparen &amp; Co. bleiben ohne Haken normale Ausgaben.
        <?= $spar ? 'Aktuell als Sparen markiert: <strong>' . h(implode(', ', $spar)) . '</strong>.' : 'Aktuell ist keine Kategorie als Sparen markiert.' ?>
    </p>
    <form method="post" class="row-form">
        <?= form_fields('cat_add') ?>
        <label class="field grow"><span>Neue Kategorie</span><input type="text" name="name" required placeholder="z.B. Haustier"></label>
        <button class="btn" type="submit">Anlegen</button>
    </form>
</section>

<div class="cat-rules">
<?php foreach ($cats as $cat => $e): ?>
    <details class="card rule-cat">
        <summary>
            <span class="chev" aria-hidden="true"></span>
            <strong><?= h($cat) ?></strong>
            <?php if (in_array($cat, $spar, true)): ?><span class="badge save" title="Zählt als Sparen, nicht als Ausgabe/Einnahme">Sparen</span><?php endif; ?>
            <span class="muted small"><?= count($e['_keywords']) + array_sum(array_map('count', $e['subs'])) ?> Stichworte<?= $e['subs'] ? ' · ' . count($e['subs']) . ' Unterkategorien' : '' ?></span>
            <span class="spacer"></span>
            <a class="muted small" href="?p=umsaetze&amp;kat=<?= urlencode($cat) ?>"><?= number_format($counts[$cat] ?? 0, 0, ',', '.') ?> Umsätze</a>
        </summary>
        <div class="rule-body">
            <form method="post" class="row-form spar-toggle" data-autosubmit>
                <?= form_fields('cat_spar') ?>
                <input type="hidden" name="cat" value="<?= h($cat) ?>">
                <input type="hidden" name="spar" value="0">
                <label class="check">
                    <input type="checkbox" name="spar" value="1" <?= in_array($cat, $spar, true) ? 'checked' : '' ?>>
                    Zählt als <strong>Sparen</strong> (Vermögensaufbau) – Einzahlungen sind keine Ausgabe, Entnahmen keine Einnahme
                </label>
                <noscript><button class="btn small" type="submit">Speichern</button></noscript>
            </form>
            <div class="rule-block">
                <div class="rule-label">Stichworte (ohne Unterkategorie)</div>
                <?php $chips($cat, '', $e['_keywords']); ?>
            </div>
            <?php foreach ($e['subs'] as $sub => $kws): ?>
                <div class="rule-block sub">
                    <div class="rule-label">
                        › <?= h($sub) ?>
                        <form method="post" class="inline" data-confirm="Unterkategorie „<?= h($sub) ?>“ löschen?">
                            <?= form_fields('sub_delete') ?>
                            <input type="hidden" name="cat" value="<?= h($cat) ?>">
                            <input type="hidden" name="sub" value="<?= h($sub) ?>">
                            <button class="link-btn" type="submit">löschen</button>
                        </form>
                    </div>
                    <?php $chips($cat, $sub, $kws); ?>
                </div>
            <?php endforeach; ?>
            <div class="rule-foot">
                <form method="post" class="row-form">
                    <?= form_fields('sub_add') ?>
                    <input type="hidden" name="cat" value="<?= h($cat) ?>">
                    <input type="text" name="name" placeholder="Neue Unterkategorie" required aria-label="Neue Unterkategorie">
                    <button class="btn small" type="submit">Hinzufügen</button>
                </form>
                <form method="post" data-confirm="Kategorie „<?= h($cat) ?>“ mit allen Stichworten löschen?">
                    <?= form_fields('cat_delete') ?>
                    <input type="hidden" name="cat" value="<?= h($cat) ?>">
                    <button class="btn ghost small danger" type="submit">Kategorie löschen</button>
                </form>
            </div>
        </div>
    </details>
<?php endforeach; ?>
</div>
