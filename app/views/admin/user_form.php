<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/uzivatele') ?>">← Uživatelé</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Jméno a příjmení <input type="text" name="name" value="<?= e($u['name']) ?>" required></label>
    <label>E-mail (přihlašovací jméno) <input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
    <div class="form-row">
      <label>Role
        <select name="role"><?php foreach (ROLES as $k => $label): ?><option value="<?= $k ?>"<?= $u['role'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
      </label>
      <label>Telefon <input type="tel" name="phone" value="<?= e($u['phone']) ?>"></label>
    </div>
    <p class="hint">Obyvatel domu (vlastník i nájemník) vidí neveřejné dokumenty, diskuzi a hlášení závad. Správce webu navíc upravuje obsah a účty.</p>
    <div class="form-row">
      <label>Vchod
        <select name="entrance"><?php foreach (ENTRANCES as $en): ?><option value="<?= $en ?>"<?= $u['entrance'] === $en ? ' selected' : '' ?>><?= $en === '' ? '—' : '48 ' . $en ?></option><?php endforeach ?></select>
      </label>
      <label>Číslo bytu <input type="text" name="unit" value="<?= e($u['unit']) ?>" maxlength="10"></label>
    </div>
    <label class="check"><input type="checkbox" name="active"<?= $u['active'] ? ' checked' : '' ?>> Účet je aktivní (může se přihlásit)</label>
    <?php if (!$u['id']): ?><p class="hint">Po uložení se zobrazí dočasné heslo, které uživateli předáte.</p><?php endif ?>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit uživatele</button><a class="btn btn-ghost" href="<?= url('/admin/uzivatele') ?>">Zrušit</a></div>
  </form>
</div>
