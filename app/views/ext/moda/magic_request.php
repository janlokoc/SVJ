<?php /* Přihlášení odkazem z e-mailu: $state ('', 'sent', 'blocked', 'nomail'), $email, $mailReady */ ?>
<div class="wrap page auth">
  <div class="card auth-card">
    <h1>Přihlášení odkazem</h1>
    <?php if (!$mailReady || $state === 'nomail'): ?>
      <p>Web zatím neumí posílat e-maily, takže se přihlaste heslem. Pokud ho neznáte, obraťte se na výbor SVJ – kontakt najdete na stránce <a href="<?= url('/kontakty') ?>">Kontakty</a>.</p>
    <?php elseif ($state === 'sent'): ?>
      <div class="flash" role="status">Pokud u nás účet s tímto e-mailem existuje, poslali jsme na něj odkaz pro přihlášení. Platí 15 minut a jen jednou.</div>
      <p class="hint">E-mail nepřišel? Podívejte se do spamu. Odkaz otevřete ve stejném zařízení, ve kterém se chcete přihlásit.</p>
    <?php else: ?>
      <?php if ($state === 'blocked'): ?><div class="flash flash-error" role="alert">Příliš mnoho žádostí. Zkuste to prosím znovu za 15 minut.</div><?php endif ?>
      <p>Nemusíte si pamatovat heslo. Zadejte e-mail, kterým se přihlašujete, a pošleme Vám odkaz – stačí na něj kliknout.</p>
      <form method="post" action="<?= url('/prihlaseni/odkaz') ?>" class="form">
        <?= csrf_field() ?>
        <label>E-mail <input type="email" name="email" id="magic-email" value="<?= e($email) ?>" autocomplete="username" required autofocus></label>
        <button class="btn btn-big" type="submit">Poslat odkaz pro přihlášení</button>
      </form>
      <p class="hint">Máte zapnuté dvoufázové ověření? Po otevření odkazu zadáte ještě kód z aplikace v telefonu.</p>
    <?php endif ?>
    <p class="hint"><a href="<?= url('/prihlaseni') ?>">← Přihlásit se heslem</a></p>
  </div>
</div>
