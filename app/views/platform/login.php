<div class="wrap page auth"><div class="card auth-card">
  <h1><?= $verify ? 'Ověření přihlášení' : 'Správa platformy' ?></h1>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
  <?php if ($verify): ?>
    <form method="post" action="<?= url('/overeni') ?>" class="form"><?= csrf_field() ?>
      <label>Kód z ověřovací aplikace <input type="text" name="code" id="totp-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus></label>
      <button class="btn btn-big" type="submit">Přihlásit se</button></form>
  <?php else: ?>
    <p>Přihlášení hlavního správce. Obyvatelé a výbory se přihlašují na webu svého společenství.</p>
    <form method="post" action="<?= url('/prihlaseni') ?>" class="form"><?= csrf_field() ?>
      <label>E-mail <input type="email" name="email" value="<?= e(post('email')) ?>" autocomplete="username" required autofocus></label>
      <label>Heslo <input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn btn-big" type="submit">Přihlásit se</button></form>
  <?php endif ?>
</div></div>
