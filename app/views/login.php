<div class="wrap page auth">
  <div class="card auth-card">
    <h1>Přihlášení</h1>
    <p>Pro obyvatele domu a členy výboru.</p>
    <?php if (!empty($error)): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
    <form method="post" action="<?= url('/prihlaseni') ?>" class="form">
      <?= csrf_field() ?>
      <label>E-mail <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></label>
      <label>Heslo <input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn btn-big" type="submit">Přihlásit se</button>
    </form>
    <p class="hint">Nemáte účet nebo jste zapomněli heslo? Přístup vám založí či obnoví výbor SVJ – viz <a href="<?= url('/kontakty') ?>">Kontakty</a>.</p>
  </div>
</div>
