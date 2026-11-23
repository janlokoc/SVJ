<?php $u = user(); $now = !$u ? 'host' : ($u['role'] === 'admin' ? 'admin' : 'resident'); ?>
<div class="wrap page">
  <header class="page-head"><h1>Vstup do ukázky</h1><p class="lead">Tohle je ukázkový web smyšleného společenství. Není potřeba účet ani heslo – vyberte, jakýma očima se chcete podívat. Mezi pohledy můžete kdykoli přepnout v liště nahoře.</p></header>
  <div class="demo-roles">
    <?php foreach ([
        'host' => ['Návštěvník bez účtu', 'Co uvidí kdokoli, kdo web otevře: úvodní stránka, veřejné aktuality a dokumenty, kontakty na výbor a havarijní čísla. Neveřejné věci zůstanou skryté.', 'Prohlédnout jako návštěvník'],
        'resident' => ['Přihlášený obyvatel', 'Co vidí vlastník nebo nájemník po přihlášení: hlasování, ankety, hlášení závad, platby na jednotku, termíny, zprávy a dokumenty jen pro obyvatele.', 'Vstoupit jako obyvatel'],
        'admin' => ['Přihlášený správce (výbor)', 'Celá administrace: obsah webu, uživatelé a jednotky, hromadné e-maily, hlasování, revize, úkoly, nastavení vzhledu a průvodce nastavením.', 'Vstoupit jako správce'],
    ] as $role => [$title, $desc, $btn]): ?>
    <form method="post" action="<?= url('/demo-prihlaseni') ?>" class="card<?= $now === $role ? ' is-current' : '' ?>"><?= csrf_field() ?><input type="hidden" name="jako" value="<?= $role ?>">
      <h2><?= e($title) ?><?= $now === $role ? ' <span class="tag">teď</span>' : '' ?></h2><p class="muted"><?= e($desc) ?></p>
      <button class="btn btn-big<?= $role === 'resident' ? '' : ' btn-ghost' ?>" type="submit"><?= e($btn) ?></button></form>
    <?php endforeach ?>
  </div>
  <p class="hint">V ukázce se změny neukládají. Vlastní web si založíte zdarma: <a href="<?= e(platform_url('/registrace')) ?>">Založit web</a>.</p>
</div>
