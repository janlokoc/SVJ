<div class="wrap page auth">
  <div class="card auth-card">
    <h1>Nové heslo</h1>
    <p>Nastavte si nové heslo k účtu <strong><?= e($u['email']) ?></strong>.</p>
    <?php require __DIR__ . '/_errors.php'; ?>
    <form method="post" action="<?= url('/obnova-hesla/' . $token) ?>" class="form">
      <?= csrf_field() ?>
      <label>Nové heslo (alespoň 10 znaků) <input type="password" name="password" minlength="10" autocomplete="new-password" required autofocus></label>
      <label>Nové heslo znovu <input type="password" name="password2" minlength="10" autocomplete="new-password" required></label>
      <button class="btn btn-big" type="submit">Uložit nové heslo</button>
    </form>
  </div>
</div>
