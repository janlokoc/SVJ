<?php
$headers = $imp['table']['headers'];
$sample = array_slice($imp['table']['rows'], 0, 3);
$back = $multi ? url('/ucetnictvi#import') : url('/admin/ucetnictvi#import');
$previewTable = function (array $pv) use ($fields): void { ?>
  <table class="table acct-preview"><thead><tr><th>Řádek</th><th>Jednotka</th><?php foreach ($fields as $f => $label): if (in_array($f, ['house', 'unit', 'entrance'], true)) continue; ?><th><?= e($label) ?></th><?php endforeach ?><th>Výsledek</th></tr></thead><tbody>
  <?php foreach (array_slice($pv['rows'], 0, 300) as $r): ?><tr<?= $r['errors'] ? ' class="is-unmatched"' : ($r['action'] === 'skip' ? ' class="is-off"' : '') ?>>
    <td data-label="Řádek"><?= (int) $r['line'] ?></td><td data-label="Jednotka"><strong><?= e($r['label']) ?></strong></td>
    <?php foreach ($fields as $f => $label): if (in_array($f, ['house', 'unit', 'entrance'], true)) continue; $v = $r['values'][$f] ?? null; ?>
      <td data-label="<?= e($label) ?>"><?= $v === null ? '<span class="muted">—</span>' : e($f === 'area' ? unit_area_label($v) : (is_float($v) ? number_format($v, 2, ',', ' ') . ' Kč' : $v)) ?></td><?php endforeach ?>
    <td data-label="Výsledek"><?php if ($r['errors']): ?><span class="status status-new">chyba</span> <small><?= e(implode(' ', $r['errors'])) ?></small>
      <?php elseif ($r['action'] === 'create'): ?><span class="status">nová</span><?php elseif ($r['action'] === 'update'): ?><span class="status status-done">uloží se</span><?php else: ?><span class="muted">přeskočí se</span><?php endif ?>
      <?= $r['warnings'] && !$r['errors'] ? '<br><small class="muted">' . e(implode(' ', $r['warnings'])) . '</small>' : '' ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php if (count($pv['rows']) > 300): ?><p class="muted">Zobrazeno prvních 300 řádků z <?= count($pv['rows']) ?>.</p><?php endif ?>
<?php }; ?>
<div class="wrap page">
  <header class="page-head"><h1><?= e($title) ?></h1>
    <p class="lead">Soubor <strong><?= e($imp['name']) ?></strong> · <?= e(ACCT_TARGETS[$imp['target']][0]) ?> · <?= count($imp['table']['rows']) ?> řádků<?= $imp['table']['encoding'] !== '' ? ' · ' . e($imp['table']['encoding']) : '' ?><?= $imp['table']['delimiter'] !== '' ? ' · oddělovač „' . e($imp['table']['delimiter'] === "\t" ? 'tabulátor' : $imp['table']['delimiter']) . '“' : '' ?></p></header>
  <?php if ($imp['preset'] !== ''): ?><div class="flash flash-info">Předvolba <?= e(ACCT_PRESETS[$imp['preset']][0]) ?> je jen <strong>odhad</strong> názvů sloupců – zkontrolujte přiřazení níže.</div><?php endif ?>
  <?php if ($restored): ?><div class="flash flash-info">Použili jsme přiřazení sloupců z minulého importu.</div><?php endif ?>
  <form method="post" action="<?= e($action) ?>" class="card form"><?= csrf_field() ?>
    <h2>Přiřazení sloupců</h2>
    <div class="two-col">
      <?php foreach ($fields as $f => $label): ?>
      <label><?= e($label) ?> <select name="map[<?= e($f) ?>]"><option value="">— nepoužít —</option>
        <?php foreach ($headers as $i => $h): ?><option value="<?= (int) $i ?>"<?= ($map[$f] ?? null) === $i ? ' selected' : '' ?>><?= e($h) ?><?= isset($sample[0][$i]) && $sample[0][$i] !== '' ? ' (např. ' . e(mb_strimwidth($sample[0][$i], 0, 24, '…')) . ')' : '' ?></option><?php endforeach ?>
      </select></label>
      <?php endforeach ?>
      <?php if ($imp['target'] === 'accounts'): ?><label>Stav platí ke dni <input type="date" name="as_of" value="<?= e($asOf) ?>" required></label><?php endif ?>
    </div>
    <p class="hint"><?= $imp['target'] === 'accounts'
        ? 'Jednotku poznáme podle označení (např. 12A/1), podle vchodu a čísla, samotného čísla, nebo podle variabilního symbolu. Záporný stav = nedoplatek. Nepřiřazené sloupce zůstanou beze změny.'
        : 'Jednotky, které na webu ještě nejsou, se založí. Podíl jako 812/10000 nebo 8,12 %. Nepřiřazené sloupce zůstanou beze změny; účty vlastníků na webu se nezakládají.' ?></p>
    <?php foreach ($preview['missing'] as $m): ?><div class="flash flash-error"><?= e($m) ?></div><?php endforeach ?>
    <div class="actions">
      <button class="btn btn-ghost" type="submit" name="action" value="check">Zkontrolovat znovu</button>
      <?php if (!$preview['missing'] && $preview['ok'] > 0): ?><button class="btn" type="submit" name="action" value="save">Uložit <?= (int) $preview['ok'] ?> <?= $preview['ok'] === 1 ? 'řádek' : ($preview['ok'] < 5 ? 'řádky' : 'řádků') ?></button><?php endif ?>
      <button class="btn btn-ghost" type="submit" name="action" value="cancel" formnovalidate>Zrušit</button>
    </div>
  </form>

  <?php if (!$multi && !$preview['missing']): ?>
  <section class="card"><h2>Náhled</h2>
    <p>Uloží se <strong><?= (int) $preview['ok'] ?></strong><?= $preview['create'] ? ' (z toho nových jednotek ' . (int) $preview['create'] . ')' : '' ?>, s chybou <strong><?= (int) $preview['bad'] ?></strong> – ty se vynechají.</p>
    <?php $previewTable($preview) ?>
  </section>
  <?php endif ?>
  <?php if ($multi): ?>
    <?php if (!empty($unknown)): ?><div class="flash flash-error">Tyto domy jsme nepoznali, jejich řádky se vynechají: <?= e(implode(', ', array_map(fn($k, $n) => $k . ' (' . $n . ')', array_keys($unknown), $unknown))) ?>. Dům se pozná podle názvu, IČO nebo adresy webu (např. <?= e($groups ? array_key_first($groups) : 'nazev-domu') ?>).</div><?php endif ?>
    <?php foreach ($groups as $slug => $g): $pv = $g['preview']; ?>
    <section class="card"><h2><?= e($g['house']['name']) ?></h2>
      <?php foreach ($pv['missing'] as $m): ?><div class="flash flash-error"><?= e($m) ?></div><?php endforeach ?>
      <?php if (!$pv['missing']): ?><p>Uloží se <strong><?= (int) $pv['ok'] ?></strong><?= $pv['create'] ? ' (nových jednotek ' . (int) $pv['create'] . ')' : '' ?>, s chybou <strong><?= (int) $pv['bad'] ?></strong>.</p><?php $previewTable($pv) ?><?php endif ?>
    </section>
    <?php endforeach ?>
  <?php endif ?>
  <p><a href="<?= e($back) ?>">← Zpět</a></p>
</div>
