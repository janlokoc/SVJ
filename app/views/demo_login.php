<?php $u = user(); $now = !$u ? 'host' : ($u['role'] === 'admin' ? 'admin' : 'resident'); ?>
<div class="wrap page">
  <header class="page-head"><h1>Vstup do ukázky</h1><p class="lead">Vše je smyšlené a nic se neukládá – není potřeba účet ani heslo. Kdo jste?</p></header>
  <div class="demo-paths">
    <a class="card demo-path" href="#svj"><span class="demo-path-kicker">Jsem z výboru nebo bydlím v domě</span><strong>Web společenství</strong>
      <span class="muted">Jak web vidí obyvatel, návštěvník a výbor, který ho spravuje: aktuality, dokumenty, hlasování, závady, platby.</span><span class="btn">Ukázka pro SVJ</span></a>
    <a class="card demo-path" href="<?= e(platform_url('/partner/ukazka')) ?>"><span class="demo-path-kicker">Spravuji domy jako správcovská firma</span><strong>Partnerský portál</strong>
      <span class="muted">Všechny spravované domy na jednom místě: hlášení závad ze všech domů, vstup do administrace každého domu a jedna faktura s množstevní slevou.</span><span class="btn btn-ghost">Ukázka pro správce</span></a>
  </div>
  <h2 id="svj" class="demo-svj-h">Ukázka pro SVJ: jakýma očima se chcete podívat?</h2>
  <p class="muted">Mezi pohledy můžete kdykoli přepnout v liště nahoře.</p>
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
  <div class="demo-edition-pick">
    <h2>V jaké edici?</h2>
    <p class="muted">Ukázka běží v edici <?= e(EDITIONS[plan_edition()]) ?>. Přepněte si, jak web vypadá a co umí ve které edici – i později v liště nahoře.</p>
    <div class="actions"><?php $de = plan_edition(); foreach (EDITIONS as $ek => $en): ?><form method="post" action="<?= url('/demo-edice') ?>"><?= csrf_field() ?><input type="hidden" name="edice" value="<?= $ek ?>"><button class="btn<?= $de === $ek ? '' : ' btn-ghost' ?>" type="submit"<?= $de === $ek ? ' aria-pressed="true"' : '' ?>><?= e($en) ?></button></form><?php endforeach ?></div>
  </div>
  <p class="hint">V ukázce se změny neukládají. Vlastní web si založíte zdarma: <a href="<?= e(platform_url('/registrace')) ?>">Založit web</a>. <a href="<?= e(platform_url('/')) ?>">← Zpět na úvodní stránku <?= e(pset('service_name')) ?></a></p>
</div>
