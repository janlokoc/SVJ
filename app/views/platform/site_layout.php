<?php $flash = flash(); $service = pset('service_name'); ?>
<!doctype html>
<html lang="cs" data-look="les">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' . $service : $service . ' – web společenství vlastníků hotový za pět minut') ?></title>
<meta name="description" content="Web pro společenství vlastníků jednotek: aktuality, dokumenty, e-maily vlastníkům, shromáždění, hlasování a hlášení závad. První měsíc zdarma.">
<?= $GLOBALS['HEAD_EXTRA'] ?? '' ?>
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script src="<?= asset('app.js') ?>" defer></script>
</head>
<body class="lp">
<a class="skip" href="#obsah">Přeskočit na obsah</a>
<header class="site-header">
  <div class="wrap header-row">
    <a class="brand" href="<?= url('/') ?>"><svg class="brand-mark" viewBox="0 0 44 32" aria-hidden="true"><rect x="1" y="9" width="9" height="22" rx="1.5"/><rect x="12" y="3" width="9" height="28" rx="1.5"/><rect x="23" y="9" width="9" height="22" rx="1.5"/><rect x="34" y="3" width="9" height="28" rx="1.5"/></svg>
      <span class="brand-text"><strong><?= e($service) ?></strong><small>weby společenství vlastníků</small></span></a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="hlavni-menu"><?= icon('menu') ?><span>Menu</span></button>
    <nav class="site-nav" id="hlavni-menu" aria-label="Hlavní menu">
      <ul>
        <li><a href="<?= url('/') ?>#funkce">Čím se lišíme</a></li>
        <li><a href="<?= url('/') ?>#vzhledy">Šablony</a></li>
        <li><a href="<?= url('/') ?>#vse">Funkce</a></li>
        <li><a href="<?= url('/') ?>#cenik">Ceník</a></li>
        <li><a href="<?= url('/') ?>#otazky">Časté otázky</a></li>
      </ul>
      <div class="nav-user"><a class="report-link" href="<?= url('/hlaseni') ?>?z=<?= e(rawurlencode(report_back($GLOBALS['PATH'] ?? '/'))) ?>" data-report-open title="Nahlásit problém nebo nápad"><?= icon('flag') ?><span>Nahlásit</span></a><a class="btn btn-small" href="<?= url('/registrace') ?>">Vyzkoušet zdarma</a></div>
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
      <li><a href="<?= url('/') ?>#cenik">Ceník</a></li>
      <li><a href="<?= url('/registrace') ?>">Založit web</a></li>
      <li><a href="<?= url('/podminky') ?>">Obchodní podmínky</a></li>
      <li><a href="<?= url('/zpracovani-udaju') ?>">Zpracování osobních údajů</a></li>
      <?php if (pset('contact_email') !== ''): ?><li><a href="mailto:<?= e(pset('contact_email')) ?>"><?= e(pset('contact_email')) ?></a></li><?php endif ?>
      <li><a href="<?= url('/sprava') ?>" rel="nofollow">Správa platformy</a></li>
    </ul>
  </div>
</footer>
<?php require __DIR__ . '/../reports/_widget.php'; ?>
</body>
</html>
