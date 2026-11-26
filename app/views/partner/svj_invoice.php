<?php /* Faktura správcovské firmy společenství za web. $d = partner_svj_view(), $backUrl */ $i = $d['inv']; $s = $d['s']; $c = $d['c']; $vatOn = (int) $i['vat_rate'] > 0; ?>
<div class="wrap page narrow print-doc invoice">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= e($backUrl) ?>">← Zpět</a> <button class="btn btn-small" type="button" data-print>Vytisknout nebo uložit jako PDF</button></p>
  <header class="invoice-head">
    <h1><?= $vatOn ? 'Faktura – daňový doklad č. ' : 'Faktura č. ' ?><?= e($i['number']) ?></h1>
    <?php if ($i['status'] === 'cancelled'): ?><p class="invoice-storno">ZRUŠENO</p><?php endif ?>
  </header>
  <div class="invoice-parties">
    <div><span class="h-small">Dodavatel</span><strong><?= e($s['name'] ?? '') ?></strong><br><?= nl2br(e($s['address'] ?? '')) ?>
      <?= ($s['ico'] ?? '') !== '' ? '<br>IČO: ' . e($s['ico']) : '' ?><?= ($s['dic'] ?? '') !== '' ? '<br>DIČ: ' . e($s['dic']) : '' ?>
      <?= ($s['email'] ?? '') !== '' ? '<br>' . e($s['email']) : '' ?><?= ($s['phone'] ?? '') !== '' ? ' · ' . e($s['phone']) : '' ?><?= !$vatOn ? '<br><small>Není plátce DPH.</small>' : '' ?></div>
    <div><span class="h-small">Odběratel</span><strong><?= e($c['name'] ?? '') ?></strong><br><?= nl2br(e($c['address'] ?? '')) ?><?= ($c['ico'] ?? '') !== '' ? '<br>IČO: ' . e($c['ico']) : '' ?></div>
  </div>
  <dl class="pay-data invoice-dates">
    <dt>Datum vystavení</dt><dd><?= cz_date($i['issued']) ?></dd>
    <?php if ($vatOn): ?><dt>Datum uskutečnění zdanitelného plnění</dt><dd><?= cz_date($i['issued']) ?></dd><?php endif ?>
    <dt>Datum splatnosti</dt><dd><?= cz_date($i['due']) ?></dd>
    <dt>Variabilní symbol</dt><dd><?= e($i['vs']) ?></dd>
    <?php if (($s['account'] ?? '') !== ''): ?><dt>Účet dodavatele</dt><dd><?= e($s['account']) ?></dd><?php endif ?>
    <?php if ($i['status'] === 'paid'): ?><dt>Úhrada</dt><dd>uhrazeno <?= cz_date($i['paid_at']) ?></dd><?php endif ?>
  </dl>
  <table class="table print-table invoice-items">
    <thead><tr><th>Položka</th><th class="t-actions"><?= $vatOn ? 'Cena bez DPH' : 'Cena' ?></th></tr></thead>
    <tbody><tr><td><?= e($i['item']) ?><?= $i['period_from'] !== '' ? ' v období ' . cz_date($i['period_from']) . ' – ' . cz_date($i['period_to']) : '' ?></td><td class="t-actions"><?= money_czk((int) $i['amount']) ?></td></tr></tbody>
    <tfoot><?php if ($vatOn): ?><tr class="vat-row"><td>Základ daně</td><td class="t-actions"><?= money_czk((int) $i['amount']) ?></td></tr><tr class="vat-row"><td>DPH <?= (int) $i['vat_rate'] ?> %</td><td class="t-actions"><?= money_czk($d['vat']) ?></td></tr><?php endif ?>
      <tr><th><?= $i['status'] === 'paid' ? 'Celkem (uhrazeno)' : 'Celkem k úhradě' ?></th><th class="t-actions"><?= money_czk($d['total']) ?></th></tr></tfoot>
  </table>
  <?php if ($d['qr'] !== ''): ?><div class="pay-qr invoice-qr"><?= $d['qr'] ?><small><strong>QR platba</strong><br>Naskenujte kód v aplikaci své banky. Částka, účet a variabilní symbol se vyplní samy.</small></div><?php endif ?>
</div>
