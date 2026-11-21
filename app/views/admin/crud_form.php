<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url("/admin/$slug") ?>">← <?= e($def['title']) ?></a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <?php foreach ($def['fields'] as $name => $f): $v = $row[$name] ?? ($f[1] === 'check' ? 1 : ''); $req = ($f[2] ?? false) ? ' required' : ''; ?>
      <?php if ($f[1] === 'check'): ?>
        <label class="check"><input type="checkbox" name="<?= $name ?>"<?= $v ? ' checked' : '' ?>> <?= e($f[0]) ?></label>
      <?php elseif ($f[1] === 'select'): ?>
        <label><?= e($f[0]) ?> <select name="<?= $name ?>"<?= $req ?>><?php if (!$req && !array_key_exists('', $f[3]())): ?><option value="">—</option><?php endif ?>
          <?php foreach ($f[3]() as $k => $label): ?><option value="<?= e($k) ?>"<?= (string) $v === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <?php else: ?>
        <label><?= e($f[0]) ?> <input type="<?= ['date' => 'date', 'time' => 'time', 'number' => 'number'][$f[1]] ?? 'text' ?>" name="<?= $name ?>" value="<?= e($f[1] === 'decimal' && $v !== '' && $v !== null ? str_replace('.', ',', (string) $v) : ($f[1] === 'number' && !$v ? '' : $v)) ?>"<?= $f[1] === 'decimal' ? ' inputmode="decimal"' : '' ?><?= $req ?>></label>
      <?php endif ?>
    <?php endforeach ?>
    <?php if ($slug === 'revize'): ?><p class="hint">Když příští termín nevyplníte, dopočítá se z data posledního provedení a intervalu.</p><?php endif ?>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit</button><a class="btn btn-ghost" href="<?= url("/admin/$slug") ?>">Zrušit</a></div>
  </form>
</div>
