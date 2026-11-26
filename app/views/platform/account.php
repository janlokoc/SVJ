<div class="wrap page narrow">
  <header class="page-head"><h1>Můj účet</h1><p class="lead"><?= e($super['email']) ?> · hlavní správce platformy</p></header>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if ($newSuper): ?><div class="card card-soft credentials"><h2>Nový hlavní správce</h2><p>Předejte mu přihlašovací údaje bezpečnou cestou; heslo se zobrazuje jen teď.</p><dl><dt>E-mail</dt><dd><code><?= e($newSuper['email']) ?></code></dd><dt>Heslo</dt><dd><code><?= e($newSuper['password']) ?></code></dd></dl></div><?php endif ?>
  <div class="two-col">
    <form method="post" class="card form"><h2>Změna hesla</h2><?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label>Současné heslo <input type="password" name="current_password" autocomplete="current-password" required></label>
      <label>Nové heslo <input type="password" name="new_password" minlength="10" autocomplete="new-password" required></label>
      <label>Nové heslo znovu <input type="password" name="new_password2" minlength="10" autocomplete="new-password" required></label>
      <button class="btn" type="submit">Změnit heslo</button></form>
    <div class="card form"><h2>Dvoufázové ověření</h2>
      <?php if ($super['totp_enabled']): ?>
        <p><span class="status status-done">Zapnuto</span></p>
        <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="totp_disable"><label>Pro vypnutí zadejte heslo <input type="password" name="current_password" required></label><button class="btn btn-ghost" type="submit">Vypnout</button></form>
      <?php elseif ($totpSetup): ?>
        <?php $svc = (pset('service_name') !== '' ? pset('service_name') . ' – správa' : platform_main_domain() . ' – správa') . (str_starts_with(platform_main_domain(), 'staging.') ? ' (staging)' : ''); ?>
        <p>V ověřovací aplikaci (Microsoft Authenticator, Google Authenticator…) přidejte účet <strong>naskenováním QR kódu</strong>:</p>
        <div class="totp-qr"><?= totp_qr($totpSetup, $svc, $super['email'], platform_main_domain()) ?></div>
        <p class="hint">V telefonu: <a href="<?= e(totp_uri($totpSetup, $svc, $super['email'])) ?>">otevřít v ověřovací aplikaci</a>. Nebo klíč zadejte ručně:<br><code class="totp-key"><?= e(trim(chunk_split($totpSetup, 4, ' '))) ?></code></p>
        <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="totp_confirm"><label>Kód z aplikace <input type="text" name="code" id="totp-confirm" inputmode="numeric" maxlength="7" required></label><button class="btn" type="submit">Zapnout</button></form>
      <?php else: ?>
        <p>Hlavní správce má přístup ke všem společenstvím – dvoufázové ověření důrazně doporučujeme.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="totp_start"><button class="btn" type="submit">Nastavit</button></form>
      <?php endif ?>
    </div>
  </div>
  <?= function_exists('sso_account_box') ? sso_account_box('console', (int) $super['id']) : '' ?>
  <h2>Hlavní správci</h2>
  <table class="table"><thead><tr><th>Jméno</th><th>E-mail</th><th>Poslední přihlášení</th><th>2FA</th></tr></thead><tbody>
    <?php foreach ($admins as $a): ?><tr><td data-label="Jméno"><strong><?= e($a['name']) ?></strong></td><td data-label="E-mail"><?= e($a['email']) ?></td><td data-label="Poslední přihlášení"><?= $a['last_login_at'] ? cz_datetime($a['last_login_at']) : 'zatím ne' ?></td><td data-label="2FA"><?= $a['totp_enabled'] ? 'ano' : 'ne' ?></td></tr><?php endforeach ?>
  </tbody></table>
  <form method="post" class="card form"><h2>Přidat hlavního správce</h2><?= csrf_field() ?><input type="hidden" name="action" value="add_admin">
    <div class="form-row"><label>Jméno a příjmení <input type="text" name="name" id="sa-name" required></label><label>E-mail <input type="email" name="email" id="sa-email" required></label></div>
    <button class="btn" type="submit">Přidat</button></form>
</div>
