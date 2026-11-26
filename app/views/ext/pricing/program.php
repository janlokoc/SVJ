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
  <article class="leaflet">
    <header><strong><?= e(pset('service_name')) ?></strong> <span>Partnerský program pro správcovské firmy</span></header>
    <h3>Web pro každý Váš dům. Portál pro Vás zdarma.</h3>
    <ul>
      <li><strong>Portál zdarma</strong> – všechny spravované domy, hlášení a termíny na jednom místě.</li>
      <li><strong>Sleva<?= $maxPct ? ' až ' . $maxPct . ' %' : '' ?></strong> podle počtu domů, jedna faktura za všechny.</li>
      <li><strong>Vaše cena pro SVJ</strong> – marži spočítá portál, faktury SVJ vystavíte z portálu.</li>
      <li><strong>Vaše značka</strong> na webu každého domu a registrační odkaz pro nové domy.</li>
      <li><strong>Pro výbory:</strong> shromáždění s prezencí a hlasováním podle podílů, dálkové odečty s měsíční informací o spotřebě, závady, dokumenty, upozornění do telefonu.</li>
    </ul>
    <p class="leaflet-price">Web domu: Standard <?= money_czk($std) ?>, Premium <?= money_czk($prem) ?> za rok bez DPH<?= price_size_note('standard') !== '' ? ' (' . e(price_size_note('standard')) . ' ve Standardu)' : '' ?>. Edice Zdarma pro začátek.</p>
    <footer><?= e(platform_url('/pro-spravce')) ?><?= $contact ? ' · ' . e($contact) : '' ?><?= $op['phone'] !== '' ? ' · ' . e($op['phone']) : '' ?><?= $op['name'] !== '' ? '<br>' . e($op['name']) . ($op['ico'] !== '' ? ', IČO ' . e($op['ico']) : '') : '' ?></footer>
  </article>
</section>

<?php if ($mail): ?><section class="lpx-cta no-print"><div class="wrap"><h2>Domluvme se na partnerském účtu.</h2><a class="btn btn-big lpx-cta-btn" href="<?= e($mail) ?>">Napsat nám</a></div></section><?php endif ?>
