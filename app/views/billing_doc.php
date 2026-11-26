<?php /* Zálohová faktura, faktura, nebo opravný doklad ke stornované faktuře: $d z invoice_view()/credit_view(), $backUrl */ $p = $d['p']; $s = $d['parties']['supplier']; $c = $d['parties']['customer']; $paid = $d['paid']; $credit = !empty($d['credit']); $sign = $credit ? '−' : '';
$vat = ['rate' => (int) ($p['vat_rate'] ?? 0)]; $vatOn = $vat['rate'] > 0; $split = vat_split((int) $p['amount'], $vat['rate']); ?>
<div class="wrap page narrow print-doc invoice">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= e($backUrl) ?>">← Zpět</a> <button class="btn btn-small" type="button" data-print>Vytisknout nebo uložit jako PDF</button></p>
  <header class="invoice-head">
    <h1><?= $credit ? ($vatOn ? 'Opravný daňový doklad č. ' : 'Opravný doklad č. ') . e($p['storno_no']) : ($paid ? ($vatOn ? 'Faktura – daňový doklad č. ' : 'Faktura č. ') . e($p['invoice_no']) : 'Zálohová faktura č. ' . e($p['vs'])) ?></h1>
    <p><?= $credit ? 'Opravný doklad k faktuře č. ' . e($p['invoice_no']) . ' ze dne ' . cz_date(substr((string) $p['paid_at'], 0, 10)) . '. ' . e($d['parties']['note'] ?? '') : ($paid ? e($d['parties']['note'] ?? '') : 'Výzva k platbě – není daňovým dokladem.') ?></p>
    <?php if (!empty($d['storno']) && !$credit): ?><p class="invoice-storno">STORNOVÁNO opravným dokladem č. <?= e($p['storno_no']) ?> ze dne <?= cz_date(substr((string) $p['storno_at'], 0, 10)) ?>.</p><?php endif ?>
  </header>
  <div class="invoice-parties">
    <div><span class="h-small">Dodavatel</span>
      <strong><?= e($s['name'] !== '' ? $s['name'] : pset('service_name')) ?></strong><br><?= nl2br(e($s['address'])) ?>
      <?= $s['ico'] !== '' ? '<br>IČO: ' . e($s['ico']) : '' ?><?= $s['dic'] !== '' ? '<br>DIČ: ' . e($s['dic']) : '' ?><?= $s['register'] !== '' ? '<br><small>' . e($s['register']) . '</small>' : '' ?>
      <?= $s['email'] !== '' ? '<br>' . e($s['email']) : '' ?><?= $s['phone'] !== '' ? ' · ' . e($s['phone']) : '' ?></div>
    <div><span class="h-small">Odběratel</span>
      <strong><?= e($c['name']) ?></strong><br><?= nl2br(e($c['address'])) ?><?= $c['ico'] !== '' ? '<br>IČO: ' . e($c['ico']) : '' ?><?= ($c['dic'] ?? '') !== '' ? '<br>DIČ: ' . e($c['dic']) : '' ?></div>
  </div>
  <dl class="pay-data invoice-dates">
    <dt>Datum vystavení</dt><dd><?= cz_date($d['issued']) ?></dd>
    <?php if ($credit): ?><dt><?= $vatOn ? 'Datum uskutečnění zdanitelného plnění' : 'Datum opravy' ?></dt><dd><?= cz_date($d['issued']) ?></dd><dt>Původní faktura</dt><dd>č. <?= e($p['invoice_no']) ?></dd><dt>Důvod opravy</dt><dd><?= e($p['storno_reason']) ?></dd>
    <?php elseif ($paid): ?><dt><?= $vatOn ? 'Datum uskutečnění zdanitelného plnění' : 'Datum plnění' ?></dt><dd><?= cz_date($d['issued']) ?></dd><dt>Úhrada</dt><dd>uhrazeno převodem <?= cz_date($p['paid_at']) ?></dd>
    <?php else: ?><dt>Datum splatnosti</dt><dd><?= cz_date($d['due']) ?></dd><?php endif ?>
    <dt>Variabilní symbol</dt><dd><?= e($p['vs']) ?></dd>
    <?php if ($s['account'] !== ''): ?><dt>Účet dodavatele</dt><dd><?= e($s['account']) ?><?= $s['iban'] !== '' && !str_starts_with(strtoupper($s['account']), 'CZ') ? ' · IBAN ' . e(trim(chunk_split($s['iban'], 4, ' '))) : '' ?></dd><?php endif ?>
  </dl>
  <table class="table print-table invoice-items">
    <thead><tr><th>Položka</th><th class="t-actions"><?= $vatOn ? 'Cena bez DPH' : 'Cena' ?></th></tr></thead>
    <tbody><?php if (!empty($d['lines'])): foreach ($d['lines'] as [$lt, $la]): ?><tr><td><?= e($lt) ?></td><td class="t-actions"><?= ($la < 0 ? '−' : $sign) . money_czk(abs((int) $la)) ?></td></tr><?php endforeach; else: ?><tr><td><?php if ($d['item']): $once = in_array($p['kind'] ?? '', ['migration', 'aicredits', 'credit'], true); ?><?= e($d['item']) ?><?= $once ? '' : ($paid ? ($p['period_from'] ? ' v období ' . cz_date($p['period_from']) . ' – ' . cz_date($p['period_to']) : '') : (in_array($p['kind'] ?? '', ['domain', 'ai'], true) ? ' na 1 rok' : '')) ?><?php else: ?>Provoz webu <?= e($d['site']) ?><?= $paid ? ' v období ' . cz_date($p['period_from']) . ' – ' . cz_date($p['period_to']) : ' na ' . ((int) $p['months'] === 12 ? '1 rok' : '1 měsíc') ?><?php endif ?></td><td class="t-actions"><?= $sign . money_czk((int) $p['amount']) ?></td></tr><?php endif ?></tbody>
    <tfoot><?php if ($vatOn): ?><tr class="vat-row"><td>Základ daně</td><td class="t-actions"><?= $sign . money_czk2($split['base']) ?></td></tr><tr class="vat-row"><td>DPH <?= (int) $vat['rate'] ?> %</td><td class="t-actions"><?= $sign . money_czk2($split['vat']) ?></td></tr><?php endif ?>
    <tr><th><?= $credit ? 'Celkem opravou' : ($paid ? 'Celkem (uhrazeno)' : 'Celkem k úhradě') ?><?= $vatOn ? ' včetně DPH' : '' ?></th><th class="t-actions"><?= $sign . pay_money($p) ?></th></tr></tfoot>
  </table>
  <?php if ($d['qr'] !== ''): ?><div class="pay-qr invoice-qr"><?= $d['qr'] ?><small><strong>QR platba – okamžitá platba</strong><br>Naskenujte kód v aplikaci své banky. Částka, účet a variabilní symbol se vyplní samy.</small></div><?php endif ?>
  <?php if ($credit): ?><p class="invoice-zero">Opravný doklad snižuje fakturu č. <?= e($p['invoice_no']) ?> o celou částku. Případné vrácení peněz proběhne převodem na účet odběratele.</p>
  <?php elseif ($paid): ?><p class="invoice-zero">K úhradě: 0 Kč. Neplaťte, faktura je již uhrazena.</p><?php endif ?>
</div>
