<?php
$path = $GLOBALS['PATH'];
$u = user();
$nav = ['/' => 'Úvod', '/aktuality' => 'Aktuality', '/dokumenty' => 'Dokumenty', '/kontakty' => 'Kontakty'];
$more = $u ? ['/diskuze' => 'Diskuze', '/zavady' => 'Závady a požadavky', '/hlasovani' => 'Hlasování', '/odecty' => 'Odečty měřidel', '/rezervace' => 'Rezervace'] : [];
// Schované části webu z menu vypadnou.
$hiddenPaths = [];
foreach (FEATURES as $key => [$label, $public, $adminPaths]) {
    if (!feature_on($key)) {
        array_push($hiddenPaths, ...$public, ...$adminPaths);
    }
}
$nav = array_diff_key($nav, array_flip($hiddenPaths));
$more = array_diff_key($more, array_flip($hiddenPaths));
$active = fn(string $href) => $href === '/' ? $path === '/' : str_starts_with($path, $href);
$adminNav = ['/admin' => 'Přehled', '/admin/aktuality' => 'Aktuality', '/admin/dokumenty' => 'Dokumenty', '/admin/zpravy' => 'E-maily', '/admin/terminy' => 'Termíny',
    '/admin/hlasovani' => 'Hlasování', '/admin/kontakty' => 'Kontakty', '/admin/jednotky' => 'Jednotky', '/admin/uzivatele' => 'Uživatelé', '/admin/zavady' => 'Závady a požadavky',
    '/admin/odecty' => 'Odečty', '/admin/revize' => 'Revize', '/admin/rezervace' => 'Rezervace', '/admin/prostory' => 'Prostory', '/admin/klice' => 'Klíče a čipy',
    '/admin/nastaveni' => 'Nastavení', '/admin/zaznamy' => 'Záznamy', '/admin/zaloha' => 'Záloha'];
$adminNav = array_diff_key($adminNav, array_flip($hiddenPaths));
$inAdmin = str_starts_with($path, '/admin') && is_admin();
// Hláška se zobrazí jen na skutečné stránce; požadavek prohlížeče na neexistující soubor (např. /favicon.ico) ji nesmí spotřebovat.
$flash = http_response_code() >= 400 && http_response_code() !== 422 ? null : flash();
?>
<!doctype html>
<html lang="<?= e(page_translatable() ? current_lang() : 'cs') ?>" data-look="<?= e(isset(LOOKS[site('look')]) ? site('look') : 'les') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(!empty($title) ? $title . ' · ' . site('name') : trim(site('name') . ' ' . site('accent'))) ?></title>
<meta name="description" content="<?= e(site('full')) ?> – aktuality, dokumenty a kontakty.">
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script src="<?= asset('app.js') ?>" defer></script>
</head>
<body<?= $inAdmin ? ' class="in-admin"' : '' ?>>
<a class="skip" href="#obsah">Přeskočit na obsah</a>
<header class="site-header">
  <div class="wrap header-row">
    <a class="brand" href="<?= url('/') ?>" aria-label="<?= e(site('name')) ?> – úvodní stránka">
      <?php if (setting('logo') !== ''): ?><img class="brand-logo" src="<?= url('/logo') ?>?v=<?= e(substr(setting('logo'), 0, 8)) ?>" alt="">
      <?php else: ?>      <svg class="brand-mark" viewBox="0 0 44 32" aria-hidden="true"><rect x="1" y="9" width="9" height="22" rx="1.5"/><rect x="12" y="3" width="9" height="28" rx="1.5"/><rect x="23" y="9" width="9" height="22" rx="1.5"/><rect x="34" y="3" width="9" height="28" rx="1.5"/></svg><?php endif ?>
      <span class="brand-text"><strong><?= e(site('title')) ?></strong><small><?= e(trim('SVJ' . (site('accent') !== '' ? ' · ' . site('accent') : ''))) ?></small></span>
    </a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="hlavni-menu"><?= icon('menu') ?><span>Menu</span></button>
    <nav class="site-nav" id="hlavni-menu" aria-label="Hlavní menu">
      <ul>
        <?php foreach ($nav as $href => $label): ?>
          <li><a href="<?= url($href) ?>"<?= $active($href) ? ' aria-current="page"' : '' ?>><?= $label ?></a></li>
        <?php endforeach ?>
        <?php if ($more): $moreActive = (bool) array_filter(array_keys($more), $active) || str_starts_with($path, '/terminy'); ?>
          <li class="nav-more"><details<?= $moreActive ? ' class="is-active"' : '' ?>><summary>Pro obyvatele</summary>
            <ul><?php foreach ($more as $href => $label): ?><li><a href="<?= url($href) ?>"<?= $active($href) ? ' aria-current="page"' : '' ?>><?= $label ?></a></li><?php endforeach ?></ul>
          </details></li>
        <?php endif ?>
      </ul>
      <div class="nav-user">
        <?php if ($u): ?>
          <?php if ($u['role'] === 'admin'): ?><a class="btn btn-small btn-accent" href="<?= url('/admin') ?>">Administrace</a><?php endif ?>
          <a class="user-link" href="<?= url('/muj-ucet') ?>"><?= icon('user') ?><span class="user-id"><strong><?= e($u['name']) ?></strong><small><?= e(user_badge($u)) ?></small></span></a>
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
      <strong class="footer-title"><?= e(site('full')) ?></strong>
      <p><?= e(setting('address')) ?><br>IČ <?= e(setting('ico')) ?> · účet <?= e(setting('account')) ?></p>
    </div>
    <ul class="footer-links">
      <li><a href="<?= url('/aktuality') ?>">Aktuality</a></li>
      <li><a href="<?= url('/dokumenty') ?>">Dokumenty</a></li>
      <li><a href="<?= url('/kontakty') ?>">Kontakty</a></li>
      <li><a href="<?= url('/ochrana-udaju') ?>">Ochrana osobních údajů</a></li>
      <li class="lang-switch" translate="no"><?php foreach (LANGS as $code => $langName): ?><a href="<?= url('/jazyk/' . $code) ?>" lang="<?= $code ?>" hreflang="<?= $code ?>"<?= current_lang() === $code ? ' aria-current="true"' : '' ?>><?= strtoupper($code) ?></a><?php endforeach ?></li>
      <?php if (setting('webmaster_email')): ?><li><a href="mailto:<?= e(setting('webmaster_email')) ?>">Napsat správci webu</a></li><?php endif ?>
    </ul>
  </div>
</footer>
</body>
</html>
