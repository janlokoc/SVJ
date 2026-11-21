<div class="wrap page print-doc">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= url('/admin/terminy/' . $ev['id'] . '/ucast') ?>">← Zpět</a> <button class="btn btn-small" type="button" data-print>Vytisknout</button></p>
  <h1>Prezenční listina</h1>
  <p><strong><?= e($ev['title']) ?></strong> · <?= cz_date($ev['event_date'], true) ?><?= $ev['event_time'] ? ' v ' . e($ev['event_time']) : '' ?><?= $ev['place'] ? ' · ' . e($ev['place']) : '' ?></p>
  <table class="table print-table">
    <thead><tr><th>Jednotka</th><th>Vlastník</th><th>Podíl</th><th>Zmocněnec (plná moc)</th><th>Podpis</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><strong><?= e(unit_name($r)) ?></strong></td><td><?= e($r['owner_note']) ?></td><td><?= e($r['share']) ?></td><td><?= $r['status'] === 'proxy' ? e($r['proxy_name']) : '' ?></td><td class="sign-cell"></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <p>Přítomno hlasů: .................... z celku · Shromáždění je / není usnášeníschopné.</p>
</div>
