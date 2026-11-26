<?php
/* Doplňky k ročnímu předplatnému (v administraci Předplatné): zapnuté doplňky a výzva k platbě za zbytek období. $t, $iban z admin/billing.php. */
$adList = addons_active($t['slug']);
$adPays = pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'addon' AND status IN ('pending','claimed') ORDER BY id", [$t['slug']])->fetchAll();
if ($adList || $adPays): ?>
  <div class="card" id="doplnky">
    <h2>Doplňky k předplatnému</h2>
    <?php if ($adList): ?>
    <ul class="plain-list"><?php foreach ($adList as $ad): ?><li><?= e(addon_label($ad['addon'], (int) $ad['qty'])) ?> – <?= money_czk(addon_price($ad['addon'], (int) $ad['qty'])) ?> ročně bez DPH<?= $ad['paid_until'] !== '' && $ad['paid_until'] >= date('Y-m-d') ? ', zaplaceno do ' . cz_date($ad['paid_until']) : '' ?></li><?php endforeach ?></ul>
    <p class="hint">Doplňky se platí spolu s ročním předplatným edice. Zapnete-li doplněk v průběhu zaplaceného období, zaplatíte jen poměrnou část do jeho konce.</p>
    <?php endif ?>
    <?php foreach ($adPays as $ap): ?>
      <h3>Platba za <?= e(implode(', ', array_column(payment_addons($ap), 'name'))) ?> do <?= cz_date((string) (payment_addons($ap)[0]['to'] ?? '')) ?></h3>
      <?php if ($ap['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($ap['claimed_at']) ?>. Čeká na potvrzení provozovatelem.</div><?php endif ?>
      <dl class="pay-data"><dt>Částka</dt><dd><strong><?= pay_money($ap) ?></strong><?= pay_vat_hint($ap) ?></dd><?php if ($iban): ?><dt>Číslo účtu</dt><dd><?= e(pset('account')) ?></dd><?php endif ?><dt>Variabilní symbol</dt><dd><code><?= e($ap['vs']) ?></code></dd></dl>
      <div class="actions">
        <?php if ($ap['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/doplnek') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $ap['id'] ?>"><button class="btn" type="submit">Platbu jsem odeslal(a)</button></form><?php endif ?>
        <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $ap['id']) ?>">Zálohová faktura s QR kódem</a>
      </div>
    <?php endforeach ?>
  </div>
<?php endif ?>
