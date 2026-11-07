<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= h(($title ?? '') ? $title . ' · ' : '') ?><?= h(cfg('app_name')) ?></title>
<link rel="stylesheet" href="assets/app.css?v=9">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%231f4e79'/><path d='M8 21h16M8 16h10M8 11h13' stroke='white' stroke-width='3' stroke-linecap='round'/></svg>">
</head>
<body class="<?= !empty($bare) ? 'bare' : '' ?>">
<?php if (empty($bare)): ?>
<header class="top">
    <div class="top-inner">
        <a class="brand" href="?p=uebersicht"><span class="logo" aria-hidden="true"></span><?= h(cfg('app_name')) ?></a>
        <nav class="tabs" aria-label="Hauptnavigation">
            <?php foreach ($pages as $key => $label): ?>
                <a href="?p=<?= h($key) ?>" class="<?= $key === $page ? 'active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="post" class="logout">
            <?= form_fields('logout') ?>
            <button class="btn ghost small" type="submit" title="Abmelden">Abmelden</button>
        </form>
    </div>
</header>
<?php endif; ?>

<main class="wrap">
    <?php foreach (take_flashes() as $fl): ?>
        <div class="flash <?= h($fl['type']) ?>" role="status"><?= h($fl['msg']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>

<script src="assets/chart.umd.min.js"></script>
<script src="assets/app.js?v=9"></script>
</body>
</html>
