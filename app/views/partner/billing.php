<?php /* Společná faktura partnera. $houses = domy, za které platí partner; $open = otevřená výzva k platbě; $tiers = [počet domů => %] */ ?>
<div class="wrap page">
  <header class="page-head"><h1>Fakturace</h1>
    <p class="lead">Partnerský portál je pro Vás <strong>zdarma</strong>. Platíte jen za weby domů – najednou, jednou fakturou s množstevní slevou – a ty Vám platí jednotlivá SVJ Vaší cenou. Po připsání platby se předplatné každého domu na faktuře prodlouží o rok.</p>
    <p><a class="btn btn-ghost" href="<?= url('/fakturace/svj') ?>">Faktury společenstvím (SVJ platí Vám)</a></p></header>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>

  <?php if ($open): $lines = partner_lines($open); ?>
  <section class="card bill-pay" id="platba">
    <h2><?= $open['status'] === 'claimed' ? 'Platba nahlášena – čeká na připsání' : 'Zálohová faktura k zaplacení' ?></h2>
    <div class="pay">
      <?php if ($qr): ?><div class="pay-qr"><?= $qr ?><small>QR platba – okamžitá platba</small></div><?php endif ?>
      <dl class="pay-data">
        <dt>Částka</dt><dd><?= pay_money($open) ?></dd>
        <dt>Účet</dt><dd><?= e(pset('account')) ?></dd>
        <dt>Variabilní symbol</dt><dd><?= e($open['vs']) ?></dd>
        <dt>Domů na faktuře</dt><dd><?= count($lines) ?><?= (int) $open['discount'] ? ' · sleva ' . money_czk((int) $open['discount']) : '' ?></dd>
      </dl>
    </div>
    <ul class="plain-list"><?php foreach ($lines as $l): ?><li><?= e($l['name']) ?> – <?= e(EDITIONS[$l['edition']] ?? $l['edition']) ?>, <?= money_czk((int) $l['price']) ?></li><?php endforeach ?></ul>
    <div class="actions">
      <a class="btn btn-ghost" href="<?= url('/fakturace/doklad/' . $open['id']) ?>">Zálohová faktura k tisku</a>
      <?php if ($open['status'] === 'pending'): ?>
      <form method="post" class="inline-form"><?= csrf_field() ?><button class="btn" type="submit" name="action" value="claim">Zaplaceno – odeslali jsme platbu</button></form>
      <form method="post" class="inline-form" data-confirm="Zrušit tuto zálohovou fakturu?"><?= csrf_field() ?><button class="btn btn-ghost" type="submit" name="action" value="cancel">Zrušit</button></form>
      <?php endif ?>
    </div>
    <?php if ($open['status'] === 'pending'): ?><p class="hint">Fakturu můžete ještě změnit: vyberte domy níže a vystavte ji znovu – nahradí tuto.</p><?php endif ?>
  </section>
  <?php endif ?>

  <?php if (!$houses): ?>
  <div class="card"><p>Zatím neplatíte za žádný dům. Domy, které založíte, jsou na Vaší faktuře automaticky; u připojených webů o tom rozhoduje výbor při schválení přístupu.</p></div>
  <?php elseif (!$open || $open['status'] === 'pending'): ?>
  <form method="post" class="card form">
    <?= csrf_field() ?><input type="hidden" name="action" value="order">
    <h2>Objednat předplatné</h2>
    <table class="table">
      <thead><tr><th></th><th>Dům</th><th>Současný stav</th><th>Edice na další rok</th></tr></thead>
      <tbody><?php foreach ($houses as $h): $bs = $h['billing_state']; $soon = $bs['mode'] !== 'paid' || $bs['days'] <= 60; ?>
        <tr>
          <td><input type="checkbox" name="houses[]" value="<?= e($h['slug']) ?>" id="h-<?= e($h['slug']) ?>"<?= $soon ? ' checked' : '' ?>></td>
          <td data-label="Dům"><label for="h-<?= e($h['slug']) ?>"><strong><?= e($h['name']) ?></strong></label></td>
          <td data-label="Současný stav">Edice <?= e(EDITIONS[$bs['edition']] ?? $bs['edition']) ?><?= $bs['mode'] === 'paid' ? ' do ' . cz_date($bs['until']) : ($bs['mode'] === 'grace' ? ' – po splatnosti' : '') ?></td>
          <td data-label="Edice"><select name="edition[<?= e($h['slug']) ?>]"><?php foreach (['standard', 'premium'] as $ek): ?><option value="<?= $ek ?>"<?= ($bs['edition'] === $ek || ($ek === 'standard' && !in_array($bs['edition'], ['standard', 'premium'], true))) ? ' selected' : '' ?>><?= e(EDITIONS[$ek]) ?> – <?= money_czk(edition_price($ek, $h)) ?></option><?php endforeach ?></select></td>
        </tr>
      <?php endforeach ?></tbody>
    </table>
    <p class="hint">Předvybrané jsou domy, kterým předplatné končí do 60 dnů nebo už skončilo. Ceny jsou za rok bez DPH. Při přechodu na vyšší edici se odečte nevyčerpaná část té dosavadní.
      Množstevní sleva podle počtu domů ve správě: <?= e(implode(', ', array_map(fn($n, $p) => 'od ' . $n . ' domů ' . $p . ' %', array_keys($tiers), $tiers))) ?>.<?= $discount ? ' Vaše sleva teď: <strong>' . $discount . ' %</strong>.' : '' ?></p>
    <div class="actions"><button class="btn btn-big" type="submit">Vystavit zálohovou fakturu</button></div>
  </form>
  <?php endif ?>

  <?php if ($history): ?>
  <h2>Faktury</h2>
  <table class="table"><thead><tr><th>Faktura</th><th>Zaplaceno</th><th>Částka</th><th>Domů</th></tr></thead><tbody>
    <?php foreach ($history as $p): ?><tr<?= $p['status'] === 'storno' ? ' class="is-off"' : '' ?>><td data-label="Faktura"><a href="<?= url('/fakturace/doklad/' . $p['id']) ?>"><?= e($p['invoice_no']) ?></a><?= $p['status'] === 'storno' ? ' <small class="muted">stornováno</small>' : '' ?></td>
      <td data-label="Zaplaceno"><?= cz_date(substr((string) $p['paid_at'], 0, 10)) ?></td><td data-label="Částka"><?= pay_money($p) ?></td><td data-label="Domů"><?= count(partner_lines($p)) ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php endif ?>
</div>
