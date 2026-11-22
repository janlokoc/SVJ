<?php
$path = $GLOBALS['PATH'];
$u = user();
$nav = ['/' => 'Úvod', '/aktuality' => 'Aktuality', '/dokumenty' => 'Dokumenty', '/kontakty' => 'Kontakty'];
$more = $u ? ['/diskuze' => 'Diskuze', '/zavady' => 'Závady a požadavky', '/hlasovani' => 'Hlasování', '/ankety' => 'Ankety', '/terminy' => 'Termíny', '/platby' => 'Platby a zálohy', '/odecty' => 'Odečty měřidel', '/rezervace' => 'Rezervace'] : [];
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
    '/admin/hlasovani' => 'Hlasování', '/admin/ankety' => 'Ankety', '/admin/ukoly' => 'Úkoly a usnesení', '/admin/dodavatele' => 'Dodavatelé', '/admin/platby' => 'Platby na jednotku',
    '/admin/vyveska' => 'Vývěska', '/admin/kontakty' => 'Kontakty', '/admin/jednotky' => 'Jednotky', '/admin/uzivatele' => 'Uživatelé', '/admin/zavady' => 'Závady a požadavky',
    '/admin/odecty' => 'Odečty', '/admin/revize' => 'Revize', '/admin/rezervace' => 'Rezervace', '/admin/prostory' => 'Prostory', '/admin/klice' => 'Klíče a čipy',
    '/admin/nastaveni' => 'Nastavení', '/admin/zaznamy' => 'Záznamy', '/admin/zaloha' => 'Záloha'];
if (!reports_central()) {
    $adminNav['/admin/hlaseni'] = 'Hlášení';
}
$adminNav = array_diff_key($adminNav, array_flip($hiddenPaths));
$billing = $GLOBALS['BILLING'] ?? ['mode' => 'free', 'until' => '', 'days' => 0];
if ($billing['mode'] !== 'free') {
    $adminNav['/admin/predplatne'] = 'Předplatné';
}
if ($billing['mode'] === 'locked') {
    $nav = $more = [];
    $adminNav = array_intersect_key($adminNav, ['/admin/predplatne' => 1, '/admin/zaloha' => 1]);
}
$billingNote = !is_admin() || $path === '/admin/predplatne' ? '' : match (true) {
    $billing['mode'] === 'grace' => 'Provoz webu není uhrazen. Bez platby bude web brzy pozastaven.',
    $billing['mode'] === 'trial' && $billing['days'] <= 7 => 'Zkušební doba končí ' . cz_date($billing['until']) . '.',
    $billing['mode'] === 'paid' && $billing['days'] <= 7 => 'Předplatné končí ' . cz_date($billing['until']) . '.',
    default => '',
};
$inAdmin = str_starts_with($path, '/admin') && is_admin();
// Hláška se zobrazí jen na skutečné stránce; požadavek prohlížeče na neexistující soubor (např. /favicon.ico) ji nesmí spotřebovat.
$flash = http_response_code() >= 400 && http_response_code() !== 422 ? null : flash();
?>
<!doctype html>
<html lang="<?= e(page_translatable() ? current_lang() : 'cs') ?>" data-look="<?= e(isset(LOOKS[site('look')]) ? site('look') : 'les') ?>" data-style="<?= e(isset(STYLES[site('style')]) ? site('style') : 'klasik') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(!empty($title) ? $title . ' · ' . site('name') : trim(site('name') . ' ' . site('accent'))) ?></title>
<meta name="description" content="<?= e(site('full')) ?> – aktuality, dokumenty a kontakty.">
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="manifest" href="<?= url('/manifest.webmanifest') ?>">
<link rel="apple-touch-icon" href="<?= asset('icon-192.png') ?>">
<meta name="theme-color" content="<?= e(LOOK_COLORS[site('look')] ?? LOOK_COLORS['les']) ?>">
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
        <a class="report-link" href="<?= url('/hlaseni') ?>?z=<?= e(rawurlencode(report_back($GLOBALS['PATH'] ?? '/'))) ?>" data-report-open title="Nahlásit problém nebo nápad"><?= icon('flag') ?><span>Nahlásit</span></a>
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

<?php if (!empty($GLOBALS['DEMO'])): ?>
<div class="demo-bar" translate="no"><div class="wrap">
  <span><strong>Ukázkový web.</strong> Vše si proklikejte, změny se neukládají.</span>
  <span class="demo-actions">Vyzkoušet jako
    <form method="post" action="<?= url('/demo-prihlaseni') ?>"><?= csrf_field() ?><input type="hidden" name="jako" value="resident"><button class="btn btn-small<?= $u && $u['role'] !== 'admin' ? '' : ' btn-ghost' ?>" type="submit">obyvatel</button></form>
    <form method="post" action="<?= url('/demo-prihlaseni') ?>"><?= csrf_field() ?><input type="hidden" name="jako" value="admin"><button class="btn btn-small<?= $u && $u['role'] === 'admin' ? '' : ' btn-ghost' ?>" type="submit">správce (výbor)</button></form>
    <a class="btn btn-small btn-accent" href="<?= e(platform_url('/registrace')) ?>">Založit vlastní web</a></span>
</div></div>
<?php endif ?>
<?php if ($inAdmin):
    // Položky administrace seskupené podle toho, k čemu slouží; skupina bez dostupných položek se nezobrazí.
    $adminGroups = ['Obsah webu' => ['/admin/aktuality', '/admin/dokumenty', '/admin/kontakty', '/admin/terminy', '/admin/vyveska'],
        'Komunikace' => ['/admin/zpravy', '/admin/hlasovani', '/admin/ankety', '/admin/zavady'],
        'Dům a lidé' => ['/admin/jednotky', '/admin/uzivatele', '/admin/platby', '/admin/klice'],
        'Provoz domu' => ['/admin/ukoly', '/admin/odecty', '/admin/revize', '/admin/dodavatele', '/admin/rezervace', '/admin/prostory'],
        'Správa webu' => ['/admin/nastaveni', '/admin/zaznamy', '/admin/zaloha', '/admin/predplatne', '/admin/hlaseni']];
    $adminActive = fn(string $href) => $href === '/admin' ? $path === '/admin' : str_starts_with($path, $href);
    $adminCurrent = 'Přehled';
    foreach ($adminNav as $href => $label) {
        if ($href !== '/admin' && $adminActive($href)) {
            $adminCurrent = $label;
        }
    }
?>
<div class="admin-shell">
<nav class="admin-nav" aria-label="Administrace">
  <button class="admin-toggle" type="button" aria-expanded="false" aria-controls="admin-menu"><?= icon('menu') ?><span>Administrace · <?= e($adminCurrent) ?></span></button>
  <div class="admin-menu" id="admin-menu">
    <?php if (isset($adminNav['/admin'])): ?><a class="admin-home" href="<?= url('/admin') ?>"<?= $adminActive('/admin') ? ' aria-current="page"' : '' ?>>Přehled</a><?php endif ?>
    <?php foreach ($adminGroups as $group => $hrefs): $items = array_intersect_key($adminNav, array_flip($hrefs)); if (!$items) { continue; } ?>
      <div class="admin-group"><span class="admin-label"><?= e($group) ?></span>
        <ul><?php foreach ($hrefs as $href): if (!isset($items[$href])) { continue; } ?><li><a href="<?= url($href) ?>"<?= $adminActive($href) ? ' aria-current="page"' : '' ?>><?= e($items[$href]) ?></a></li><?php endforeach ?></ul>
      </div>
    <?php endforeach ?>
  </div>
</nav>
<?php endif ?>

<main id="obsah">
  <?php if ($billingNote !== ''): ?><div class="wrap"><div class="flash flash-info bill-note" translate="no"><?= e($billingNote) ?> <a href="<?= url('/admin/predplatne') ?>">Zobrazit platební údaje</a></div></div><?php endif ?>
  <?php if ($flash): ?>
    <div class="wrap"><div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div></div>
  <?php endif ?>
  <?= $content ?>
</main>
<?php if ($inAdmin): ?></div><?php endif ?>

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
      <?php if ($u): ?><li><a href="<?= url('/hlaseni') ?>#moje">Moje hlášení</a></li><?php endif ?>
      <li class="lang-switch" translate="no"><?php foreach (LANGS as $code => $langName): ?><a href="<?= url('/jazyk/' . $code) ?>" lang="<?= $code ?>" hreflang="<?= $code ?>"<?= current_lang() === $code ? ' aria-current="true"' : '' ?>><?= strtoupper($code) ?></a><?php endforeach ?></li>
      <?php if (setting('webmaster_email')): ?><li><a href="mailto:<?= e(setting('webmaster_email')) ?>">Napsat správci webu</a></li><?php endif ?>
    </ul>
  </div>
</footer>
<?php require __DIR__ . '/reports/_widget.php'; ?>
</body>
</html>
