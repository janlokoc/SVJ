<div class="wrap page">
  <header class="page-head"><h1>Záznamy platformy</h1><p class="lead">Co hlavní správci na platformě udělali. Činnost uvnitř jednotlivých webů zapisuje každý web zvlášť.</p></header>
  <table class="table"><thead><tr><th>Kdy</th><th>Kdo</th><th>Událost</th><th>Podrobnost</th><th>IP adresa</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr><td data-label="Kdy"><?= cz_datetime($r['at']) ?></td><td data-label="Kdo"><?= e($r['admin']) ?></td><td data-label="Událost"><?= e($r['action']) ?></td><td data-label="Podrobnost"><?= e($r['detail']) ?></td><td data-label="IP adresa"><?= e($r['ip']) ?></td></tr><?php endforeach ?></tbody></table>
  <h2 id="upozorneni">Upozornění odeslaná společenstvím</h2>
  <?php if (!$notices): ?><p class="empty">Zatím žádná.</p><?php else: ?>
  <table class="table"><thead><tr><th>Kdy</th><th>Společenství</th><th>Upozornění</th><th>Komu</th><th>Stav</th></tr></thead>
    <tbody><?php foreach ($notices as $n): ?><tr><td data-label="Kdy"><?= cz_datetime($n['at']) ?></td><td data-label="Společenství"><?= e($n['tenant_name']) ?> <small class="muted"><?= e($n['tenant']) ?></small></td>
      <td data-label="Upozornění"><?= e(NOTICE_KINDS[$n['kind']] ?? $n['kind']) ?></td><td data-label="Komu"><?= e($n['email'] !== '' ? $n['email'] : '—') ?></td><td data-label="Stav"><?= $n['sent'] ? 'odesláno' : 'neodesláno' ?></td></tr><?php endforeach ?></tbody></table>
  <?php endif ?>
</div>
