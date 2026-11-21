<div class="wrap page auth">
  <div class="card auth-card">
    <h1>První spuštění</h1>
    <p>Web zatím nemá žádného správce. Vytvořte si účet, kterým budete spravovat obsah. Další správce a obyvatele pak přidáte v administraci.</p>
    <?php require __DIR__ . '/_errors.php'; ?>
    <form method="post" action="<?= url('/instalace') ?>" class="form">
      <?= csrf_field() ?>
      <label>Jméno a příjmení <input type="text" name="name" value="<?= e($name) ?>" required autofocus></label>
      <label>E-mail (přihlašovací jméno) <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required></label>
      <label>Heslo (alespoň 10 znaků) <input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
      <button class="btn btn-big" type="submit">Vytvořit správce</button>
    </form>
  </div>
</div>
