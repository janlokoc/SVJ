<div class="wrap page auth"><div class="card auth-card">
  <h1>Platforma pro více SVJ</h1>
  <p>Vytvořte účet hlavního správce. Ten zakládá weby jednotlivých společenství a přiděluje jim adresy.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Instalační kód <input type="text" name="code" id="p-code" autocomplete="off" required autofocus></label>
    <p class="hint">Kód je v souboru <code>instalacni-kod-platformy-….txt</code> ve složce <code>storage</code> na serveru.</p>
    <label>Jméno a příjmení <input type="text" name="name" value="<?= e(post('name')) ?>" required></label>
    <label>E-mail <input type="email" name="email" value="<?= e(post('email')) ?>" autocomplete="username" required></label>
    <label>Heslo (alespoň 10 znaků) <input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
    <button class="btn btn-big" type="submit">Vytvořit hlavního správce</button>
  </form>
</div></div>
