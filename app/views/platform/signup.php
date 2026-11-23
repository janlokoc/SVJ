<div class="wrap page narrow">
  <h1>Založení webu</h1>
  <ol class="signup-steps" aria-label="Postup">
    <?php $i = 0; foreach ($steps as $href => $label): $i++; ?>
      <li<?= $i === $step ? ' aria-current="step"' : '' ?> class="<?= $i < $step ? 'is-done' : '' ?>"><?php if ($i < $step): ?><a href="<?= url($href) ?>"><?= $i ?>. <?= e($label) ?></a><?php else: ?><?= $i ?>. <?= e($label) ?><?php endif ?></li>
    <?php endforeach ?>
  </ol>
  <?php if (!empty($_SESSION['ref']['name'])): ?><div class="flash flash-ok">Doporučilo Vás <strong><?= e($_SESSION['ref']['name']) ?></strong>.<?= pset_int('referral_welcome', 0, 100000) ? ' Na první roční platbu za edici dostanete slevu ' . money_czk(pset_int('referral_welcome', 0, 100000)) . '.' : '' ?> Web je i tak zdarma.</div><?php endif ?>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if ($step === 1): ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Název společenství <input type="text" name="name" id="signup-name" value="<?= e($s['name']) ?>" required minlength="3" maxlength="120" placeholder="SVJ Zahradní 12" autofocus></label>
    <label>Adresa webu <span class="slug-row"><?php if (platform_main_paths()): ?><span><?= e(platform_main_domain()) ?>/</span><?php endif ?><input type="text" name="slug" data-slug-fixed="<?= e(platform_main_domain()) ?>" aria-describedby="slug-fixed-note" id="signup-slug" value="<?= e($s['slug']) ?>" pattern="[a-z0-9][a-z0-9\-]{1,38}" maxlength="39" required placeholder="zahradni"
      data-slug-from="#signup-name" data-slug-check="<?= url('/registrace/adresa') ?>" data-slug-status="#slug-status" autocomplete="off"><?php if (!platform_main_paths()): ?><span>.<?= e(platform_main_domain()) ?></span><?php endif ?></span></label>
    <p class="hint" id="slug-status" role="status">Doplňte jen název – část <?= e(platform_main_paths() ? platform_main_domain() . '/' : '.' . platform_main_domain()) ?> je pevná. Malá písmena bez diakritiky, číslice a pomlčka; adresu později nejde změnit.</p>
    <label>Adresa domu (nepovinné) <input type="text" name="address" value="<?= e($s['address']) ?>" maxlength="200" placeholder="Zahradní 12, Brno"></label>
    <div class="actions"><button class="btn btn-big" type="submit">Pokračovat na vzhled</button></div>
  </form>
  <?php elseif ($step === 2): ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <p>Vyberte šablonu a barvy webu <strong><?= e(tenant_address($s['slug'])) ?></strong>. Obojí můžete později kdykoli změnit v nastavení.</p>
    <p class="h-small">Šablona</p>
    <p class="hint">Šablona Klasická je v edici Zdarma. Ostatní patří do edice Standard – když si některou vyberete, dostanete ji na <?= PLAN_TRIAL_DAYS ?> dní na zkoušku a potom se web vrátí ke Klasické, dokud nepřejdete na Standard.</p>
    <div class="look-cards" role="radiogroup" aria-label="Šablona webu">
      <?php foreach (array_diff_key(STYLES, array_flip(PREMIUM_STYLES)) as $style => $label): $look = $s['look'] ?: 'les'; $miniName = trim(preg_replace('/^(SVJ|Společenství vlastníků( jednotek)?)\s+/iu', '', $s['name'])); ?>
        <label class="look-card"><input type="radio" name="style" value="<?= $style ?>"<?= $s['style'] === $style ? ' checked' : '' ?>><?php require __DIR__ . '/_mini.php'; ?><span><?= e($label) ?></span></label>
      <?php endforeach ?>
    </div>
    <p class="h-small">Barvy</p>
    <div class="look-picker" role="radiogroup" aria-label="Barvy webu">
      <?php foreach (array_diff_key(LOOKS, array_flip(PREMIUM_LOOKS)) as $k => $label): ?>
        <label class="look look-<?= $k ?>"><input type="radio" name="look" value="<?= $k ?>"<?= ($s['look'] ?: 'les') === $k ? ' checked' : '' ?>><span class="look-swatch" aria-hidden="true"><i></i><i></i><i></i></span><span><?= e($label) ?></span></label>
      <?php endforeach ?>
    </div>
    <div class="actions"><button class="btn btn-big" type="submit">Pokračovat na účet správce</button><a class="btn btn-ghost" href="<?= url('/registrace') ?>">Zpět</a></div>
  </form>
  <?php else: $trial = 0; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <p>Poslední krok: účet správce webu <strong><?= e(tenant_address($s['slug'])) ?></strong>. Další správce i obyvatele pak přidáte v administraci.</p>
    <label>Jméno a příjmení <input type="text" name="person" value="<?= e($s['person']) ?>" required minlength="3" maxlength="120" autocomplete="name" autofocus></label>
    <label>E-mail <input type="email" name="email" value="<?= e($s['email']) ?>" required autocomplete="username"></label>
    <label>Heslo (alespoň 10 znaků) <input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <p class="hp" aria-hidden="true"><label>Web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
    <label class="check"><input type="checkbox" name="agree" required> <span>Souhlasím s <a href="<?= url('/podminky') ?>" target="_blank" rel="noopener">obchodními podmínkami</a> a se <a href="<?= url('/zpracovani-udaju') ?>" target="_blank" rel="noopener">smlouvou o zpracování osobních údajů</a> a beru na vědomí <a href="<?= url('/ochrana-udaju') ?>" target="_blank" rel="noopener">zásady ochrany osobních údajů</a> <small>Web běží v edici Zdarma bez časového omezení a nic neplatíte. Placenou edici si můžete objednat později v administraci.</small></span></label>
    <div class="actions"><button class="btn btn-big" type="submit">Založit web zdarma</button><a class="btn btn-ghost" href="<?= url('/registrace/vzhled') ?>">Zpět</a></div>
  </form>
  <?php endif ?>
</div>
