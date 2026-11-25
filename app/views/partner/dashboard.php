<?php /* Přehled domů partnera. $houses: řádky webů (+stats, billing_state), $discount: aktuální množstevní sleva v % */
$active = array_values(array_filter($houses, fn($h) => $h['partner_status'] === 'active'));
$pending = array_values(array_filter($houses, fn($h) => $h['partner_status'] === 'pending'));
$sum = fn(string $k) => array_sum(array_map(fn($h) => (int) ($h['stats'][$k] ?? 0), $active)); ?>
<div class="wrap page">
  <header class="page-head row"><div><h1>Domy ve správě</h1><p class="lead"><?= count($active) ?> <?= count($active) === 1 ? 'dům' : (count($active) >= 2 && count($active) <= 4 ? 'domy' : 'domů') ?><?= $discount ? ' · množstevní sleva ' . $discount . ' %' : '' ?></p></div>
    <div class="actions"><a class="btn" href="<?= url('/domy/novy') ?>">+ Založit web domu</a><a class="btn btn-ghost" href="<?= url('/domy/pripojit') ?>">Připojit existující web</a></div></header>
  <?php if (!empty($enterError)): ?><div class="flash flash-error" role="alert"><?= e($enterError) ?></div><?php endif ?>
  <?php if ($active): ?>
  <div class="stat-grid partner-stats">
    <div class="stat"><span>Nevyřízené závady</span><strong><?= $sum('tickets') ?></strong></div>
    <div class="stat"><span>Revize do 30 dnů</span><strong><?= $sum('revisions') ?></strong></div>
    <div class="stat"><span>Jednotek celkem</span><strong><?= $sum('units') ?></strong></div>
    <div class="stat"><span>Účtů obyvatel</span><strong><?= $sum('users') ?></strong></div>
  </div>
  <label class="table-filter">Hledat dům <input type="search" data-filter="#houses tbody" data-filter-rows="tr" placeholder="Název nebo adresa…"></label>
  <table class="table" id="houses">
    <thead><tr><th>Dům</th><th>Závady</th><th>Nejbližší termín</th><th>Revize</th><th>Předplatné</th><th></th></tr></thead>
    <tbody><?php foreach ($active as $h): $s = $h['stats']; $bs = $h['billing_state']; [$evDate, $evTitle] = array_pad(explode('|', $s['event'], 2), 2, ''); ?>
      <tr>
        <td data-label="Dům"><strong><?= e($h['name']) ?></strong><br><small class="muted"><?= e(tenant_address($h['slug'])) ?></small></td>
        <td data-label="Závady"><?= $s['tickets'] ? '<strong>' . $s['tickets'] . '</strong> nevyřízených' : '<span class="muted">žádné</span>' ?></td>
        <td data-label="Nejbližší termín"><?= $evDate !== '' ? cz_date($evDate) . '<br><small class="muted">' . e(mb_strimwidth($evTitle, 0, 40, '…')) . '</small>' : '<span class="muted">—</span>' ?></td>
        <td data-label="Revize"><?= $s['revisions'] ? '<span class="tag tag-pin">' . $s['revisions'] . ' do 30 dnů</span>' : '<span class="muted">v pořádku</span>' ?></td>
        <td data-label="Předplatné">Edice <?= e(EDITIONS[$bs['edition']] ?? $bs['edition']) ?><br><small class="muted"><?= match ($bs['mode']) { 'paid' => 'do ' . cz_date($bs['until']), 'trial' => 'na zkoušku do ' . cz_date($bs['until']), 'grace' => 'po splatnosti', 'free' => 'bez placení', default => 'zdarma' } ?><?= $h['partner_billing'] ? '' : ' · platí SVJ' ?></small></td>
        <td class="t-actions"><a class="btn btn-small" href="<?= url('/domy/' . $h['slug'] . '/vstoupit') ?>">Otevřít administraci</a></td>
      </tr>
    <?php endforeach ?></tbody>
  </table>
  <?php else: ?>
  <div class="card"><h2>Zatím tu nejsou žádné domy</h2>
    <p>Založte web pro dům, který spravujete – údaje doplníte z ARES a výboru pošleme pozvánku. Pokud už dům web u nás má, připojte ho: výbor Vám přístup schválí ve své administraci.</p></div>
  <?php endif ?>
  <?php if ($pending): ?>
  <h2>Čeká na schválení výborem</h2>
  <ul class="plain-list"><?php foreach ($pending as $h): ?>
    <li><strong><?= e($h['name']) ?></strong> <small class="muted">· <?= e(tenant_address($h['slug'])) ?> · žádost od <?= cz_date(substr((string) $h['partner_since'], 0, 10)) ?></small>
      <form method="post" action="<?= url('/domy/' . $h['slug'] . '/zrusit-zadost') ?>" class="inline-form"><?= csrf_field() ?><button class="link-btn" type="submit">zrušit žádost</button></form></li>
  <?php endforeach ?></ul>
  <?php endif ?>
</div>
