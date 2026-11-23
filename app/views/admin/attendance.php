<?php $c = $att['counts']; ?>
<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/admin/terminy') ?>">← Termíny</a></p>
  <header class="page-head"><h1><?= e($ev['title']) ?></h1>
    <p class="lead">Účast přihlášená na <?= cz_date($ev['event_date'], true) ?><?= $ev['event_time'] ? ' v ' . e($ev['event_time']) : '' ?>.</p></header>
  <?php if (!$att['units']): ?>
    <p class="empty">Nejdřív založte jednotky v sekci <a href="<?= url('/admin/jednotky') ?>">Jednotky</a> – účast se eviduje po jednotkách.</p>
  <?php else: ?>
  <div class="quorum<?= $att['quorate'] ? ' is-ok' : '' ?>">
    <p class="eyebrow">Přihlášeno (osobně nebo v zastoupení)</p>
    <p class="quorum-num"><?= percent_cz($att['percent']) ?> <small><?= $att['byShare'] ? 'všech hlasů' : 'jednotek' ?></small></p>
    <div class="meter" role="img" aria-label="Přihlášeno <?= percent_cz($att['percent']) ?>, potřeba více než <?= $att['threshold'] ?> %"><span class="meter-fill" data-width="<?= round(min(100, $att['percent']), 1) ?>"></span><span class="meter-mark" data-left="<?= $att['threshold'] ?>"></span></div>
    <p><strong><?= $att['quorate'] ? 'Podle přihlášek je shromáždění usnášeníschopné.' : 'Podle přihlášek zatím není usnášeníschopné' ?></strong><?= $att['quorate'] ? '' : ' – chybí ' . percent_cz(max(0, $att['threshold'] - $att['percent'])) . ' a alespoň jeden další hlas.' ?> Hranice: více než <?= $att['threshold'] ?> % (změníte v Nastavení).</p>
  </div>
  <?php if (!$att['byShare']): ?><div class="flash flash-info">Některé jednotky nemají vyplněný spoluvlastnický podíl, proto se zatím počítá každá jednotka jako jeden hlas. Pro přesný výpočet podle podílů je doplňte v sekci <a href="<?= url('/admin/jednotky') ?>">Jednotky</a> (např. 652/100000).</div><?php endif ?>
  <div class="stat-grid">
    <div class="stat"><span class="stat-num"><?= $c['yes'] ?></span><span>přijde osobně</span></div>
    <div class="stat"><span class="stat-num"><?= $c['proxy'] ?></span><span>v zastoupení</span></div>
    <div class="stat"><span class="stat-num"><?= $c['no'] ?></span><span>nepřijde</span></div>
    <div class="stat"><span class="stat-num"><?= $c['none'] ?></span><span>bez odpovědi</span></div>
  </div>
  <p class="actions"><a class="btn" href="<?= url('/admin/terminy/' . $ev['id'] . '/prezence-na-miste') ?>">Prezence na místě</a> <a class="btn btn-ghost" href="<?= url('/admin/terminy/' . $ev['id'] . '/prezence') ?>">Prezenční listina k tisku</a></p>
  <p class="hint">Odpověď můžete zapsat i za vlastníka, který se ozval jinak než přes web. Stejně lze seznam použít při prezenci na místě.</p>
  <table class="table">
    <thead><tr><th>Jednotka</th><th>Vlastník dle evidence</th><th>Podíl</th><th>Účast</th><th>Zapsal(a)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($att['rows'] as $r): ?>
      <tr id="j<?= $r['id'] ?>">
        <td data-label="Jednotka"><strong><?= e(unit_name($r)) ?></strong></td>
        <td data-label="Vlastník dle evidence"><?= e($r['owner_note']) ?></td>
        <td data-label="Podíl"><?= e($r['share']) ?></td>
        <td data-label="Účast"><?php if ($r['status']): ?><span class="status status-rsvp-<?= e($r['status']) ?>"><?= RSVP[$r['status']] ?></span><?= $r['status'] === 'proxy' && $r['proxy_name'] ? '<br><small>' . e($r['proxy_name']) . '</small>' : '' ?><?= !empty($r['file_id']) ? '<br><small><a href="' . url('/terminy/' . $ev['id'] . '/plna-moc/soubor?jednotka=' . $r['id']) . '" target="_blank" rel="noopener">✓ plná moc nahrána</a></small>' : ($r['status'] === 'proxy' ? '<br><small class="muted">plná moc zatím nenahrána</small>' : '') ?><?php else: ?><span class="muted">bez odpovědi</span><?php endif ?></td>
        <td data-label="Zapsal(a)"><?= $r['status'] ? e($r['responder'] ?? '') . ' <small class="muted">' . cz_datetime($r['updated_at']) . '</small>' : '' ?></td>
        <td class="t-actions"><form method="post" action="<?= url('/admin/terminy/' . $ev['id'] . '/ucast') ?>" class="inline-form">
          <?= csrf_field() ?><input type="hidden" name="unit_id" value="<?= $r['id'] ?>">
          <label class="sr-only" for="s<?= $r['id'] ?>">Účast jednotky <?= e(unit_name($r)) ?></label>
          <select name="status" id="s<?= $r['id'] ?>"><option value="">bez odpovědi</option><?php foreach (RSVP as $k => $label): ?><option value="<?= $k ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
          <label class="sr-only" for="px<?= $r['id'] ?>">Zmocněnec</label><input type="text" name="proxy_name" id="px<?= $r['id'] ?>" value="<?= e($r['proxy_name'] ?? '') ?>" placeholder="zmocněnec" maxlength="190">
          <button class="btn btn-small btn-ghost" type="submit">Zapsat</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
