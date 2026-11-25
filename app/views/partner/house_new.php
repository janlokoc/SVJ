<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/') ?>">← Domy</a></p>
  <h1>Založit web domu</h1>
  <p class="lead">Web bude hned ve Vaší správě a jeho předplatné na Vaší společné faktuře. Do zaplacení běží jako edice Zdarma.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <?php $aresUrl = url('/ares'); require __DIR__ . '/../platform/_ares.php'; ?>
    <label>Název společenství <input type="text" name="name" data-ares-field="site_name" value="<?= e($in['name']) ?>" required placeholder="SVJ Zahrádecká 372"></label>
    <div class="form-row">
      <label>Krátký název na web <input type="text" name="title" data-ares-field="short" value="<?= e($in['title']) ?>" required placeholder="Zahrádecká 372"></label>
      <label>IČO <input type="text" name="ico" data-ares-field="ico" value="<?= e($in['ico']) ?>" inputmode="numeric" maxlength="8" placeholder="12345678"></label>
    </div>
    <label>Celý název (patička, plná moc) <input type="text" name="full" data-ares-field="name" value="<?= e($in['full']) ?>" placeholder="Společenství vlastníků jednotek Zahrádecká 372"></label>
    <label>Adresa domu <input type="text" name="address" data-ares-field="address" value="<?= e($in['address']) ?>" placeholder="Zahrádecká 372/4, Praha 4"></label>
    <label>Adresa webu (nepovinné) <input type="text" name="slug" value="<?= e($in['slug']) ?>" maxlength="39" placeholder="zahradecka-372"></label>
    <p class="hint">Web poběží na adrese <code><?= e(tenant_address('…')) ?></code>. Když pole necháte prázdné, vytvoříme ji z krátkého názvu.</p>
    <fieldset>
      <legend>Kontakt na výbor (nepovinné)</legend>
      <div class="form-row">
        <label>Jméno <input type="text" name="contact_name" value="<?= e($in['contact_name']) ?>" autocomplete="off"></label>
        <label>E-mail <input type="email" name="contact_email" value="<?= e($in['contact_email']) ?>" autocomplete="off"></label>
      </div>
      <p class="hint">Pošleme mu pozvánku do administrace webu jako správci. Výbor tak bude vidět, kdo dům spravuje, a může přístup Vaší firmy kdykoli ukončit.</p>
    </fieldset>
    <div class="actions"><button class="btn btn-big" type="submit">Založit web</button><a class="btn btn-ghost" href="<?= url('/') ?>">Zrušit</a></div>
  </form>
</div>
