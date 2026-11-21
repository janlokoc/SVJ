<div class="wrap page auth">
  <div class="card auth-card">
    <h1>První spuštění</h1>
    <p>Web zatím nemá žádného správce. Vytvořte si účet, kterým budete spravovat obsah. Další správce a obyvatele pak přidáte v administraci.</p>
    <?php require __DIR__ . '/_errors.php'; ?>
    <form method="post" action="<?= url('/instalace') ?>" class="form">
      <?= csrf_field() ?>
      <label>Instalační kód <input type="text" name="code" id="setup-code" autocomplete="off" required autofocus></label>
      <p class="hint">Kód najdete v souboru <code>instalacni-kod-….txt</code> ve složce <code>storage</code> na serveru (otevřete ho přes FTP nebo správce souborů hostingu). Chrání web před tím, aby si účet správce vytvořil někdo cizí.</p>
      <label>Jméno a příjmení <input type="text" name="name" value="<?= e($name) ?>" required></label>
      <label>E-mail (přihlašovací jméno) <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required></label>
      <label>Heslo (alespoň 10 znaků) <input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
      <button class="btn btn-big" type="submit">Vytvořit správce</button>
    </form>
  </div>
</div>
