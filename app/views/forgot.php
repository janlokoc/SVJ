<div class="wrap page auth">
  <div class="card auth-card">
    <h1>Zapomenuté heslo</h1>
    <?php if (!$mailReady): ?>
      <p>Web zatím neumí posílat e-maily, takže nové heslo Vám musí nastavit výbor SVJ. Kontakt najdete na stránce <a href="<?= url('/kontakty') ?>">Kontakty</a>.</p>
    <?php elseif ($done): ?>
      <div class="flash" role="status">Pokud u nás účet s tímto e-mailem existuje, poslali jsme na něj odkaz pro nastavení nového hesla. Platí jednu hodinu.</div>
      <p class="hint">E-mail nepřišel? Podívejte se do spamu, nebo se obraťte na výbor SVJ.</p>
    <?php else: ?>
      <p>Zadejte e-mail, kterým se přihlašujete. Pošleme Vám odkaz, přes který si nastavíte nové heslo.</p>
      <form method="post" action="<?= url('/zapomenute-heslo') ?>" class="form">
        <?= csrf_field() ?>
        <label>E-mail <input type="email" name="email" id="forgot-email" autocomplete="username" required autofocus></label>
        <button class="btn btn-big" type="submit">Poslat odkaz</button>
      </form>
    <?php endif ?>
    <p class="hint"><a href="<?= url('/prihlaseni') ?>">← Zpět na přihlášení</a></p>
  </div>
</div>
