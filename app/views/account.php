<div class="wrap page narrow">
  <header class="page-head"><h1>Můj účet</h1>
    <p class="lead"><?= e($u['email']) ?> · <?= ROLES[$u['role']] ?><?= $u['entrance'] ? ' · vchod ' . e($u['entrance']) : '' ?><?= $u['unit'] ? ' · byt ' . e($u['unit']) : '' ?></p></header>
  <?php require __DIR__ . '/_errors.php'; ?>
  <div class="two-col">
    <form method="post" action="<?= url('/muj-ucet') ?>" class="card form">
      <h2>Kontaktní údaje</h2>
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <label>Jméno a příjmení <input type="text" name="name" value="<?= e($u['name']) ?>" required></label>
      <label>Telefon <input type="tel" name="phone" value="<?= e($u['phone']) ?>" autocomplete="tel"></label>
      <p class="hint">Telefon vidí jen výbor – kvůli řešení nahlášených závad.</p>
      <button class="btn" type="submit">Uložit údaje</button>
    </form>
    <form method="post" action="<?= url('/muj-ucet') ?>" class="card form">
      <h2>Změna hesla</h2>
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label>Současné heslo <input type="password" name="current_password" autocomplete="current-password" required></label>
      <label>Nové heslo (alespoň 10 znaků) <input type="password" name="new_password" minlength="10" autocomplete="new-password" required></label>
      <label>Nové heslo znovu <input type="password" name="new_password2" minlength="10" autocomplete="new-password" required></label>
      <button class="btn" type="submit">Změnit heslo</button>
    </form>
  </div>
</div>
