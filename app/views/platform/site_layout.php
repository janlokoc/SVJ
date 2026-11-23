<?php $flash = flash(); $service = pset('service_name'); ?>
<!doctype html>
<html lang="cs" data-look="les" data-site="sluzba"<?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' . $service : $service . ' – web společenství vlastníků hotový za pět minut') ?></title>
<meta name="description" content="Web pro společenství vlastníků jednotek: aktuality, dokumenty, e-maily vlastníkům, shromáždění, hlasování a hlášení závad. Základní web zdarma.">
<?= $GLOBALS['HEAD_EXTRA'] ?? '' ?>
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= asset('icon-sluzba-180.png') ?>">
<meta name="apple-mobile-web-app-title" content="<?= e(pset('service_name')) ?>">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script src="<?= asset('app.js') ?>" defer></script>
</head>
<body class="lp">
<?= staging_bar() ?>
<a class="skip" href="#obsah">Přeskočit na obsah</a>
<header class="site-header">
  <div class="wrap header-row">
    <a class="brand" href="<?= url('/') ?>"><svg class="brand-mark" viewBox="0 0 44 32" aria-hidden="true"><rect x="1" y="9" width="9" height="22" rx="1.5"/><rect x="12" y="3" width="9" height="28" rx="1.5"/><rect x="23" y="9" width="9" height="22" rx="1.5"/><rect x="34" y="3" width="9" height="28" rx="1.5"/></svg>
      <span class="brand-text"><strong><?= e($service) ?></strong><small>weby společenství vlastníků</small></span></a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="hlavni-menu"><?= icon('menu') ?><span>Menu</span></button>
    <nav class="site-nav" id="hlavni-menu" aria-label="Hlavní menu">
      <ul>
        <?php $navPath = $GLOBALS['PATH'] ?? '/'; $navDemo = function_exists('demo_tenant') ? demo_tenant() : null; ?>
        <li><a href="<?= url('/funkce') ?>"<?= str_starts_with($navPath, '/funkce') ? ' aria-current="page"' : '' ?>>Funkce</a></li>
        <li><a href="<?= url('/cenik') ?>"<?= str_starts_with($navPath, '/cenik') ? ' aria-current="page"' : '' ?>>Ceník</a></li>
        <?php if ($navDemo): ?><li><a href="<?= e(tenant_url($navDemo, '/vstup')) ?>">Ukázka webu</a></li><?php endif ?>
      </ul>
      <div class="nav-user"><a class="btn btn-small" href="<?= url('/registrace') ?>">Založit web zdarma</a></div>
    </nav>
  </div>
</header>
<main id="obsah">
  <?php if ($flash): ?><div class="wrap"><div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div></div><?php endif ?>
  <?= $content ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-grid">
    <div>
      <strong class="footer-title"><?= e($service) ?></strong>
      <?php $op = operator(); if ($op['name'] !== ''): ?><p><?= e($op['name']) ?><?= $op['address'] !== '' ? ', ' . e($op['address']) : '' ?><?= $op['ico'] !== '' ? '<br>IČO ' . e($op['ico']) : '' ?><?= $op['phone'] !== '' ? ' · ' . e($op['phone']) : '' ?></p><?php endif ?>
    </div>
    <ul class="footer-links">
      <li><a href="<?= url('/cenik') ?>">Ceník</a></li>
      <li><a href="<?= url('/registrace') ?>">Založit web</a></li>
      <li><a href="<?= url('/podminky') ?>">Obchodní podmínky</a></li>
      <li><a href="<?= url('/ochrana-udaju') ?>">Ochrana osobních údajů</a></li>
      <li><a href="<?= url('/zpracovani-udaju') ?>">Smlouva o zpracování údajů</a></li>
      <?php if (pset('contact_email') !== ''): ?><li><a href="mailto:<?= e(pset('contact_email')) ?>"><?= e(pset('contact_email')) ?></a></li><?php endif ?>
      <li><a href="<?= url('/hlaseni') ?>?z=<?= e(rawurlencode(report_back($GLOBALS['PATH'] ?? '/'))) ?>" data-report-open>Nahlásit problém nebo nápad</a></li>
      <li><a href="<?= url('/sprava') ?>" rel="nofollow">Správa platformy</a></li>
      <li><?= theme_switch() ?></li>
    </ul>
  </div>
</footer>
<?php require __DIR__ . '/../reports/_widget.php'; ?>
</body>
</html>
