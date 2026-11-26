<?php $flash = flash(); $pu = $GLOBALS['PARTNER_USER'] ?? null; $partner = $GLOBALS['PARTNER'] ?? null; $path = $GLOBALS['PATH']; ?>
<!doctype html>
<html lang="cs" data-look="petrolej"<?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(($title ?? '') . ' · Partnerský portál · ' . pset('service_name')) ?></title>
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script src="<?= asset('app.js') ?>" defer></script>
</head>
<body class="in-admin">
<?= staging_bar() ?>
<header class="site-header">
  <div class="wrap header-row">
    <a class="brand" href="<?= url('/') ?>"><svg class="brand-mark" viewBox="0 0 44 32" aria-hidden="true"><rect x="1" y="9" width="9" height="22" rx="1.5"/><rect x="12" y="3" width="9" height="28" rx="1.5"/><rect x="23" y="9" width="9" height="22" rx="1.5"/><rect x="34" y="3" width="9" height="28" rx="1.5"/></svg>
      <span class="brand-text"><strong><?= e($partner['name'] ?? 'Partnerský portál') ?></strong><small>partnerský portál · <?= e(pset('service_name')) ?></small></span></a>
    <?php if ($pu): ?>
    <nav class="site-nav" aria-label="Menu portálu">
      <ul>
        <li><a href="<?= url('/') ?>"<?= $path === '/' || str_starts_with($path, '/domy') ? ' aria-current="page"' : '' ?>>Domy</a></li>
        <li><a href="<?= url('/hlaseni') ?>"<?= $path === '/hlaseni' ? ' aria-current="page"' : '' ?>>Hlášení</a></li>
        <li><a href="<?= url('/fakturace') ?>"<?= str_starts_with($path, '/fakturace') ? ' aria-current="page"' : '' ?>>Fakturace</a></li>
        <li><a href="<?= url('/tym') ?>"<?= $path === '/tym' ? ' aria-current="page"' : '' ?>>Tým</a></li>
      </ul>
      <div class="nav-user">
        <a class="user-link" href="<?= url('/ucet') ?>"><?= icon('user') ?><span class="user-id"><strong><?= e($pu['name']) ?></strong><small><?= $pu['role'] === 'owner' ? 'správce účtu firmy' : 'pracovník' ?></small></span></a>
        <form method="post" action="<?= url('/odhlaseni') ?>"><?= csrf_field() ?><button class="btn btn-small btn-ghost" type="submit">Odhlásit</button></form>
      </div>
    </nav>
    <?php else: ?>
    <a class="btn btn-small btn-ghost console-back" href="<?= e(platform_url('/')) ?>">← Zpět na úvodní stránku</a>
    <?php endif ?>
  </div>
</header>
<main id="obsah">
  <?php if ($flash): ?><div class="wrap"><div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div></div><?php endif ?>
  <?= $content ?>
</main>
<footer class="wrap console-foot"><a href="<?= e(platform_url('/')) ?>"><?= e(pset('service_name')) ?></a><?= theme_switch() ?></footer>
</body>
</html>
