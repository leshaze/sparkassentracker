<div class="login-card card">
    <div class="brand big"><span class="logo" aria-hidden="true"></span><?= h(cfg('app_name')) ?></div>
    <?php if ($setup): ?>
        <p class="muted">Ersteinrichtung: Lege ein Passwort fest. Es wird nur als Hash auf dem Pi gespeichert.</p>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="setup">
            <label>Passwort <input type="password" name="pw" minlength="8" required autofocus autocomplete="new-password"></label>
            <label>Passwort wiederholen <input type="password" name="pw2" minlength="8" required autocomplete="new-password"></label>
            <button class="btn primary" type="submit">Passwort festlegen</button>
        </form>
    <?php else: ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="login">
            <label>Passwort <input type="password" name="pw" required autofocus autocomplete="current-password"></label>
            <button class="btn primary" type="submit">Anmelden</button>
        </form>
    <?php endif; ?>
</div>
