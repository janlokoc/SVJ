<div class="wrap page auth">
  <div class="card auth-card">
    <h1>Vítejte, <?= e($inv['name']) ?></h1>
    <p>Byli jste pozváni na web <?= e(site('name')) ?><?= $unitNames ? ' k jednotce ' . e(implode(', ', $unitNames)) : '' ?>. Nastavte si heslo a účet bude aktivní. Přihlašovat se budete e-mailem <strong><?= e($inv['email']) ?></strong>.</p>
    <?php require __DIR__ . '/_errors.php'; ?>
    <form method="post" action="<?= url('/pozvanka/' . $token) ?>" class="form">
      <?= csrf_field() ?>
      <label>Heslo (alespoň 10 znaků) <input type="password" name="password" minlength="10" autocomplete="new-password" required autofocus></label>
      <label>Heslo znovu <input type="password" name="password2" minlength="10" autocomplete="new-password" required></label>
      <button class="btn btn-big" type="submit">Aktivovat účet</button>
    </form>
  </div>
</div>
