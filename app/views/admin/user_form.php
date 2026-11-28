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
      <label>Telefon <input type="tel" name="phone" value="<?= e($u['phone']) ?>" placeholder="+420 777 123 456"></label>
    </div>
    <p class="hint">Obyvatel domu (vlastník i nájemník) vidí neveřejné dokumenty, diskuzi a hlášení závad a požadavků. Správce webu navíc upravuje obsah a účty.</p>
    <fieldset>
      <legend>Jednotky</legend>
      <?php if (!$units): ?><p class="hint">Zatím nejsou založené žádné jednotky. Přidáte je v sekci <a href="<?= url('/admin/jednotky') ?>">Jednotky</a>.</p><?php else: ?>
      <label>Hledat jednotku <input type="search" data-filter="#unit-pick" placeholder="Např. 48C/12"></label>
      <div class="pick" id="unit-pick">
        <?php foreach ($units as $un): ?>
          <label class="check"><input type="checkbox" name="units[]" value="<?= $un['id'] ?>"<?= in_array((int) $un['id'], $unitIds, true) ? ' checked' : '' ?>>
            <span><?= e(unit_name($un)) ?><?php if ($un['owner_note']): ?> <small><?= e($un['owner_note']) ?></small><?php endif ?></span></label>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </fieldset>
    <div class="form-row">
      <label>Vztah k domu
        <select name="kind"><?php foreach (KINDS as $k => $label): ?><option value="<?= $k ?>"<?= $u['kind'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
      </label>
      <label>Funkce ve společenství
        <select name="position"><?php foreach (POSITIONS as $k => $label): ?><option value="<?= $k ?>"<?= ($u['position'] ?? '') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
      </label>
    </div>
    <p class="hint">Funkce je vidět u jména všude na webu (kontakty, zprávy, diskuze), aby obyvatelé věděli, kdo je ve výboru a kdo v kontrolní komisi. Na oprávnění nemá vliv – to určuje role.</p>
    <p class="hint">Zprávy určené vlastníkům (například pozvánky na shromáždění) se nájemníkům neposílají.</p>
    <label class="check"><input type="checkbox" name="email_notify"<?= $u['email_notify'] ? ' checked' : '' ?>> Posílat důležité zprávy výboru e-mailem</label>
    <label class="check"><input type="checkbox" name="active"<?= $u['active'] ? ' checked' : '' ?>> Účet je aktivní (může se přihlásit)</label>
    <?php if (!$u['id']): ?><p class="hint">Po uložení se zobrazí dočasné heslo, které uživateli předáte.</p><?php endif ?>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit uživatele</button><a class="btn btn-ghost" href="<?= url('/admin/uzivatele') ?>">Zrušit</a></div>
  </form>
  <?php if ($u['id'] && (int) $u['id'] !== (int) user()['id']): ?>
  <form method="post" action="<?= url('/admin/uzivatele/' . $u['id'] . '/smazat') ?>" class="card form" data-confirm="Opravdu smazat účet „<?= e($u['name']) ?>“?">
    <h2>Smazat účet</h2>
    <?= csrf_field() ?>
    <p>Účet se smaže natrvalo. Příspěvky v diskuzi, hlášené závady a odevzdané hlasy zůstanou, jen bez jména. Kdo se má jen dočasně nepřihlašovat, tomu stačí vypnout „Účet je aktivní“.</p>
    <button class="btn btn-accent" type="submit">Smazat uživatele</button>
  </form>
  <?php elseif ($u['id']): ?><p class="hint">Vlastní účet smazat nejde. Může to udělat jiný správce.</p>
  <?php endif ?>
  <?php if (!empty($u['id'])): ?><p class="hint">Žádá tento člověk o přístup ke svým údajům (GDPR) a sám se nepřihlásí? <a href="<?= url('/admin/uzivatele/' . $u['id'] . '/udaje.json') ?>" download>Stáhnout všechny jeho údaje</a>. Může si je stáhnout i sám ve svém účtu, kde si účet může také zrušit.</p><?php endif ?>
</div>
