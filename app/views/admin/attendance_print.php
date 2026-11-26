<div class="wrap page print-doc">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= url('/admin/terminy/' . $ev['id'] . '/ucast') ?>">← Zpět</a> <button class="btn btn-small" type="button" data-print>Vytisknout</button></p>
  <h1>Prezenční listina</h1>
  <p><strong><?= e($ev['title']) ?></strong> · <?= cz_date($ev['event_date'], true) ?><?= $ev['event_time'] ? ' v ' . e($ev['event_time']) : '' ?><?= $ev['place'] ? ' · ' . e($ev['place']) : '' ?></p>
  <p class="hint no-print"><?= $pres ? 'Listina je vyplněná podle Prezence na místě – tedy podle toho, kdo opravdu přišel.' : 'Prezence na místě zatím neběží, proto je listina prázdná k podpisům; zmocněnci jsou předvyplnění podle přihlášek předem. Jakmile začnete zapisovat prezenci na místě, listina se vyplní podle ní.' ?></p>
  <table class="table print-table">
    <thead><tr><th>Jednotka</th><th>Vlastník</th><th>Podíl</th><?php if ($pres): ?><th>Přítomen</th><?php endif ?><th>Zmocněnec (plná moc)</th><th>Podpis</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><strong><?= e(unit_name($r)) ?></strong></td><td><?= e($r['owner_note']) ?></td><td><?= e($r['share']) ?></td>
        <?php if ($pres): ?><td><?= $r['mode'] === 'self' ? 'osobně' : ($r['mode'] === 'proxy' ? 'v zastoupení' : ($r['mode'] === 'online' ? 'online' : '–')) ?></td>
        <td><?= $r['mode'] === 'proxy' ? e($r['p_proxy']) . ($r['proxy_ok'] || $r['file_id'] ? ' ✓' : '') : '' ?></td>
        <?php else: ?><td><?= $r['status'] === 'proxy' ? e($r['proxy_name']) : '' ?></td><?php endif ?>
        <td class="sign-cell"></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <?php if ($pres): ?>
  <p>Přítomno <?= $pres['counts']['yes'] + $pres['counts']['proxy'] + ($pres['counts']['online'] ?? 0) ?> z <?= $pres['units'] ?> jednotek (osobně <?= $pres['counts']['yes'] ?>, v zastoupení <?= $pres['counts']['proxy'] ?><?= !empty($pres['counts']['online']) ? ', online ' . $pres['counts']['online'] : '' ?>) s <strong><?= percent_cz($pres['percent']) ?></strong> všech hlasů · Shromáždění <strong><?= $pres['quorate'] ? 'je' : 'není' ?></strong> usnášeníschopné (potřeba více než <?= $pres['threshold'] ?> %).<?= $pres['missingDocs'] ? ' Zmocněnci bez předložené plné moci: ' . $pres['missingDocs'] . '.' : '' ?></p>
  <?php else: ?>
  <p>Přítomno hlasů: .................... z celku · Shromáždění je / není usnášeníschopné.</p>
  <?php endif ?>
  <?php if (function_exists('hybrid_protocol_block')) { hybrid_protocol_block($ev); } ?>
</div>
