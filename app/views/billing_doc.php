<?php /* Zálohová faktura (výzva k platbě) nebo faktura za provoz webu: $d z invoice_view(), $backUrl */ $p = $d['p']; $s = $d['parties']['supplier']; $c = $d['parties']['customer']; $paid = $d['paid']; ?>
<div class="wrap page narrow print-doc invoice">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= e($backUrl) ?>">← Zpět</a> <button class="btn btn-small" type="button" data-print>Vytisknout nebo uložit jako PDF</button></p>
  <header class="invoice-head">
    <h1><?= $paid ? 'Faktura č. ' . e($p['invoice_no']) : 'Zálohová faktura č. ' . e($p['vs']) ?></h1>
    <p><?= $paid ? e($d['parties']['note'] ?? '') : 'Výzva k platbě – není daňovým dokladem.' ?></p>
  </header>
  <div class="invoice-parties">
    <div><span class="h-small">Dodavatel</span>
      <strong><?= e($s['name'] !== '' ? $s['name'] : pset('service_name')) ?></strong><br><?= nl2br(e($s['address'])) ?>
      <?= $s['ico'] !== '' ? '<br>IČO: ' . e($s['ico']) : '' ?><?= $s['dic'] !== '' ? '<br>DIČ: ' . e($s['dic']) : '' ?><?= $s['register'] !== '' ? '<br><small>' . e($s['register']) . '</small>' : '' ?>
      <?= $s['email'] !== '' ? '<br>' . e($s['email']) : '' ?><?= $s['phone'] !== '' ? ' · ' . e($s['phone']) : '' ?></div>
    <div><span class="h-small">Odběratel</span>
      <strong><?= e($c['name']) ?></strong><br><?= nl2br(e($c['address'])) ?><?= $c['ico'] !== '' ? '<br>IČO: ' . e($c['ico']) : '' ?></div>
  </div>
  <dl class="pay-data invoice-dates">
    <dt>Datum vystavení</dt><dd><?= cz_date($d['issued']) ?></dd>
    <?php if ($paid): ?><dt>Datum plnění</dt><dd><?= cz_date($d['issued']) ?></dd><dt>Úhrada</dt><dd>uhrazeno převodem <?= cz_date($p['paid_at']) ?></dd>
    <?php else: ?><dt>Datum splatnosti</dt><dd><?= cz_date($d['due']) ?></dd><?php endif ?>
    <dt>Variabilní symbol</dt><dd><?= e($p['vs']) ?></dd>
    <?php if ($s['account'] !== ''): ?><dt>Účet dodavatele</dt><dd><?= e($s['account']) ?><?= $s['iban'] !== '' && !str_starts_with(strtoupper($s['account']), 'CZ') ? ' · IBAN ' . e(trim(chunk_split($s['iban'], 4, ' '))) : '' ?></dd><?php endif ?>
  </dl>
  <table class="table print-table invoice-items">
    <thead><tr><th>Položka</th><th class="t-actions">Cena</th></tr></thead>
    <tbody><tr><td>Provoz webu <?= e($d['site']) ?><?= $paid ? ' v období ' . cz_date($p['period_from']) . ' – ' . cz_date($p['period_to']) : ' na ' . ((int) $p['months'] === 12 ? '1 rok' : '1 měsíc') ?></td><td class="t-actions"><?= money_czk((int) $p['amount']) ?></td></tr></tbody>
    <tfoot><tr><th><?= $paid ? 'Celkem (uhrazeno)' : 'Celkem k úhradě' ?></th><th class="t-actions"><?= money_czk((int) $p['amount']) ?></th></tr></tfoot>
  </table>
  <?php if ($d['qr'] !== ''): ?><div class="pay-qr invoice-qr"><?= $d['qr'] ?><small>QR platba</small></div><?php endif ?>
  <?php if ($paid): ?><p class="invoice-zero">K úhradě: 0 Kč. Neplaťte, faktura je již uhrazena.</p><?php endif ?>
</div>
