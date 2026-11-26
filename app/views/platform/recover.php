<div class="wrap page auth"><div class="card auth-card">
  <h1>Obnova přístupu</h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if (!$started): ?>
    <p>Web uloží jednorázový kód do souboru na serveru. Kdo má přístup na hosting přes FTP (např. FileZilla), kód přečte, nastaví nové heslo a tím zároveň vypne dvoufázové ověření.</p>
    <form method="post" action="<?= url('/obnova') ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="start">
      <label>E-mail hlavního správce <input type="email" name="email" autocomplete="username" required autofocus></label>
      <button class="btn btn-big" type="submit">Vytvořit kód na serveru</button></form>
  <?php else: ?>
    <p>Pokud účet s tímto e-mailem existuje, je teď ve složce <code>storage</code> na hostingu soubor <code>obnova-pristupu-….txt</code>. Otevřete ho přes FTP a kód z něj opište sem. Kód platí 30 minut.</p>
    <form method="post" action="<?= url('/obnova') ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="finish">
      <label>Kód ze souboru <input type="text" name="code" autocomplete="off" maxlength="20" required autofocus></label>
      <label>Nové heslo <input type="password" name="new_password" minlength="10" autocomplete="new-password" required></label>
      <label>Nové heslo znovu <input type="password" name="new_password2" minlength="10" autocomplete="new-password" required></label>
      <button class="btn btn-big" type="submit">Nastavit heslo a přihlásit se</button></form>
    <p class="hint">Dvoufázové ověření bude potom vypnuté – v části Můj účet ho nastavte znovu. <a href="<?= url('/obnova') ?>?znovu=1">Zadat jiný e-mail</a></p>
  <?php endif ?>
  <p class="hint"><a href="<?= url('/') ?>">Zpět na přihlášení</a></p>
</div></div>
