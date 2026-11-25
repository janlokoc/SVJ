<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/uzivatele') ?>">← Uživatelé</a></p>
  <h1>Výbor podle rejstříku</h1>
  <p class="lead">Současní členové orgánů společenství zapsaní ve veřejném rejstříku (načteno z ARES podle IČO <?= e(setting('ico')) ?>). Vyberte, komu založit účet s funkcí a poslat pozvánku – e-maily rejstřík nevede, ty doplňte.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if (!$res['ok']): ?>
    <div class="card card-soft"><p><?= e($res['error']) ?></p>
      <?php if (ares_ico(setting('ico')) === null): ?><p><a class="btn" href="<?= url('/admin/nastaveni') ?>">Doplnit IČO v nastavení</a></p><?php endif ?></div>
  <?php else: ?>
  <form method="post" class="card form committee">
    <?= csrf_field() ?>
    <?php foreach ($rows as $i => $m): $u = $m['user']; ?>
      <fieldset class="committee-row">
        <legend><label class="check"><input type="checkbox" name="use[<?= $i ?>]" value="1"<?= !$u || ($u['position'] ?? '') === '' ? ' checked' : '' ?>> <strong><?= e($m['name']) ?></strong></label></legend>
        <p class="muted committee-fn"><?= e($m['function'] !== '' ? $m['function'] : $m['body']) ?><?= $m['since'] ? ' · od ' . cz_date($m['since']) : '' ?></p>
        <div class="form-row">
          <label>Funkce na webu
            <select name="position[<?= $i ?>]"><?php foreach (POSITIONS as $k => $label): ?><option value="<?= $k ?>"<?= ($u && ($u['position'] ?? '') !== '' ? $u['position'] : $m['position']) === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
          <?php if ($u): ?>
            <p class="committee-has">Už má účet: <a href="<?= url('/admin/uzivatele/' . $u['id']) ?>"><?= e($u['email']) ?></a><?= ($u['position'] ?? '') !== '' ? ' · ' . e(POSITIONS[$u['position']] ?? '') : '' ?>. Uloží se jen funkce.</p>
          <?php else: ?>
            <label>E-mail <input type="email" name="email[<?= $i ?>]" value="<?= e($_POST['email'][$i] ?? '') ?>" autocomplete="off" placeholder="adresa pro pozvánku"></label>
          <?php endif ?>
        </div>
        <?php if (!$u): ?><label class="check"><input type="checkbox" name="invite[<?= $i ?>]" value="1" checked> Poslat pozvánku e-mailem (nastaví si heslo sám)</label><?php endif ?>
      </fieldset>
    <?php endforeach ?>
    <?php if ($res['how']): ?><details class="committee-how"><summary>Jak jedná za společenství (podle rejstříku)</summary><?php foreach ($res['how'] as $h): ?><p><?= e($h) ?></p><?php endforeach ?></details><?php endif ?>
    <p class="hint">Z rejstříku se přebírá jen jméno a funkce. Datum narození ani adresu bydliště, které rejstřík také uvádí, web neukládá. Funkce je pak vidět u jména na webu; oprávnění správce dostane člen jen tehdy, když mu ho výslovně nastavíte v jeho účtu.</p>
    <div class="actions"><button class="btn" type="submit">Uložit vybrané</button><a class="btn btn-ghost" href="<?= url('/admin/uzivatele') ?>">Zpět</a></div>
  </form>
  <?php endif ?>
</div>
