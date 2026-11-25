<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/') ?>">← Domy</a></p>
  <h1>Připojit existující web</h1>
  <p class="lead">Dům už web u nás má? Požádejte o přístup – výbor ho schválí ve své administraci a může u toho rozhodnout, zda předplatné bude dál hradit sám, nebo na Vaší společné faktuře.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Adresa webu domu <input type="text" name="address" value="<?= e(post('address')) ?>" required placeholder="<?= e(tenant_address('nazev-domu')) ?>"></label>
    <label>IČO společenství <input type="text" name="ico" value="<?= e(post('ico')) ?>" inputmode="numeric" maxlength="8" placeholder="12345678"></label>
    <p class="hint">IČO ověříme proti údajům webu, aby žádost nemohl poslat kdokoli. Výbor dostane e-mail a uvidí žádost v přehledu administrace.</p>
    <div class="actions"><button class="btn btn-big" type="submit">Požádat o přístup</button><a class="btn btn-ghost" href="<?= url('/') ?>">Zrušit</a></div>
  </form>
</div>
