<?php /* Modul G: kampaň „Měsíční informace o spotřebě“ (/mesicni-informace-o-spotrebe).
 * Právní údaje podle výkladu MMR (zdroj uveden na stránce, načteno 26. 11. 2026). Čísla paragrafů, která výklad neuvádí, na stránce nejsou. */
$mmr = 'https://mmr.gov.cz/getmedia/224540f8-975f-4c66-a2fc-3c980b37ad29/Vyklad-a-typicke-dotazy-k-problematice-povinneho-poskytovani-informaci-o-mereni-tepla-a-informaci-poskytovatelum-energetickych-sluzeb-v-bytovych-objektech_1.pdf.aspx?ext=.pdf';
$open = pset('signup_open') === '1';
$std = edition_price('standard');
?>
<?php $shots = is_file(__DIR__ . '/../../../../assets/landing/odecty-sprava.jpg') && is_file(__DIR__ . '/../../../../assets/landing/odecty-telefon.jpg'); ?>
<section class="lpx-hero<?= $shots ? '' : ' lpx-hero-plain' ?>">
  <div class="wrap<?= $shots ? ' lpx-hero-grid' : '' ?>">
    <div class="lpx-hero-text">
    <p class="lpx-eyebrow lpx-law">Povinnost nejpozději od ledna 2027</p>
    <h1>Měsíční informace o spotřebě tepla a teplé vody – vyřešená webem domu</h1>
    <p class="lpx-lead">Kde se teplo a teplá voda odečítají na dálku, musí každý vlastník a nájemník <strong>každý měsíc dostat informaci o své spotřebě</strong>. Do konce roku 2026 musí mít dálkově odečitatelná měřidla prakticky každý dům s ústředním topením. Web domu ji obyvatelům zpřístupní a upozorní je – automaticky.</p>
    <div class="actions"><?php if ($open): ?><a class="btn btn-big" href="<?= url('/registrace') ?>">Založit web zdarma</a><?php endif ?><a class="btn btn-big btn-ghost" href="#jak">Jak to funguje</a></div>
    </div>
    <?php if ($shots): ?>
    <div class="lpx-row-media lpx-meter-media">
      <div class="lpx-browser"><div class="lpx-bar" aria-hidden="true"><i></i><i></i><i></i></div><img src="<?= asset('landing/odecty-sprava.jpg') ?>" alt="Dálkové odečty v administraci: stav za minulý měsíc a nastavení" decoding="async"></div>
      <div class="lpx-phone"><img src="<?= asset('landing/odecty-telefon.jpg') ?>" alt="Spotřeba po měsících s grafem v telefonu obyvatele" decoding="async"></div>
    </div>
    <?php endif ?>
  </div>
</section>

<section class="lpx-section">
  <div class="wrap narrow">
    <h2>Co zákon ukládá</h2>
    <ul class="lp-check">
      <li><strong>Dálkově odečitatelná měřidla do konce roku 2026.</strong> Měřiče tepla a teplé vody a indikátory topných nákladů musí jít odečíst bez vstupu do bytu (zákon č. 406/2000 Sb., o hospodaření energií). Za nesplnění hrozí od Státní energetické inspekce pokuta až 200 000 Kč.</li>
      <li><strong>Informace o spotřebě každý měsíc.</strong> Jakmile se odečítá na dálku, musí poskytovatel služeb – u bytových domů společenství vlastníků – poskytovat obyvatelům informaci o zjištěné spotřebě tepla a teplé vody za každý kalendářní měsíc (zákon č. 67/2013 Sb., o službách, ve znění zákona č. 424/2022 Sb.). Kde už dálkové odečty jsou, platí to od ledna 2024; nejpozději od ledna 2027 všude.</li>
      <li><strong>Do 25 dnů po skončení měsíce.</strong> Delší interval ani pozdější termín si dům odhlasovat nemůže; mimo topné období se nulové zprávy posílat nemusí.</li>
      <li><strong>Prokazatelně a chráněně.</strong> Nestačí vyvěsit tabulku na nástěnku ani poslat obyčejný e-mail bez dohody. Vyhovuje chráněný přístup na web nebo do aplikace s vlastním přihlášením každého obyvatele – a obyvatel musí být o nové informaci aktivně vyrozuměn. Údaje o spotřebě jsou podle Úřadu pro ochranu osobních údajů citlivé.</li>
      <li><strong>Sankce:</strong> za neposkytnutí informace pokuta podle zákona o službách (výklad MMR uvádí 50 Kč za každý den).</li>
    </ul>
    <p class="lpx-fineprint">Shrnutí podle <a href="<?= e($mmr) ?>" rel="noopener" target="_blank">výkladu Ministerstva pro místní rozvoj k povinnému poskytování informací o měření tepla</a> (načteno 26. 11. 2026). Rozúčtování nákladů upravuje vyhláška č. 269/2015 Sb., ve znění pozdějších předpisů. Nejde o právní radu; přesné znění a čísla ustanovení ověřte v e-Sbírce (<a href="https://www.e-sbirka.cz/" rel="noopener" target="_blank">e-sbirka.cz</a>) nebo u odečtové firmy. Studená voda pod tuto povinnost nespadá.</p>
  </div>
</section>

<section class="lpx-section lpx-meters" id="jak">
  <div class="wrap">
    <h2>Jak to web domu zařídí</h2>
    <ol class="lpx-auto">
      <li><strong>Data přijdou sama.</strong> Odečtová firma posílá stavy měřidel na tajnou adresu Vašeho webu (JSON nebo CSV) – nebo správce jednou měsíčně nahraje její soubor. Technický popis pro odečtovou firmu je v nápovědě webu.</li>
      <li><strong>Spotřeba se spočítá sama.</strong> Každý obyvatel po přihlášení vidí spotřebu své jednotky po měsících u každého měřidla – na počítači i v telefonu. Cizí jednotky nevidí.</li>
      <li><strong>Upozornění odejde samo.</strong> Když správce zapne automatické upozorňování, pátý den v měsíci dostanou obyvatelé e-mail (a kdo chce, upozornění do telefonu), že je informace za minulý měsíc na webu. Web si zaznamená, kdy a kolika lidem upozornění odešlo.</li>
    </ol>
    <p>Dálkové odečty a měsíční informace jsou v edici <strong>Standard</strong> za <?= money_czk($std) ?> ročně <small class="vat-tag">bez DPH</small> <?= price_vat_html($std) ?><?= price_size_note('standard') !== '' ? ' (' . e(price_size_note('standard')) . ')' : '' ?>. Edici Standard si můžete <?= PLAN_TRIAL_DAYS ?> dní zdarma vyzkoušet.</p>
    <p class="lpx-fineprint">Co musíte zařídit Vy: dálkově odečitatelná měřidla a smlouvu s odečtovou firmou (data o spotřebě dodává ona), a s obyvateli dohodu, že informace dostávají na webu – nejlépe usnesením nebo souhlasem při registraci. Web data přijme, ukáže a obyvatele upozorní.</p>
  </div>
</section>

<section class="lpx-section">
  <div class="wrap narrow">
    <h2>Časté otázky</h2>
    <details class="lp-faq"><summary>Stačí, když výbor pošle tabulku všem e-mailem?</summary><p>Podle výkladu MMR obyčejný e-mail bez předchozí písemné dohody nestačí a údaje o spotřebě jsou citlivé – tabulka s cizími byty do hromadného e-mailu nepatří. Web dá každému přístup jen k jeho jednotce.</p></details>
    <details class="lp-faq"><summary>Co když někdo nemá internet?</summary><p>Obyvatel si může písemně vyžádat informace v listinné podobě; náklady na tisk a doručení pak podle výkladu MMR hradí on. Ostatní dostávají informaci na webu.</p></details>
    <details class="lp-faq"><summary>Umíte načíst data od naší odečtové firmy?</summary><p>Web přijímá stavy měřidel ve formátu JSON nebo CSV na tajnou adresu, nebo ze souboru, který nahraje správce. Formát je popsaný v nápovědě; odečtové firmy obvykle umějí export do CSV. Pošlete nám ukázku jejich souboru a ověříme to s Vámi.</p></details>
    <details class="lp-faq"><summary>Kolik to stojí?</summary><p>Edice Standard <?= money_czk($std) ?> ročně bez DPH<?= false ? '' : '' ?>. Zkušební doba <?= PLAN_TRIAL_DAYS ?> dní je zdarma a nic se automaticky nestrhává. <a href="<?= url('/cenik') ?>">Celý ceník</a></p></details>
  </div>
</section>

<?php if ($open): ?>
<section class="lpx-cta"><div class="wrap"><h2>Splňte povinnost dřív, než začne platit všude.</h2><a class="btn btn-big lpx-cta-btn" href="<?= url('/registrace') ?>">Založit web zdarma</a></div></section>
<?php endif ?>
