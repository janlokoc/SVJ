<?php
$path = $GLOBALS['PATH'];
$u = user();
$nav = ['/' => 'Úvod', '/aktuality' => 'Aktuality', '/dokumenty' => 'Dokumenty', '/kontakty' => 'Kontakty'];
if ($u) {
    $nav += ['/diskuze' => 'Diskuze', '/zavady' => 'Závady'];
}
$active = fn(string $href) => $href === '/' ? $path === '/' : str_starts_with($path, $href);
$adminNav = ['/admin' => 'Přehled', '/admin/aktuality' => 'Aktuality', '/admin/dokumenty' => 'Dokumenty', '/admin/zpravy' => 'E-maily', '/admin/terminy' => 'Termíny',
    '/admin/kontakty' => 'Kontakty', '/admin/jednotky' => 'Jednotky', '/admin/uzivatele' => 'Uživatelé', '/admin/zavady' => 'Závady', '/admin/nastaveni' => 'Nastavení', '/admin/zaznamy' => 'Záznamy'];
$inAdmin = str_starts_with($path, '/admin') && is_admin();
$flash = flash();
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(!empty($title) ? $title . ' · SVJ Tábor 48' : 'SVJ Tábor 48 a–d, Brno') ?></title>
<meta name="description" content="Společenství vlastníků jednotek Tábor 48 a–d, Brno – aktuality, dokumenty a kontakty.">
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script src="<?= asset('app.js') ?>" defer></script>
</head>
<body<?= $inAdmin ? ' class="in-admin"' : '' ?>>
<a class="skip" href="#obsah">Přeskočit na obsah</a>
<header class="site-header">
  <div class="wrap header-row">
    <a class="brand" href="<?= url('/') ?>" aria-label="SVJ Tábor 48 – úvodní stránka">
      <svg class="brand-mark" viewBox="0 0 44 32" aria-hidden="true"><rect x="1" y="9" width="9" height="22" rx="1.5"/><rect x="12" y="3" width="9" height="28" rx="1.5"/><rect x="23" y="9" width="9" height="22" rx="1.5"/><rect x="34" y="3" width="9" height="28" rx="1.5"/></svg>
      <span class="brand-text"><strong>Tábor 48</strong><small>SVJ · vchody a–d</small></span>
    </a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="hlavni-menu"><?= icon('menu') ?><span>Menu</span></button>
    <nav class="site-nav" id="hlavni-menu" aria-label="Hlavní menu">
      <ul>
        <?php foreach ($nav as $href => $label): ?>
          <li><a href="<?= url($href) ?>"<?= $active($href) ? ' aria-current="page"' : '' ?>><?= $label ?></a></li>
        <?php endforeach ?>
      </ul>
      <div class="nav-user">
        <?php if ($u): ?>
          <?php if ($u['role'] === 'admin'): ?><a class="btn btn-small btn-accent" href="<?= url('/admin') ?>">Administrace</a><?php endif ?>
          <a class="user-link" href="<?= url('/muj-ucet') ?>"><?= icon('user') ?><span><?= e($u['name']) ?></span></a>
          <form method="post" action="<?= url('/odhlaseni') ?>"><?= csrf_field() ?><button class="btn btn-small btn-ghost" type="submit">Odhlásit</button></form>
        <?php else: ?>
          <a class="btn btn-small" href="<?= url('/prihlaseni') ?>"><?= icon('lock') ?> Přihlásit se</a>
        <?php endif ?>
      </div>
    </nav>
  </div>
</header>

<?php if ($inAdmin): ?>
<nav class="admin-nav" aria-label="Administrace">
  <div class="wrap"><span class="admin-label">Administrace</span>
    <ul>
      <?php foreach ($adminNav as $href => $label): ?>
        <li><a href="<?= url($href) ?>"<?= ($href === '/admin' ? $path === '/admin' : str_starts_with($path, $href)) ? ' aria-current="page"' : '' ?>><?= $label ?></a></li>
      <?php endforeach ?>
    </ul>
  </div>
</nav>
<?php endif ?>

<main id="obsah">
  <?php if ($flash): ?>
    <div class="wrap"><div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div></div>
  <?php endif ?>
  <?= $content ?>
</main>

<footer class="site-footer">
  <div class="wrap footer-grid">
    <div>
      <strong class="footer-title">Společenství vlastníků jednotek Tábor 48</strong>
      <p><?= e(setting('address')) ?><br>IČ <?= e(setting('ico')) ?> · účet <?= e(setting('account')) ?></p>
    </div>
    <ul class="footer-links">
      <li><a href="<?= url('/aktuality') ?>">Aktuality</a></li>
      <li><a href="<?= url('/dokumenty') ?>">Dokumenty</a></li>
      <li><a href="<?= url('/kontakty') ?>">Kontakty</a></li>
      <?php if (setting('webmaster_email')): ?><li><a href="mailto:<?= e(setting('webmaster_email')) ?>">Napsat správci webu</a></li><?php endif ?>
    </ul>
  </div>
</footer>
</body>
</html>
