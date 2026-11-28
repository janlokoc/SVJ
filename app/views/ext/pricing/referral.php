<?php /* Doporučovací program pro společenství (/doporuceni): jak funguje, kolik kdo dostane, podmínky. Částky z nastavení konzole. */
$rwd = pset_int('referral_reward', 0, 100000);
$welcome = pset_int('referral_welcome', 0, 100000);
?>
<section class="wrap lp-section lp-page-head">
  <p class="crumbs"><a href="<?= url('/cenik') ?>">← Ceník</a></p>
  <h1>Doporučte nás jinému společenství</h1>
  <p class="lead">Za každé společenství, které si přes Váš odkaz založí web a zaplatí edici, dostanete <strong><?= money_czk($rwd) ?></strong> na svou další platbu<?= $welcome ? ' – a oni slevu <strong>' . money_czk($welcome) . '</strong> na první rok' : '' ?>.</p>
  <div class="actions"><a class="btn btn-big" href="<?= url('/registrace') ?>">Založit web zdarma</a><a class="btn btn-big btn-ghost" href="#jak">Jak to funguje</a></div>
</section>

<section class="wrap lp-section" id="jak">
  <h2>Jak to funguje</h2>
  <ol class="ref-steps">
    <li><strong>Zkopírujte svůj odkaz.</strong> Najdete ho v administraci svého webu v části Předplatné → Doporučení a kredity. Můžete ho poslat e-mailem rovnou odtud.</li>
    <li><strong>Pošlete ho sousednímu SVJ</strong> – výboru, předsedovi, správci, komukoli, kdo se stará o dům.</li>
    <li><strong>Oni si založí web.</strong> Základní web je zdarma a bez časového omezení<?= $welcome ? '; při registraci přes Váš odkaz dostanou slevu ' . money_czk($welcome) . ', která se odečte od jejich první platby za edici' : '' ?>.</li>
    <li><strong>Když poprvé zaplatí roční edici, připíšeme Vám <?= money_czk($rwd) ?>.</strong> Přijde Vám o tom e-mail a kredit se sám odečte od Vaší příští platby za edici.</li>
  </ol>
  <p>V administraci průběžně vidíte, kolikrát někdo odkaz otevřel, kdo se zaregistroval, kdo zkouší placené funkce a za koho už máte kredit.</p>
</section>

<section class="wrap lp-section" id="podminky">
  <h2>Podmínky</h2>
  <ul>
    <li>Odměna je za každé doporučené společenství jednou – ve chvíli, kdy poprvé zaplatí roční edici. Počet doporučení není omezený, kredity se sčítají.</li>
    <li>Kredit se nevyplácí v penězích; odečítá se od plateb za edici Vašeho webu.</li>
    <li>Doporučit lze jen jiné společenství – registrace se stejným kontaktním e-mailem jako Váš web se nepočítá.</li>
    <li>Správcovské firmy mají místo toho vlastní <a href="<?= url('/pro-spravce/program') ?>">partnerský program</a> s množstevní slevou.</li>
  </ul>
</section>
