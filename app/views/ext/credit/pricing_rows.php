<?php
/* Veřejný ceník: služby podle spotřeby (z předplaceného kreditu) a opakované doplňky – ceny bez DPH i s DPH. Funkce, kterou provozovatel ještě nespustil, má štítek „připravujeme“. */
$crRows = credit_price_list();
$crIncluded = [['insolvence', 'Hlídání insolvencí vlastníků', 'Premium'], ['bank-psd2', 'Propojení s účty u dalších bank (PSD2)', 'Premium'], ['sso', 'Přihlášení přes Google, Microsoft, Apple a Facebook', 'všechny edice']];
$crTag = fn(string $key) => match (beta_stage($key)) { 'off' => ' <span class="tag">připravujeme</span>', 'beta' => ' <span class="tag tag-beta">Beta</span>', default => '' };
?>
  <h3 class="lp-sub" id="sluzby">Služby z kreditu a doplňky <small class="vat-tag">ceny bez DPH a s DPH</small></h3>
  <p class="hint">Služby placené podle spotřeby se strhávají z předplaceného <strong>kreditu služeb</strong> – dobijete ho v administraci od <?= money_czk(CREDIT_TOPUP_MIN) ?> zálohovou fakturou s QR kódem a platí <?= credit_setting('credit_valid_months') ?> měsíců. Nevyčerpaný zůstatek při zrušení služby vrátíme.</p>
  <table class="table price-table">
    <thead><tr><th>Služba</th><th class="t-actions">Bez DPH</th><th class="t-actions"><?= vat_payer() ? 'S DPH ' . (int) vat_rate_now() . "\u{a0}%" : 'Konečná cena' ?></th></tr></thead>
    <tbody>
    <?php foreach ($crRows as $r): ?>
      <tr><td><strong><?= e($r['name']) ?></strong><?= $crTag($r['key']) ?><br><small class="muted"><?= e($r['desc']) ?> <?= $r['credit'] ? 'Platí se z kreditu služeb.' : '' ?></small></td>
        <td class="t-actions"><strong><?= e(credit_money((int) $r['h'])) ?></strong><br><small class="muted"><?= e($r['unit']) ?></small></td>
        <td class="t-actions"><?= e(credit_money(credit_gross_h((int) $r['h']))) ?></td></tr>
    <?php endforeach ?>
    <?php foreach ($crIncluded as [$key, $name, $where]): ?>
      <tr><td><strong><?= e($name) ?></strong><?= $crTag($key) ?></td><td class="t-actions" colspan="2">v ceně – <?= e($where) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php if (!vat_payer()): ?><p class="hint">Nejsme plátci DPH – ceny jsou konečné.</p><?php endif ?>
