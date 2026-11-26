<?php $disc = partner_discount(count($houses)); $def = (int) ($partner['resale'] ?? 0); ?>
<div class="wrap page">
  <header class="page-head"><h1><?= e($partner['name']) ?></h1>
    <p class="lead">Jak se firma ukazuje na webech domů, odkaz pro nové domy a cena, kterou účtujete společenstvím.</p></header>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <div class="two-col">
    <form method="post" class="card form" enctype="multipart/form-data"><h2>Značka na webech domů</h2><?= csrf_field() ?><input type="hidden" name="action" value="brand">
      <p class="muted">V patičce každého webu, který spravujete, je „Dům spravuje …“ s logem a kontaktem. Obyvatelé tak vědí, na koho se obrátit – a každý web je i Vaše vizitka.</p>
      <label class="check"><input type="checkbox" name="brand" value="1"<?= (int) ($partner['brand'] ?? 1) ? ' checked' : '' ?><?= $owner ? '' : ' disabled' ?>> Ukazovat firmu na webech domů</label>
      <label>Web firmy <input type="text" inputmode="url" name="web" value="<?= e($partner['web'] ?? '') ?>" placeholder="https://www.vase-firma.cz"<?= $owner ? '' : ' disabled' ?>></label>
      <div class="form-row"><label>Telefon pro obyvatele <input type="text" name="phone" value="<?= e($partner['phone']) ?>"<?= $owner ? '' : ' disabled' ?>></label><label>E-mail pro obyvatele <input type="email" name="email" value="<?= e($partner['email']) ?>"<?= $owner ? '' : ' disabled' ?>></label></div>
      <?php if ($logo !== ''): ?><p><img src="<?= e($logo) ?>" alt="Logo firmy" class="partner-brand-logo"> <?php if ($owner): ?><label class="check"><input type="checkbox" name="logo_remove" value="1"> Odebrat logo</label><?php endif ?></p><?php endif ?>
      <?php if ($owner): ?><label>Logo <small class="muted">(PNG nebo JPG do 200 kB)</small> <input type="file" name="logo" accept="image/png,image/jpeg"></label>
      <button class="btn" type="submit">Uložit</button><?php else: ?><p class="hint">Údaje mění správce účtu firmy.</p><?php endif ?>
    </form>
    <section class="card form"><h2>Odkaz pro nové domy</h2>
      <p class="muted">Pošlete ho společenstvím, která chtějí web. Při registraci výbor jedním zaškrtnutím potvrdí, že dům spravujete – nemusíte pak o přístup žádat a dům se Vám hned ukáže v portálu.</p>
      <p><code class="totp-key"><?= e($refUrl) ?></code></p>
      <p class="hint">Web se zakládá v edici Zdarma; edici pak objednáváte na společné faktuře.</p>
    </section>
  </div>
  <form method="post" class="card form" id="fakturace-svj"><h2>Fakturační údaje pro SVJ</h2><?= csrf_field() ?><input type="hidden" name="action" value="svjbill">
    <p class="muted">Společenstvím fakturujete za web přímo z portálu (<a href="<?= url('/fakturace/svj') ?>">Faktury společenstvím</a>). Na faktuře budou údaje firmy (název, IČO, DIČ, sídlo – mění je provozovatel služby) a tyto platební údaje.</p>
    <div class="form-row"><label>Číslo účtu <input type="text" name="account" value="<?= e($partner['account'] ?? '') ?>" placeholder="123456789/0100"<?= $owner ? '' : ' disabled' ?>></label>
      <label>IBAN <small class="muted">(pro QR platbu)</small> <input type="text" name="iban" value="<?= e($partner['iban'] ?? '') ?>" placeholder="CZ65 0800 0000 1920 0014 5399"<?= $owner ? '' : ' disabled' ?>></label></div>
    <div class="form-row"><label>Splatnost (dní) <input type="number" name="inv_due" value="<?= (int) ($partner['inv_due'] ?? 14) ?>" min="0" max="90"<?= $owner ? '' : ' disabled' ?>></label>
      <label class="check"><input type="checkbox" name="vat" value="1"<?= (int) ($partner['vat_rate'] ?? 0) ? ' checked' : '' ?><?= $owner ? '' : ' disabled' ?>> Jsme plátci DPH (21 %)</label></div>
    <?php if ($owner): ?><button class="btn" type="submit">Uložit</button><?php endif ?></form>
  <form method="post" class="card form" id="cena"><h2>Cena pro SVJ a Vaše marže</h2><?= csrf_field() ?><input type="hidden" name="action" value="resale">
    <p class="muted">Edice platíte na společné faktuře s množstevní slevou (teď <?= $disc ?> %). Portál je pro Vás zdarma: platíte jen za weby domů a ty Vám platí jednotlivá SVJ – Vaší cenou, třeba jako součást správy. Tady si cenu zapište a uvidíte, kolik na každém domě vyděláte; faktury společenstvím pak vystavíte v části <a href="<?= url('/fakturace/svj') ?>">Faktury společenstvím</a>. Ceny jsou bez DPH.</p>
    <label>Vaše roční cena za web pro SVJ <small class="muted">(výchozí pro všechny domy)</small> <input type="text" name="resale" value="<?= $def ?: '' ?>" inputmode="numeric" placeholder="např. 4 500"<?= $owner ? '' : ' disabled' ?>></label>
    <?php if ($houses): ?>
    <div class="table-scroll"><table class="table"><thead><tr><th>Dům</th><th>Edice</th><th>Platíte nám</th><th>Účtujete SVJ</th><th>Marže / rok</th></tr></thead><tbody>
      <?php $sumM = 0; foreach ($houses as $h): $ed = billing_state($h)['edition']; $ed = $ed === 'free' ? 'standard' : $ed; $cost = (int) round(edition_price($ed, $h) * (100 - $disc) / 100); $sell = (int) ($h['partner_resale'] ?? 0) ?: $def; $m = $sell ? $sell - $cost : null; $sumM += (int) $m; ?>
      <tr><td data-label="Dům"><?= e($h['name']) ?></td><td data-label="Edice"><?= e(EDITIONS[$ed]) ?></td><td data-label="Platíte nám"><?= money_czk($cost) ?></td>
        <td data-label="Účtujete SVJ"><input type="text" name="house[<?= e($h['slug']) ?>]" value="<?= (int) ($h['partner_resale'] ?? 0) ?: '' ?>" inputmode="numeric" size="8" placeholder="<?= $def ?: '—' ?>" aria-label="Cena pro <?= e($h['name']) ?>"<?= $owner ? '' : ' disabled' ?>></td>
        <td data-label="Marže / rok"><?= $m === null ? '<span class="muted">—</span>' : '<strong' . ($m < 0 ? ' class="is-neg"' : '') . '>' . money_czk($m) . '</strong>' ?></td></tr>
      <?php endforeach ?>
      <tr class="t-sum"><td colspan="4"><strong>Celkem za rok</strong></td><td><strong><?= money_czk($sumM) ?></strong></td></tr>
    </tbody></table></div>
    <p class="hint">Dům ve Zdarma počítáme jako Standard (tak ho nejspíš objednáte). Platíte nám = cena edice po množstevní slevě.</p>
    <?php endif ?>
    <?php if ($owner): ?><button class="btn" type="submit">Uložit ceny</button><?php endif ?>
  </form>
</div>
