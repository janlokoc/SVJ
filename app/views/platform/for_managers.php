<?php /* Veřejná stránka pro správcovské firmy (partnery). */
$demoPortal = $demo ? platform_url('/partner/ukazka') : '';
$contact = filter_var(pset('contact_email'), FILTER_VALIDATE_EMAIL) ? pset('contact_email') : ''; ?>
<section class="lpx-hero lpx-hero-plain">
  <div class="wrap">
    <p class="lpx-eyebrow">Pro správcovské firmy a bytová družstva</p>
    <h1>Všechny Vaše domy na jednom místě. Portál máte zdarma.</h1>
    <p class="lpx-lead">Každý dům, který spravujete, dostane vlastní web pro vlastníky a výbor. Vy je řídíte z jednoho partnerského portálu – <strong>ten je pro Vás úplně zdarma</strong>. Platíte jen za weby domů, a ty Vám platí jednotlivá SVJ Vaší cenou, třeba jako součást správy.</p>
    <div class="actions"><?php if ($demoPortal): ?><a class="btn btn-big" href="<?= e($demoPortal) ?>">Prohlédnout ukázku portálu</a><?php endif ?><?php if ($contact): ?><a class="btn btn-big btn-ghost" href="mailto:<?= e($contact) ?>?subject=<?= rawurlencode('Partnerský účet pro správcovskou firmu') ?>">Chci partnerský účet</a><?php endif ?></div>
  </div>
</section>
<section class="lpx-section">
  <div class="wrap">
    <h2>Jak to funguje s penězi</h2>
    <ol class="mgr-flow">
      <li><strong>Partnerský portál: 0 Kč.</strong> Bez poplatku za firmu, za pracovníky ani za počet domů.</li>
      <li><strong>SVJ platí Vám.</strong> Za web domu si účtujete vlastní cenu (samostatně, nebo v ceně správy). Fakturu společenství vystavíte přímo z portálu, výbor ji dostane e-mailem i do administrace webu.</li>
      <li><strong>Vy platíte nám.</strong> Jednou fakturou za všechny domy, s množstevní slevou<?= ($tiers = partner_discount_tiers()) ? ' až ' . max(array_values($tiers)) . ' %' : '' ?>. Rozdíl je Váš výdělek – portál ho spočítá za Vás.</li>
    </ol>
    <p class="muted">Ceny edic pro jeden dům najdete v <a href="<?= url('/cenik') ?>">ceníku</a> (bez DPH). Podmínky, slevy, časté otázky a leták k tisku: <a href="<?= url('/pro-spravce/program') ?>">partnerský program</a>.</p>
  </div>
</section>
<section class="lpx-section">
  <div class="wrap">
    <h2>Co Vám portál ušetří</h2>
    <div class="mgr-grid">
      <div class="card"><h3>Přehled správy</h3><p>Všechny domy v jedné tabulce: otevřená hlášení, co je po termínu, rychlost reakce, revize. Dům, který potřebuje pozornost, je nahoře.</p></div>
      <div class="card"><h3>Výbor vidí Vaši práci</h3><p>Každý výbor má v administraci přehled správy domu s časovou osou toho, co jste udělali. Méně dotazů „co se vlastně děje“, spokojenější klient.</p></div>
      <div class="card"><h3>Hlášení z domů na jednom místě</h3><p>Závady a požadavky obyvatel ze všech domů, s řešitelem, termínem a zprávami s obyvatelem. Nová hlášení Vám chodí e-mailem.</p></div>
      <div class="card"><h3>Kalendář všech domů</h3><p>Shromáždění, revize, odečty a termíny hlášení napříč domy – i přímo ve Vašem Outlooku nebo Google kalendáři.</p></div>
      <div class="card"><h3>Hromadně a se šablonami</h3><p>Jedno oznámení do více domů najednou. Šablony pozvánek a oznámení použijete v každém domě.</p></div>
      <div class="card"><h3>Vaše značka na webu domu</h3><p>„Dům spravuje …“ s Vaším logem a kontaktem na každém webu. Registrační odkaz pro nové domy – výbor jedním zaškrtnutím potvrdí, že dům spravujete.</p></div>
    </div>
  </div>
</section>
<section class="lpx-cta">
  <div class="wrap"><h2>Vyzkoušejte si portál na smyšlené firmě se třemi domy.</h2><?php if ($demoPortal): ?><a class="btn btn-big lpx-cta-btn" href="<?= e($demoPortal) ?>">Otevřít ukázku portálu</a><?php endif ?></div>
</section>
