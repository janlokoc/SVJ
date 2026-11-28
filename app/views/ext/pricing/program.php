<?php /* Modul G: partnerský program pro správcovské firmy (/pro-spravce/program) s letákem k tisku. */
$tiers = partner_discount_tiers();
$std = edition_price('standard');
$prem = edition_price('premium');
$contact = filter_var(pset('contact_email'), FILTER_VALIDATE_EMAIL) ? pset('contact_email') : '';
$mail = $contact ? 'mailto:' . $contact . '?subject=' . rawurlencode('Partnerský program – ' . pset('service_name')) : '';
$demoPortal = demo_tenant() ? platform_url('/partner/ukazka') : '';
$maxPct = $tiers ? max($tiers) : 0;
$exampleSell = (int) (ceil($std * 1.5 / 100) * 100);   // příklad ceny pro SVJ; cenu určuje partner
$op = operator();
?>
<section class="wrap lp-section lp-page-head no-print">
  <p class="crumbs"><a href="<?= url('/pro-spravce') ?>">← Pro správce domů</a></p>
  <h1>Partnerský program pro správcovské firmy</h1>
  <p class="lead">Spravujete domy? Nabídněte svým SVJ web domu pod svou správou. Portál pro Vás je zdarma, za weby platíte jednou fakturou s množstevní slevou a SVJ účtujete svou cenu.</p>
  <div class="actions"><?php if ($mail): ?><a class="btn btn-big" href="<?= e($mail) ?>">Chci se stát partnerem</a><?php endif ?><?php if ($demoPortal): ?><a class="btn btn-big btn-ghost" href="<?= e($demoPortal) ?>">Ukázka partnerského portálu</a><?php endif ?><a class="btn btn-big btn-ghost" href="#letak">Leták k tisku</a></div>
</section>

<section class="wrap lp-section no-print" id="podminky">
  <h2>Podmínky programu</h2>
  <div class="mgr-grid">
    <div class="card"><h3>Portál zdarma</h3><p>Partnerský portál nic nestojí – bez poplatku za firmu, za pracovníky i za počet domů. Přehled všech domů, hlášení, kalendář, hromadná oznámení, šablony a Vaše značka na webu domu.</p></div>
    <div class="card"><h3>Množstevní sleva</h3><p>Za weby domů, které platíte Vy, dostanete slevu podle počtu domů ve správě<?= $maxPct ? ' – až ' . $maxPct . ' %' : '' ?>. Sleva se počítá z ceny všech domů na faktuře.</p></div>
    <div class="card"><h3>Vaše marže</h3><p>SVJ platí Vám Vaši cenou – samostatně, nebo jako součást ceny správy. Rozdíl mezi Vaší cenou a naší cenou po slevě je Váš. Portál marži u každého domu spočítá.</p></div>
    <div class="card"><h3>Fakturace SVJ</h3><p>Faktury společenstvím vystavíte přímo z portálu; výbor je dostane e-mailem i do administrace svého webu. Nám platíte jednou společnou fakturou za všechny domy.</p></div>
  </div>
  <h3 class="lp-sub">Množstevní sleva <small class="vat-tag">ceny bez DPH</small></h3>
  <div class="table-wrap"><table class="table price-table">
    <thead><tr><th>Domů ve správě</th><th class="t-num">Sleva</th><th class="t-num">Standard za dům a rok</th><th class="t-num">Premium za dům a rok</th></tr></thead>
    <tbody>
      <tr><td data-label="Domů">1–<?= $tiers ? array_key_first($tiers) - 1 : '…' ?></td><td data-label="Sleva" class="t-num">0 %</td><td data-label="Standard" class="t-num"><?= money_czk($std) ?></td><td data-label="Premium" class="t-num"><?= money_czk($prem) ?></td></tr>
      <?php foreach ($tiers as $min => $pct): ?>
      <tr><td data-label="Domů">od <?= (int) $min ?></td><td data-label="Sleva" class="t-num"><?= (int) $pct ?> %</td><td data-label="Standard" class="t-num"><?= money_czk((int) round($std * (100 - $pct) / 100)) ?></td><td data-label="Premium" class="t-num"><?= money_czk((int) round($prem * (100 - $pct) / 100)) ?></td></tr>
      <?php endforeach ?>
    </tbody>
  </table></div>
  <?php if (price_size_note('standard') !== ''): ?><p class="hint">Cena domu: <?= e(price_size_note('standard')) ?> ve Standardu<?= price_size_note('premium') !== '' ? ', ' . e(price_size_note('premium')) . ' v Premium' : '' ?>.</p><?php endif ?>
  <p class="hint">Příklad: spravujete <?= $tiers ? array_key_first($tiers) : 5 ?> domů a SVJ si účtujete <?= money_czk($exampleSell) ?> ročně za web edice Standard (cenu určujete Vy). Nám zaplatíte <?= money_czk((int) round($std * (100 - partner_discount($tiers ? array_key_first($tiers) : 5)) / 100)) ?> za dům; marže <?= money_czk($exampleSell - (int) round($std * (100 - partner_discount($tiers ? array_key_first($tiers) : 5)) / 100)) ?> za dům a rok.</p>
</section>

<section class="wrap lp-section narrow no-print" id="otazky">
  <h2>Časté otázky partnerů</h2>
  <details class="lp-faq"><summary>Musí s tím výbor souhlasit?</summary><p>Ano. Web, který založíte pro klienta, je hned Váš; k existujícímu webu požádáte o přístup a výbor ho jedním kliknutím schválí. Výbor může přístup kdykoli odebrat – data patří společenství.</p></details>
  <details class="lp-faq"><summary>Kdo platí předplatné?</summary><p>U každého domu si vyberete: buď platíte Vy (jednou fakturou s množstevní slevou) a SVJ účtujete svou cenu, nebo si web platí SVJ samo přímo nám.</p></details>
  <details class="lp-faq"><summary>Počítá se do slevy každý dům?</summary><p>Do počtu pro slevu se počítají všechny domy, které ve správě máte a jejichž výbor přístup schválil. Sleva se uplatní na všechny domy na faktuře.</p></details>
  <details class="lp-faq"><summary>Uvidí obyvatelé naši firmu?</summary><p>Ano, pokud chcete: na webu domu je „Dům spravuje …“ s Vaším logem a kontaktem. Váš registrační odkaz pro nové domy najdete v portálu.</p></details>
  <details class="lp-faq"><summary>Mohou naši pracovníci vstupovat do webů domů?</summary><p>Ano, z portálu jedním kliknutím, bez dalšího hesla. Do limitu správců edice se nepočítají a v záznamech webu je vidět jméno pracovníka i firma.</p></details>
  <details class="lp-faq"><summary>Kolik stojí partnerský účet?</summary><p>Nic. Platíte jen za weby domů, za které jste se rozhodli platit.</p></details>
</section>

<section class="wrap lp-section" id="letak">
  <h2 class="no-print">Leták k tisku</h2>
  <p class="hint no-print">Jedna strana A4 pro schůzku s klientem nebo do kanceláře. Vytiskněte ji, nebo uložte jako PDF (Tisk → Uložit jako PDF).</p>
  <div class="actions no-print"><button class="btn" type="button" data-print>Vytisknout leták</button></div>
  <?php $lfUrl = platform_url('/pro-spravce/program'); $lfShot = is_file(__DIR__ . '/../../../../assets/landing/partner-prehled.jpg'); ?>
  <div class="leaflet-stage">
  <article class="leaflet">
    <header class="lf-band">
      <div class="lf-brand"><svg class="lf-mark" viewBox="0 0 44 32" aria-hidden="true"><rect x="1" y="9" width="9" height="22" rx="1.5"/><rect x="12" y="3" width="9" height="28" rx="1.5"/><rect x="23" y="9" width="9" height="22" rx="1.5"/><rect x="34" y="3" width="9" height="28" rx="1.5"/></svg>
        <strong><?= e(pset('service_name')) ?></strong></div>
      <p class="lf-kicker">Partnerský program pro správcovské firmy</p>
      <h3>Web pro každý Váš dům.<br>Portál pro Vás zdarma.</h3>
      <p class="lf-lead">Všechny spravované domy, hlášení závad, termíny a faktury na jednom místě – a každé SVJ s vlastním webem pod Vaší značkou.</p>
    </header>
    <?php if ($lfShot): ?><figure class="lf-shot"><img src="<?= url('/assets/landing/partner-prehled.jpg') ?>" alt="Partnerský portál: přehled domů ve správě se závadami, termíny a revizemi" width="1280" height="800"></figure><?php endif ?>
    <ul class="lf-benefits">
      <li><span class="lf-num">1</span><strong>Portál zdarma</strong><span>Bez poplatku za firmu, pracovníky i počet domů.</span></li>
      <li><span class="lf-num">2</span><strong>Sleva<?= $maxPct ? ' až ' . $maxPct . ' %' : '' ?></strong><span>Podle počtu domů. Jedna faktura za všechny.</span></li>
      <li><span class="lf-num">3</span><strong>Vaše cena pro SVJ</strong><span>Marži spočítá portál, faktury SVJ vystavíte z něj.</span></li>
      <li><span class="lf-num">4</span><strong>Vaše značka</strong><span>Na webu každého domu a v odkazu pro nové domy.</span></li>
    </ul>
    <section class="lf-committee">
      <h4>Co dostanou výbory a vlastníci</h4>
      <p>Shromáždění s prezencí a hlasováním podle podílů · dálkové odečty s měsíční informací o spotřebě · hlášení závad · dokumenty · upozornění do telefonu</p>
    </section>
    <div class="lf-bottom">
      <div class="lf-prices">
        <h4>Web domu <small>ceny za rok bez DPH</small></h4>
        <dl>
          <div><dt>Zdarma</dt><dd>0 Kč</dd></div>
          <div><dt>Standard</dt><dd><?= money_czk($std) ?></dd></div>
          <div><dt>Premium</dt><dd><?= money_czk($prem) ?></dd></div>
        </dl>
        <?php if (price_size_note('standard') !== ''): ?><p class="lf-note">Standard: <?= e(price_size_note('standard')) ?>.</p><?php endif ?>
        <?php if ($tiers): ?><p class="lf-tiers"><?php foreach ($tiers as $from => $pct): ?><span>od <?= (int) $from ?> domů <strong>−<?= (int) $pct ?> %</strong></span><?php endforeach ?></p><?php endif ?>
      </div>
      <div class="lf-qr"><?= qr_svg($lfUrl, 'QR kód: partnerský program') ?><span>Podrobnosti a ukázka portálu</span></div>
    </div>
    <footer class="lf-foot"><span><?= e(preg_replace('~^https?://~', '', $lfUrl)) ?></span><?= $contact ? '<span>' . e($contact) . '</span>' : '' ?><?= $op['phone'] !== '' ? '<span>' . e($op['phone']) . '</span>' : '' ?><?= $op['name'] !== '' ? '<span>' . e($op['name']) . ($op['ico'] !== '' ? ', IČO ' . e($op['ico']) : '') . '</span>' : '' ?></footer>
  </article>
  </div>
</section>

<?php if ($mail): ?><section class="lpx-cta no-print"><div class="wrap"><h2>Domluvme se na partnerském účtu.</h2><a class="btn btn-big lpx-cta-btn" href="<?= e($mail) ?>">Napsat nám</a></div></section><?php endif ?>
