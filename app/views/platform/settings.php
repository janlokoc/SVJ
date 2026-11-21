<div class="wrap page narrow">
  <h1>Nastavení služby</h1>
  <p class="lead">Ceny, zkušební doba, platební údaje a texty úvodní stránky na adrese <a href="<?= e(platform_url()) ?>"><?= e(platform_domain()) ?></a>.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <h2>Služba a registrace</h2>
    <label>Název služby <input type="text" name="service_name" value="<?= e($v['service_name']) ?>" required></label>
    <label class="check"><input type="checkbox" name="signup_open"<?= $v['signup_open'] === '1' ? ' checked' : '' ?>> <span>Registrace je otevřená <small>Kdokoli si může z úvodní stránky sám založit web společenství. Po vypnutí zakládáte weby jen Vy.</small></span></label>
    <div class="form-row">
      <label>Zkušební doba zdarma (dní) <input type="number" name="trial_days" value="<?= e($v['trial_days']) ?>" min="0" max="365" required></label>
      <label>Web běží po splatnosti ještě (dní) <input type="number" name="grace_days" value="<?= e($v['grace_days']) ?>" min="0" max="365" required></label>
    </div>
    <h2>Cena</h2>
    <label>Platí se <select name="period"><option value="month"<?= $v['period'] === 'month' ? ' selected' : '' ?>>měsíčně</option><option value="year"<?= $v['period'] === 'year' ? ' selected' : '' ?>>ročně</option></select></label>
    <div class="form-row">
      <label>Cena za období v prvním roce (Kč) <input type="number" name="price_first" value="<?= e($v['price_first']) ?>" min="0" required></label>
      <label>Cena za období v dalších letech (Kč) <input type="number" name="price_next" value="<?= e($v['price_next']) ?>" min="0" required></label>
    </div>
    <label>Ceník podpory a úprav (jedna položka na řádek: název | cena) <textarea name="pricelist" rows="7"><?= e($v['pricelist']) ?></textarea></label>
    <h2>Platby</h2>
    <label>Číslo účtu pro platby <input type="text" name="account" value="<?= e($v['account']) ?>" placeholder="19-2000145399/0800 nebo IBAN"></label>
    <p class="hint">Z čísla účtu se vytváří QR platba. Dokud není vyplněné, vidí správci společenství jen informaci, že platební údaje dostanou e-mailem.</p>
    <label>Název příjemce (nepovinné) <input type="text" name="recipient" value="<?= e($v['recipient']) ?>" maxlength="35"></label>
    <h2>Kontakt a e-maily</h2>
    <div class="form-row">
      <label>Kontaktní e-mail (zobrazuje se na webu) <input type="email" name="contact_email" value="<?= e($v['contact_email']) ?>"></label>
      <label>Odesílatel e-mailů služby <input type="email" name="mail_from" value="<?= e($v['mail_from']) ?>"></label>
    </div>
    <p class="hint">Z adresy odesílatele chodí potvrzení registrace, připomenutí konce zkušební doby a potvrzení plateb. Bez ní se tyto e-maily neposílají. Nově založené weby ji dostanou jako výchozího odesílatele.</p>
    <label>Provozovatel (patička úvodní stránky: název, IČO, sídlo) <textarea name="operator" rows="3"><?= e($v['operator']) ?></textarea></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit</button></div>
  </form>
</div>
