<div class="wrap page auth"><div class="card auth-card">
  <h1>Nové heslo</h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if ($token !== ''): ?>
    <?php if ($valid): ?>
    <form method="post" action="<?= url('/obnova/' . $token) ?>" class="form"><?= csrf_field() ?>
      <label>Nové heslo <input type="password" name="new_password" minlength="10" autocomplete="new-password" required autofocus></label>
      <label>Nové heslo znovu <input type="password" name="new_password2" minlength="10" autocomplete="new-password" required></label>
      <button class="btn btn-big" type="submit">Nastavit heslo a přihlásit se</button></form>
    <p class="hint">Máte-li zapnuté dvoufázové ověření, zadáte potom ještě kód z aplikace.</p>
    <?php else: ?>
    <p>Odkaz už neplatí (platí 30 minut a jen jednou). <a href="<?= url('/obnova') ?>">Požádat o nový</a></p>
    <?php endif ?>
  <?php elseif ($sent): ?>
    <p>Pokud účet s tímto e-mailem existuje, poslali jsme na něj odkaz pro nastavení nového hesla. Platí 30 minut.</p>
  <?php else: ?>
    <form method="post" action="<?= url('/obnova') ?>" class="form"><?= csrf_field() ?>
      <label>E-mail hlavního správce <input type="email" name="email" autocomplete="username" required autofocus></label>
      <button class="btn btn-big" type="submit">Poslat odkaz na nové heslo</button></form>
  <?php endif ?>
  <p class="hint"><a href="<?= url('/') ?>">Zpět na přihlášení</a></p>
</div></div>
