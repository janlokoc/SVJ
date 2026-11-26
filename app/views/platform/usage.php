<div class="wrap page">
  <header class="page-head"><h1>Využití funkcí</h1>
    <p class="lead">Které funkce společenství opravdu používají. Komu funkce chybí, tomu se hodí napsat nebo zavolat – a funkce, kterou nepoužívá skoro nikdo, je námět na lepší návod nebo úpravu obrazovky. Správcům se nevyužité funkce nabízejí v kartě „Vyzkoušejte“ na přehledu administrace.</p></header>
  <?php if (!$rows): ?><p class="empty">Zatím tu není žádné aktivní společenství.</p><?php else: $n = count($rows); ?>
  <h2>Souhrn</h2>
  <div class="usage-sum">
    <?php foreach (USAGE_FEATURES as $k => [$label]): $c = count(array_filter($rows, fn($r) => $r['use'][$k])); ?>
      <div class="usage-bar"><span><?= e($label) ?></span><progress max="<?= $n ?>" value="<?= $c ?>"><?= $c ?></progress><strong><?= $c ?> z <?= $n ?></strong></div>
    <?php endforeach ?>
  </div>
  <h2>Po společenstvích</h2>
  <div class="table-scroll"><table class="table usage-table"><thead><tr><th>Společenství</th><th>Edice</th><th>Využito</th><th class="usage-mobile"></th>
    <?php foreach (USAGE_FEATURES as $k => [$label]): ?><th title="<?= e($label) ?>"><span class="usage-th"><?= e($label) ?></span></th><?php endforeach ?></tr></thead><tbody>
    <?php usort($rows, fn($a, $b) => array_sum($a['use']) <=> array_sum($b['use'])); foreach ($rows as $r): ?><tr>
      <td><a href="<?= url('/svj/' . $r['t']['slug']) ?>"><?= e($r['t']['name']) ?></a></td>
      <td data-label="Edice"><?= e(EDITIONS[$r['edition']] ?? $r['edition']) ?></td>
      <td data-label="Využito"><strong><?= array_sum($r['use']) ?></strong>/<?= count(USAGE_FEATURES) ?></td>
      <?php $usedL = $unusedL = []; foreach (USAGE_FEATURES as $k => [$label]) { if ($r['use'][$k]) { $usedL[] = $label; } else { $unusedL[] = $label; } } ?>
      <td class="usage-mobile"><?php if ($usedL): ?><p><span class="muted">Používá:</span> <?= e(implode(', ', $usedL)) ?></p><?php endif ?><?php if ($unusedL): ?><p><span class="muted">Nepoužívá:</span> <?= e(implode(', ', $unusedL)) ?></p><?php endif ?></td>
      <?php foreach (USAGE_FEATURES as $k => [$label]): ?><td class="usage-cell"><?= $r['use'][$k] ? '<span class="usage-yes" title="' . e($label) . ': ano">✓</span>' : '<span class="muted" title="' . e($label) . ': ne">·</span>' ?></td><?php endforeach ?>
    </tr><?php endforeach ?>
  </tbody></table></div>
  <p class="hint">Seřazeno od společenství, která používají nejméně funkcí. Ukázkový web se nepočítá.</p>
  <?php endif ?>
</div>
