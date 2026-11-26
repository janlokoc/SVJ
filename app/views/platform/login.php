<div class="wrap page auth"><div class="card auth-card">
  <h1><?= $verify ? 'Dvoufázové ověření' : 'Správa platformy' ?></h1>
  <?php if ($verify): ?><p>Heslo je v pořádku. Teď otevřete v telefonu ověřovací aplikaci (Microsoft Authenticator, Google Authenticator…) a opište šestimístný kód u účtu <strong><?= e((pset('service_name') !== '' ? pset('service_name') : platform_main_domain()) . ' – správa') ?></strong>. Kód se mění každých 30 sekund.</p><?php endif ?>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
  <?php if ($verify): ?>
    <form method="post" action="<?= url('/overeni') ?>" class="form"><?= csrf_field() ?>
      <label>Šestimístný kód <input type="text" name="code" id="totp-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus></label>
      <button class="btn btn-big" type="submit">Přihlásit se</button></form>
    <?php if (!empty($recovery)): ?>
    <div class="card-soft"><h2>Obnova bez ověřovací aplikace</h2>
      <p>Na serveru je teď ve složce <code>storage</code> soubor <code>obnova-2fa-….txt</code>. Otevřete ho přes FTP (např. FileZilla) a kód z něj opište sem. Po přihlášení bude dvoufázové ověření vypnuté – v části Můj účet ho nastavte znovu. Kód platí 30 minut.</p>
      <form method="post" action="<?= url('/overeni') ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="recovery">
        <label>Kód ze souboru <input type="text" name="recovery_code" autocomplete="off" maxlength="20" required></label>
        <button class="btn" type="submit">Vypnout 2FA a přihlásit se</button></form></div>
    <?php else: ?>
    <details><summary>Nemám přístup k ověřovací aplikaci</summary>
      <p class="hint">Web uloží jednorázový kód do souboru na serveru. Kdo má přístup na hosting přes FTP, kód přečte a dvoufázové ověření tím vypne.</p>
      <form method="post" action="<?= url('/overeni') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="recovery_start"><button class="btn btn-ghost btn-small" type="submit">Vytvořit kód na serveru</button></form></details>
    <?php endif ?>
  <?php else: ?>
    <p>Přihlášení hlavního správce. Obyvatelé a výbory se přihlašují na webu svého společenství.</p>
    <form method="post" action="<?= url('/prihlaseni') ?>" class="form"><?= csrf_field() ?>
      <label>E-mail <input type="email" name="email" value="<?= e(post('email')) ?>" autocomplete="username" required autofocus></label>
      <label>Heslo <input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn btn-big" type="submit">Přihlásit se</button></form>
    <p class="hint"><a href="<?= url('/obnova') ?>">Zapomenuté heslo</a></p>
  <?php endif ?>
</div></div>
