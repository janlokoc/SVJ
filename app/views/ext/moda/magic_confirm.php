<?php /* Potvrzení přihlášení odkazem: $token, $name */ ?>
<div class="wrap page auth">
  <div class="card auth-card">
    <h1>Přihlášení odkazem</h1>
    <p>Přihlásíte se jako <strong><?= e($name) ?></strong>.</p>
    <form method="post" action="<?= url('/prihlaseni/odkaz/' . $token) ?>" class="form">
      <?= csrf_field() ?>
      <button class="btn btn-big" type="submit">Přihlásit se</button>
    </form>
    <p class="hint">Odkaz platí jen jednou. Pokud jste o přihlášení nežádali, stránku zavřete.</p>
  </div>
</div>
