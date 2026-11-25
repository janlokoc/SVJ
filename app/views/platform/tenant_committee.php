<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/svj/' . $t['slug']) ?>">← <?= e($t['name']) ?></a></p>
  <h1>Výbor z rejstříku</h1>
  <p class="lead">Současní členové orgánů společenství podle veřejného rejstříku (ARES, IČO <?= e($ico !== '' ? $ico : '—') ?>). Vyberte, komu založit účet s funkcí. E-maily rejstřík nevede – doplňte je; pozvánky pak předáte odkazem.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if ($created): ?>
  <div class="card card-soft credentials">
    <h2>Hotovo</h2>
    <p>Odkazy na nastavení hesla se <strong>zobrazují jen teď</strong>, platí 14 dní. Předejte je členům výboru.</p>
    <ul class="committee-done"><?php foreach ($created as $c): ?><li><strong><?= e($c['name']) ?></strong> – <?= e($c['note']) ?><?php if ($c['link']): ?><br><code><?= e($c['link']) ?></code><?php endif ?></li><?php endforeach ?></ul>
  </div>
  <?php endif ?>
  <?php if (!$res['ok']): ?>
    <div class="card card-soft"><p><?= e(ares_ico($ico) === null ? 'Web nemá vyplněné platné IČO. Doplňte ho v části Údaje a vzhled (třeba tlačítkem „Načíst z ARES“) a vraťte se sem.' : $res['error']) ?></p>
      <p><a class="btn" href="<?= url('/svj/' . $t['slug']) ?>#udaje">Údaje a vzhled</a></p></div>
  <?php else: ?>
  <form method="post" class="card form committee">
    <?= csrf_field() ?>
    <?php foreach ($rows as $i => $m): $u = $m['user']; ?>
      <fieldset class="committee-row">
        <legend><label class="check"><input type="checkbox" name="use[<?= $i ?>]" value="1"<?= !$u ? ' checked' : '' ?>> <strong><?= e($m['name']) ?></strong></label></legend>
        <p class="muted committee-fn"><?= e($m['function'] !== '' ? $m['function'] : $m['body']) ?><?= $m['since'] ? ' · od ' . cz_date($m['since']) : '' ?></p>
        <div class="form-row">
          <label>Funkce na webu
            <select name="position[<?= $i ?>]"><?php foreach (POSITIONS as $k => $label): ?><option value="<?= $k ?>"<?= ($u && ($u['position'] ?? '') !== '' ? $u['position'] : $m['position']) === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
          <?php if ($u): ?>
            <p class="committee-has">Už má účet: <?= e($u['email']) ?> · <?= $u['role'] === 'admin' ? 'správce webu' : 'obyvatel' ?><?= ($u['position'] ?? '') !== '' ? ' · ' . e(POSITIONS[$u['position']] ?? '') : '' ?>. Uloží se jen funkce.</p>
          <?php else: ?>
            <label>E-mail <input type="email" name="email[<?= $i ?>]" value="<?= e($_POST['email'][$i] ?? '') ?>" autocomplete="off"></label>
          <?php endif ?>
        </div>
        <?php if (!$u): $admin = ($_POST['role'][$i] ?? (!$hasAdmin && in_array($m['position'], ['predseda_svj', 'predseda'], true) ? 'admin' : 'resident')) === 'admin'; ?>
          <label>Účet na webu <select name="role[<?= $i ?>]"><option value="resident"<?= $admin ? '' : ' selected' ?>>obyvatel (vidí neveřejný obsah)</option><option value="admin"<?= $admin ? ' selected' : '' ?>>správce webu (spravuje obsah a účty)</option></select></label>
        <?php endif ?>
      </fieldset>
    <?php endforeach ?>
    <?php if ($res['how']): ?><details class="committee-how"><summary>Jak jedná za společenství (podle rejstříku)</summary><?php foreach ($res['how'] as $h): ?><p><?= e($h) ?></p><?php endforeach ?></details><?php endif ?>
    <p class="hint">Přebírá se jen jméno a funkce – datum narození a adresu bydliště z rejstříku web neukládá. Založení účtů se zapisuje do záznamů platformy.</p>
    <div class="actions"><button class="btn" type="submit">Uložit vybrané</button></div>
  </form>
  <?php endif ?>
</div>
