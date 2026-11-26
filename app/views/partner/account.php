<div class="wrap page narrow">
  <header class="page-head"><h1>Můj účet</h1><p class="lead"><?= e($me['email']) ?> · <?= e($partner['name']) ?></p></header>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form"><h2>Upozornění</h2><?= csrf_field() ?><input type="hidden" name="action" value="notify">
    <label class="check"><input type="checkbox" name="notify_tickets" value="1"<?= (int) ($me['notify_tickets'] ?? 1) ? ' checked' : '' ?>> Posílat mi e-mailem nová hlášení závad a požadavků z domů, které firma spravuje</label>
    <p class="hint">Hlášení přiřazené konkrétnímu řešiteli chodí už jen jemu.</p>
    <button class="btn btn-small" type="submit">Uložit</button></form>
  <div class="two-col">
    <form method="post" class="card form"><h2>Změna hesla</h2><?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label>Současné heslo <input type="password" name="current_password" autocomplete="current-password" required></label>
      <label>Nové heslo <input type="password" name="new_password" minlength="10" autocomplete="new-password" required></label>
      <label>Nové heslo znovu <input type="password" name="new_password2" minlength="10" autocomplete="new-password" required></label>
      <button class="btn" type="submit">Změnit heslo</button></form>
    <div class="card form"><h2>Dvoufázové ověření</h2>
      <?php if ($me['totp_enabled']): ?>
        <p><span class="status status-done">Zapnuto</span></p>
        <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="totp_disable"><label>Pro vypnutí zadejte heslo <input type="password" name="current_password" required></label><button class="btn btn-ghost" type="submit">Vypnout</button></form>
      <?php elseif ($totpSetup): ?>
        <?php $svc = pset('service_name') . ' – partner'; ?>
        <p>V ověřovací aplikaci (Microsoft Authenticator, Google Authenticator…) přidejte účet <strong>naskenováním QR kódu</strong>:</p>
        <div class="totp-qr"><?= totp_qr($totpSetup, $svc, $me['email'], platform_main_domain()) ?></div>
        <p class="hint">V telefonu: <a href="<?= e(totp_uri($totpSetup, $svc, $me['email'])) ?>">otevřít v ověřovací aplikaci</a>. Nebo klíč zadejte ručně:<br><code class="totp-key"><?= e(trim(chunk_split($totpSetup, 4, ' '))) ?></code></p>
        <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="totp_confirm"><label>Kód z aplikace <input type="text" name="code" inputmode="numeric" maxlength="7" required></label><button class="btn" type="submit">Zapnout</button></form>
      <?php else: ?>
        <p>Účet má přístup do administrace všech domů Vaší firmy – dvoufázové ověření důrazně doporučujeme.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="totp_start"><button class="btn" type="submit">Nastavit</button></form>
      <?php endif ?>
    </div>
  </div>
  <?= function_exists('sso_account_box') && empty($GLOBALS['PARTNER_DEMO']) ? sso_account_box('partner', (int) $me['id']) : '' ?>
</div>
