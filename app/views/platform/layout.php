<?php $flash = flash(); $super = $GLOBALS['SUPER'] ?? null; $path = $GLOBALS['PATH']; ?>
<!doctype html>
<html lang="cs" data-look="grafit"<?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(($title ?? '') . ' · Správa platformy SVJ') ?></title>
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script src="<?= asset('app.js') ?>" defer></script>
</head>
<body class="in-admin">
<?= staging_bar() ?>
<header class="site-header">
  <div class="wrap header-row">
    <a class="brand" href="<?= url('/') ?>"><svg class="brand-mark" viewBox="0 0 44 32" aria-hidden="true"><rect x="1" y="9" width="9" height="22" rx="1.5"/><rect x="12" y="3" width="9" height="28" rx="1.5"/><rect x="23" y="9" width="9" height="22" rx="1.5"/><rect x="34" y="3" width="9" height="28" rx="1.5"/></svg>
      <span class="brand-text"><strong>Platforma SVJ</strong><small>správa všech společenství</small></span></a>
    <?php if ($super): ?>
    <nav class="site-nav" aria-label="Menu platformy">
      <ul>
        <li><a href="<?= url('/') ?>"<?= $path === '/' || str_starts_with($path, '/svj') ? ' aria-current="page"' : '' ?>>Společenství</a></li>
        <li><a href="<?= url('/platby') ?>"<?= str_starts_with($path, '/platby') ? ' aria-current="page"' : '' ?>>Platby<?php $claimed = (int) pq("SELECT COUNT(*) FROM payments WHERE status = 'claimed'")->fetchColumn(); if ($claimed): ?> <span class="count"><?= $claimed ?></span><?php endif ?></a></li>
        <li><a href="<?= url('/hlaseni') ?>"<?= str_starts_with($path, '/hlaseni') ? ' aria-current="page"' : '' ?>>Hlášení<?php $newReports = reports_open_count(); if ($newReports): ?> <span class="count"><?= $newReports ?></span><?php endif ?></a></li>
        <li><a href="<?= url('/zajem') ?>"<?= $path === '/zajem' ? ' aria-current="page"' : '' ?>>Zájem o funkce</a></li>
        <li><a href="<?= url('/nastaveni') ?>"<?= $path === '/nastaveni' ? ' aria-current="page"' : '' ?>>Nastavení služby</a></li>
        <li><a href="<?= url('/zaznamy') ?>"<?= $path === '/zaznamy' ? ' aria-current="page"' : '' ?>>Záznamy</a></li>
      </ul>
      <div class="nav-user">
        <a class="user-link" href="<?= url('/ucet') ?>"><?= icon('user') ?><span class="user-id"><strong><?= e($super['name']) ?></strong><small>hlavní správce</small></span></a>
        <form method="post" action="<?= url('/odhlaseni') ?>"><?= csrf_field() ?><button class="btn btn-small btn-ghost" type="submit">Odhlásit</button></form>
      </div>
    </nav>
    <?php endif ?>
  </div>
</header>
<main id="obsah">
  <?php if ($flash): ?><div class="wrap"><div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div></div><?php endif ?>
  <?= $content ?>
</main>
<footer class="wrap console-foot"><?= theme_switch() ?></footer>
</body>
</html>
