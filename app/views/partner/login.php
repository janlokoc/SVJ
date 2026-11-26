<div class="wrap page auth"><div class="card auth-card">
  <?php if ($mode === 'invite-bad'): ?>
    <h1>Odkaz už neplatí</h1>
    <p>Odkaz pro nastavení hesla vypršel nebo už byl použit. Požádejte kolegu, který Vás pozval, o novou pozvánku, nebo se <a href="<?= url('/') ?>">přihlaste</a>.</p>
  <?php elseif ($mode === 'invite'): ?>
    <h1>Nastavení hesla</h1>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
    <p><?= e($invitee['name']) ?>, nastavte si heslo k partnerskému portálu. Přihlašovat se budete e-mailem <strong><?= e($invitee['email']) ?></strong>.</p>
    <form method="post" class="form"><?= csrf_field() ?>
      <label>Heslo (alespoň 10 znaků) <input type="password" name="password" minlength="10" autocomplete="new-password" required autofocus></label>
      <label>Heslo znovu <input type="password" name="password2" minlength="10" autocomplete="new-password" required></label>
      <button class="btn btn-big" type="submit">Uložit heslo a přihlásit se</button></form>
  <?php elseif ($mode === 'verify'): ?>
    <h1>Dvoufázové ověření</h1>
    <p>Heslo je v pořádku. Teď otevřete v telefonu ověřovací aplikaci (Microsoft Authenticator, Google Authenticator…) a opište šestimístný kód u účtu partnerského portálu. Kód se mění každých 30 sekund.</p>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
    <form method="post" action="<?= url('/overeni') ?>" class="form"><?= csrf_field() ?>
      <label>Kód z ověřovací aplikace <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus></label>
      <button class="btn btn-big" type="submit">Přihlásit se</button></form>
  <?php else: ?>
    <h1>Partnerský portál</h1>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
    <p>Přihlášení pro správcovské firmy. Výbory a obyvatelé se přihlašují na webu svého domu.</p>
    <form method="post" action="<?= url('/prihlaseni') ?>" class="form"><?= csrf_field() ?>
      <label>E-mail <input type="email" name="email" value="<?= e(post('email')) ?>" autocomplete="username" required autofocus></label>
      <label>Heslo <input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn btn-big" type="submit">Přihlásit se</button></form>
    <?= function_exists('sso_buttons') ? sso_buttons('partner') : '' ?>
    <p class="hint">Chcete spravovat weby domů pro své klienty? <?= pset('contact_email') !== '' ? 'Napište nám na <a href="mailto:' . e(pset('contact_email')) . '">' . e(pset('contact_email')) . '</a>.' : 'Ozvěte se nám.' ?></p>
  <?php endif ?>
</div></div>
