<div class="wrap page auth">
  <div class="card auth-card">
    <h1>Ověření přihlášení</h1>
    <p>Zadejte šestimístný kód z ověřovací aplikace v telefonu.</p>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
    <form method="post" action="<?= url('/prihlaseni/overeni') ?>" class="form">
      <?= csrf_field() ?>
      <label>Kód <input type="text" name="code" id="totp-code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
      <button class="btn btn-big" type="submit">Přihlásit se</button>
    </form>
    <p class="hint">Nemáte telefon po ruce nebo jste o aplikaci přišli? Ověření Vám může vypnout jiný správce webu.</p>
  </div>
</div>
