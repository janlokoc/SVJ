<?php
/** Kredit služeb domu. @var array $t @var int $balance (haléře) @var array $rows @var ?array $payment @var string $qr @var ?array $partner @var ?array $partnerPending
 *  @var array $expiring @var array $prices @var int $months @var int $freeLeft @var int $freeYear */
$iban = cz_iban(pset('account'));
?>
<div class="wrap page">
  <header class="page-head"><h1>Kredit služeb</h1>
    <p class="lead">Z předplaceného kreditu se platí služby podle skutečné spotřeby – SMS zprávy, lustrace exekucí, ověření hlasu přes Bank iD a online shromáždění. Kredit platí <?= (int) $months ?> měsíců od dobití; zůstatek se dá vrátit při zrušení služby.</p></header>

  <div class="two-col">
    <section class="card">
      <h2>Zůstatek</h2>
      <p class="sms-balance"><strong><?= e(credit_money($balance)) ?></strong></p>
      <?php if ($freeYear): ?><p>K tomu <?= (int) $freeLeft ?> z <?= (int) $freeYear ?> SMS v ceně edice Premium na letošní rok.</p><?php endif ?>
      <?php foreach ($expiring as $x): ?><p class="hint"><?= e(credit_money((int) $x['left_h'])) ?> propadne <?= e(cz_date((string) $x['expires_at'])) ?>, pokud ho do té doby nevyčerpáte.</p><?php endforeach ?>
      <?php if ($balance < credit_setting('credit_low_h')): ?><p class="hint">Když kredit nestačí, placené služby nejdou použít – tlačítka u nich nabídnou dobití.</p><?php endif ?>
      <?php if ($prices): ?>
      <h3>Ceny služeb</h3>
      <ul class="plain-list"><?php foreach ($prices as $r): ?><li><?= e($r['name']) ?>: <strong><?= e(credit_money((int) $r['h'])) ?></strong> <?= e($r['unit']) ?> <small class="muted">bez DPH</small></li><?php endforeach ?></ul>
      <?php endif ?>
    </section>

    <section class="card" id="dobit">
      <h2>Dobít kredit</h2>
      <?php if ($payment): $p = $payment; ?>
      <div id="platba">
        <h3>Platba za dobití <?= money_czk((int) $p['amount']) ?></h3>
        <?php if ($p['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($p['claimed_at']) ?>. Kredit připíšeme po potvrzení.</div><?php endif ?>
        <div class="pay"><?php if ($qr): ?><div class="pay-qr"><?= $qr ?><small>Načtěte v bankovní aplikaci</small></div><?php endif ?>
          <dl class="pay-data"><dt>Částka</dt><dd><strong><?= pay_money($p) ?></strong><?= pay_vat_hint($p) ?></dd><?php if ($iban): ?><dt>Číslo účtu</dt><dd><?= e(pset('account')) ?></dd><?php endif ?><dt>Variabilní symbol</dt><dd><code><?= e($p['vs']) ?></code></dd></dl></div>
        <div class="actions">
          <?php if ($p['status'] === 'pending'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><button class="btn" type="submit">Platbu jsem odeslal(a)</button></form><?php endif ?>
          <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $p['id']) ?>">Zálohová faktura k tisku</a>
          <?php if ($p['status'] === 'pending'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Zrušit objednávku</button></form><?php endif ?>
        </div>
      </div>
      <?php endif ?>
      <?php if ($partner): ?>
      <p>Předplatné domu platí správce <strong><?= e($partner['name']) ?></strong>. Dobití se přidá na jeho zálohovou fakturu a kredit připíšeme, až ji zaplatí.</p>
      <?php if ($partnerPending): ?><p class="callout">Na faktuře správce čeká dobití <strong><?= money_czk((int) $partnerPending[1]['price']) ?></strong><?= $partnerPending[0]['status'] === 'claimed' ? ' – platba je nahlášená a čeká na potvrzení' : '' ?>. Nová objednávka ho nahradí.</p><?php endif ?>
      <?php endif ?>
      <?php if (!$payment || $payment['status'] === 'pending'): ?>
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="order">
        <fieldset><legend>Částka bez DPH</legend>
          <?php foreach (CREDIT_TOPUPS as $i => $a): ?><label class="check"><input type="radio" name="amount" value="<?= $a ?>"<?= $i === 1 ? ' checked' : '' ?>> <span><?= money_czk($a) ?></span></label><?php endforeach ?>
          <label class="check"><input type="radio" name="amount" value="jina"> <span>Jiná částka</span></label>
          <label>Jiná částka (Kč, nejméně <?= CREDIT_TOPUP_MIN ?>) <input type="number" name="custom" min="<?= CREDIT_TOPUP_MIN ?>" max="<?= CREDIT_TOPUP_MAX ?>" step="1" inputmode="numeric"></label>
        </fieldset>
        <?= vat_payer() ? '<p class="hint">K částce se připočte DPH ' . (int) vat_rate_now() . "\u{a0}%; kredit se připíše v částce bez DPH.</p>" : '' ?>
        <button class="btn" type="submit"><?= $partner ? 'Přidat na fakturu správce' : ($payment ? 'Změnit objednávku' : 'Objednat dobití') ?></button>
      </form>
      <?php if (!$partner): ?><p class="hint">Dostanete zálohovou fakturu s QR kódem. Po připsání platby kredit připíšeme a pošleme fakturu.</p><?php endif ?>
      <?php endif ?>
    </section>
  </div>

  <section class="card">
    <h2>Pohyby kreditu</h2>
    <?php if (!$rows): ?><p class="muted">Zatím žádné pohyby.</p><?php else: ?>
    <table class="table"><thead><tr><th>Kdy</th><th>Co</th><th>Částka</th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?><tr>
        <td data-label="Kdy"><?= e(cz_date(substr($r['at'], 0, 10))) ?> <small class="muted"><?= e(substr($r['at'], 11, 5)) ?></small></td>
        <td data-label="Co"><?= e(CREDIT_KINDS[$r['kind']] ?? $r['kind']) ?><?= (int) $r['qty'] && in_array($r['kind'], CREDIT_SERVICES, true) ? ' · ' . (int) $r['qty'] . ' ks' : '' ?><?= $r['note'] !== '' ? '<br><small class="muted">' . e($r['note']) . '</small>' : '' ?></td>
        <td data-label="Částka"><?= (int) $r['delta_h'] > 0 ? '+' : '' ?><?= e((int) $r['delta_h'] === 0 ? 'v ceně edice' : credit_money((int) $r['delta_h'])) ?></td></tr><?php endforeach ?>
    </tbody></table>
    <p class="hint">Posledních 50 pohybů. Částky jsou bez DPH.</p>
    <?php endif ?>
  </section>
</div>
