<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/kontakty') ?>">← Kontakty</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <div class="form-row">
      <label>Skupina
        <select name="grp"><?php foreach (CONTACT_GROUPS as $k => $label): ?><option value="<?= $k ?>"<?= $c['grp'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
      </label>
      <label>Funkce / druh služby <input type="text" name="role" value="<?= e($c['role']) ?>" placeholder="Např. Předseda SVJ"></label>
    </div>
    <label>Jméno nebo název firmy <input type="text" name="name" value="<?= e($c['name']) ?>" required></label>
    <div class="form-row">
      <label>Telefon <input type="tel" name="phone" value="<?= e($c['phone']) ?>"></label>
      <label>E-mail <input type="email" name="email" value="<?= e($c['email']) ?>"></label>
    </div>
    <label>Poznámka <input type="text" name="note" value="<?= e($c['note']) ?>" placeholder="Např. Zástupce vchodu 48 C"></label>
    <label>Pořadí (menší číslo = výše) <input type="number" name="sort" value="<?= (int) $c['sort'] ?>"></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit kontakt</button><a class="btn btn-ghost" href="<?= url('/admin/kontakty') ?>">Zrušit</a></div>
  </form>
</div>
