<?php $flash = flash(); $super = $GLOBALS['SUPER'] ?? null; $path = $GLOBALS['PATH']; ?>
<!doctype html>
<html lang="cs" data-look="grafit"<?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(($title ?? '') . ' · Správa platformy SVJ') ?></title>
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= asset('icon-sluzba-180.png') ?>">
<meta name="apple-mobile-web-app-title" content="<?= e(pset('service_name')) ?>">
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
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="hlavni-menu"><?= icon('menu') ?><span>Menu</span></button>
    <nav class="site-nav" id="hlavni-menu" aria-label="Menu platformy">
      <?php
      // Hlavní položky nahoře, méně časté pod „Další“ – menu se vejde na jeden řádek.
      $claimed = (int) pq("SELECT COUNT(*) FROM payments WHERE status = 'claimed'")->fetchColumn();
      $newReports = reports_open_count();
      $consoleMain = [['/', 'Společenství', $path === '/' || str_starts_with($path, '/svj'), 0], ['/platby', 'Platby', str_starts_with($path, '/platby'), $claimed],
          ['/obchod', 'Obchod', $path === '/obchod', 0], ['/hlaseni', 'Hlášení', str_starts_with($path, '/hlaseni'), $newReports],
          ['/nastaveni', 'Nastavení', $path === '/nastaveni', 0]];
      $consoleMore = [['/partneri', 'Partneři'], ['/vyuziti', 'Využití funkcí'], ['/kredit', 'Kredit služeb'], ['/rejstriky', 'Rejstříky'], ['/zajem', 'Zájem o funkce'], ['/zaznamy', 'Záznamy']];
      $moreActive = in_array($path, array_column($consoleMore, 0), true) || str_starts_with($path, '/partneri');
      ?>
      <ul>
        <?php foreach ($consoleMain as [$href, $label, $active, $count]): ?>
        <li><a href="<?= url($href) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($count): ?> <span class="count"><?= $count ?></span><?php endif ?></a></li>
        <?php endforeach ?>
        <li class="nav-more"><details<?= $moreActive ? ' class="is-active"' : '' ?>><summary>Další</summary>
          <ul><?php foreach ($consoleMore as [$href, $label]): ?><li><a href="<?= url($href) ?>"<?= $path === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li><?php endforeach ?></ul>
        </details></li>
      </ul>
      <div class="nav-user">
        <a class="user-link" href="<?= url('/ucet') ?>"><?= icon('user') ?><span class="user-id"><strong><?= e($super['name']) ?></strong><small>hlavní správce</small></span></a>
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
  <?php if ($super && empty($super['totp_enabled']) && $path !== '/ucet'): ?><div class="wrap"><div class="flash flash-info console-2fa">Konzole má přístup ke všem webům. <strong>Zapněte si ověření kódem z telefonu</strong> (dvoufázové ověření) – heslo samo nestačí. <a href="<?= url('/ucet') ?>">Zapnout v Účtu</a></div></div><?php endif ?>
  <?= $content ?>
</main>
<footer class="wrap console-foot"><a href="<?= e(platform_url('/')) ?>">Úvodní stránka služby</a><?= theme_switch() ?></footer>
</body>
</html>
