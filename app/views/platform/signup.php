<div class="wrap page narrow">
  <h1>Založení webu</h1>
  <ol class="signup-steps" aria-label="Postup">
    <?php $i = 0; foreach ($steps as $href => $label): $i++; ?>
      <li<?= $i === $step ? ' aria-current="step"' : '' ?> class="<?= $i < $step ? 'is-done' : '' ?>"><?php if ($i < $step): ?><a href="<?= url($href) ?>"><?= $i ?>. <?= e($label) ?></a><?php else: ?><?= $i ?>. <?= e($label) ?><?php endif ?></li>
    <?php endforeach ?>
  </ol>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if ($step === 1): ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Název společenství <input type="text" name="name" id="signup-name" value="<?= e($s['name']) ?>" required minlength="3" maxlength="120" placeholder="SVJ Zahradní 12" autofocus></label>
    <label>Adresa webu <span class="slug-row"><?php if (platform_paths()): ?><span><?= e(platform_domain()) ?>/</span><?php endif ?><input type="text" name="slug" id="signup-slug" value="<?= e($s['slug']) ?>" pattern="[a-z0-9][a-z0-9\-]{1,38}" maxlength="39" required placeholder="zahradni"
      data-slug-from="#signup-name" data-slug-check="<?= url('/registrace/adresa') ?>" data-slug-status="#slug-status" autocomplete="off"><?php if (!platform_paths()): ?><span>.<?= e(platform_domain()) ?></span><?php endif ?></span></label>
    <p class="hint" id="slug-status" role="status">Jen malá písmena bez diakritiky, číslice a pomlčka. Adresu později nejde změnit.</p>
    <label>Adresa domu (nepovinné) <input type="text" name="address" value="<?= e($s['address']) ?>" maxlength="200" placeholder="Zahradní 12, Brno"></label>
    <div class="actions"><button class="btn btn-big" type="submit">Pokračovat na vzhled</button></div>
  </form>
  <?php elseif ($step === 2): ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <p>Vyberte vzhled webu <strong><?= e(tenant_address($s['slug'])) ?></strong>. Později ho můžete kdykoli změnit v nastavení.</p>
    <div class="look-cards" role="radiogroup" aria-label="Vzhled webu">
      <?php $n = 0; foreach (LOOKS as $look => $label): $miniName = trim(preg_replace('/^(SVJ|Společenství vlastníků( jednotek)?)\s+/iu', '', $s['name'])); ?>
        <label class="look-card"><input type="radio" name="look" value="<?= $look ?>"<?= ($s['look'] ?: 'les') === $look ? ' checked' : '' ?>><?php require __DIR__ . '/_mini.php'; ?><span><?= e($label) ?></span></label>
      <?php endforeach ?>
    </div>
    <div class="actions"><button class="btn btn-big" type="submit">Pokračovat na účet správce</button><a class="btn btn-ghost" href="<?= url('/registrace') ?>">Zpět</a></div>
  </form>
  <?php else: $trial = pset_int('trial_days', 0, 365); ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <p>Poslední krok: účet správce webu <strong><?= e(tenant_address($s['slug'])) ?></strong>. Další správce i obyvatele pak přidáte v administraci.</p>
    <label>Jméno a příjmení <input type="text" name="person" value="<?= e($s['person']) ?>" required minlength="3" maxlength="120" autocomplete="name" autofocus></label>
    <label>E-mail <input type="email" name="email" value="<?= e($s['email']) ?>" required autocomplete="username"></label>
    <label>Heslo (alespoň 10 znaků) <input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <p class="hp" aria-hidden="true"><label>Web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
    <label class="check"><input type="checkbox" name="agree" required> <span>Souhlasím s <a href="<?= url('/podminky') ?>" target="_blank" rel="noopener">obchodními podmínkami</a> a se <a href="<?= url('/zpracovani-udaju') ?>" target="_blank" rel="noopener">smlouvou o zpracování osobních údajů</a> <small><?= $trial ? 'Prvních ' . $trial . ' dní je zdarma. ' : '' ?>Potom stojí provoz <?= money_czk(pset_int('price_first', 0, 1000000)) ?> za <?= period_label() ?> v prvním roce a <?= money_czk(pset_int('price_next', 0, 1000000)) ?> za <?= period_label() ?> dále. Bez úhrady se web pozastaví; nic se nestrhává automaticky.</small></span></label>
    <div class="actions"><button class="btn btn-big" type="submit">Založit web<?= $trial ? ' a začít zkušební dobu' : '' ?></button><a class="btn btn-ghost" href="<?= url('/registrace/vzhled') ?>">Zpět</a></div>
  </form>
  <?php endif ?>
</div>
