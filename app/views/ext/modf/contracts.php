<?php /* Smlouvy a pojištění – seznam. $rows = modf_contracts(), $kind, $feed. */
$today = date('Y-m-d'); ?>
<div class="wrap page">
  <header class="page-head row"><h1>Smlouvy a pojištění</h1>
    <span class="actions"><a class="btn" href="<?= url('/admin/smlouvy/nova') ?>">+ Přidat smlouvu</a><a class="btn btn-ghost" href="<?= url('/admin/smlouvy/nova?druh=insurance') ?>">+ Přidat pojištění</a></span></header>
  <p class="lead">Web hlídá, do kdy lze smlouvu vypovědět, než se sama prodlouží, a kdy smlouva nebo pojištění končí. Správcům webu přijde e-mail zadaný počet dní předem a znovu týden předem.</p>
  <nav class="chips" aria-label="Druh"><?php foreach (['' => 'Vše'] + MODF_CONTRACT_KINDS as $k => $label): ?><a href="<?= url('/admin/smlouvy' . ($k !== '' ? '?druh=' . $k : '')) ?>"<?= $kind === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach ?></nav>
  <?php if (!$rows): ?><p class="empty">Zatím tu nic není. Přidejte třeba smlouvu na úklid, výtah, energie nebo pojištění domu.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Předmět</th><th>Platnost</th><th>Výpovědní lhůta</th><th>Nejbližší termín</th><th>Cena</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): $ev = $c['events'][0] ?? null; $soon = $ev && $ev['date'] <= modf_add_days($today, (int) $c['remind_days']); ?>
      <tr id="c<?= (int) $c['id'] ?>">
        <td data-label="Předmět"><a href="<?= url('/admin/smlouvy/' . $c['id']) ?>"><strong><?= e($c['subject']) ?></strong></a> <span class="tag"><?= e(MODF_CONTRACT_KINDS[$c['kind']] ?? '') ?></span>
          <br><small class="muted"><?= e($c['supplier'] !== '' ? $c['supplier'] : $c['party']) ?><?= $c['number'] !== '' ? ' · č. ' . e($c['number']) : '' ?></small></td>
        <td data-label="Platnost"><?= $c['start_date'] !== '' ? 'od ' . cz_date($c['start_date']) . ' ' : '' ?><?= $c['end_date'] !== '' ? 'do ' . cz_date($c['end_date']) : 'na dobu neurčitou' ?><?= (int) $c['auto_renew'] ? '<br><small class="muted">prodlužuje se o ' . (int) $c['renew_months'] . ' měs.</small>' : '' ?></td>
        <td data-label="Výpovědní lhůta"><?= (int) $c['notice_value'] ? (int) $c['notice_value'] . ' ' . e(MODF_NOTICE_UNITS[$c['notice_unit']] ?? '') : '–' ?></td>
        <td data-label="Nejbližší termín"><?php if ($ev): ?><span class="<?= $soon ? 'status status-in_progress' : '' ?>"><?= cz_date($ev['date']) ?></span> <small class="muted"><?= e(mb_strtolower($ev['label'])) ?></small><?php else: ?>–<?php endif ?></td>
        <td data-label="Cena"><?= (float) $c['price'] ? modf_money((float) $c['price']) . ' <small class="muted">' . e(MODF_PRICE_PERIODS[$c['price_period']] ?? '') . '</small>' : '–' ?></td>
        <td class="t-actions"><?php if ($c['filename'] !== ''): ?><a href="<?= url('/admin/smlouvy/' . $c['id'] . '/soubor') ?>" target="_blank" rel="noopener">Dokument</a><?php endif ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
  <section class="card" id="kalendar" aria-labelledby="modf-cal-h">
    <h2 id="modf-cal-h">Termíny ve Vašem kalendáři</h2>
    <p>Výpovědní lhůty, konce smluv, lhůty pro doručení vyúčtování a pro vyřízení námitek můžete odebírat v Google Kalendáři, Outlooku nebo v telefonu. Vložte tento odkaz jako „kalendář z adresy URL“ (odběr):</p>
    <p><input type="text" readonly value="<?= e($feed) ?>" class="modf-feed" aria-label="Odkaz na kalendář smluv a lhůt" data-select-all> <button class="btn btn-small" type="button" data-copy="<?= e($feed) ?>">Zkopírovat odkaz</button></p>
    <p class="hint">Odkaz je tajný – kdo ho zná, vidí termíny bez přihlášení. Pokud se dostal k někomu nepovolanému, vytvořte nový.</p>
    <form method="post" action="<?= url('/admin/smlouvy/kalendar') ?>" data-confirm="Vytvořit nový odkaz? Ten současný přestane fungovat."><?= csrf_field() ?><button class="btn btn-small btn-ghost" type="submit">Vytvořit nový odkaz</button></form>
  </section>
</div>
