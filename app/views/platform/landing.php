<?php
/* Úvodní stránka služby (varianta A „Produkt“): ukazuje skutečný web na snímcích; hlavní je úspora času při shromáždění. */
$cta = fn(string $class = 'btn btn-big') => $open ? '<a class="' . $class . '" href="' . url('/registrace') . '">Založit web zdarma</a>' : '';
$img = fn(string $f, string $alt, string $cls = '') => is_file(__DIR__ . '/../../../assets/landing/' . $f)
    ? '<img class="' . $cls . '" src="' . asset('landing/' . $f) . '" alt="' . e($alt) . '" loading="lazy" decoding="async">' : '';
$browser = fn(string $f, string $alt, string $addr = '') => '<div class="lpx-browser"><div class="lpx-bar" aria-hidden="true"><i></i><i></i><i></i>' . ($addr !== '' ? '<span>' . e($addr) . '</span>' : '') . '</div>' . $img($f, $alt) . '</div>';
$lim = fn(string $ed, string $k) => function_exists('pset_int') ? pset_int('lim_' . $ed . '_' . $k, 0, 10000000) : 0;
$num = fn(int $n, string $unit) => $n === 0 ? 'bez omezení' : number_format($n, 0, ',', "\u{a0}") . "\u{a0}" . $unit;
$editions = [
    'free' => ['Pro malý dům, který chce začít.', ['Veřejný web a část pro obyvatele', $num($lim('free', 'users'), 'účtů') . ', ' . $num($lim('free', 'mails'), 'e-mailů měsíčně'), 'Jeden pevný vzhled']],
    'standard' => ['Pro běžné společenství.', ['Prezence, hlasování a zápis ze shromáždění', 'Dálkové odečty a upozornění do telefonu', 'Revize, úkoly a dodavatelé', '5 šablon, 5 barev a vlastní logo']],
    'premium' => ['Pro velké domy a správu plateb.', ['AI asistent: odpovědi podle stanov a dokumentů domu', 'Platby na jednotku s upomínkami, AI zápis', 'Rezervace, jazykové verze, 10 šablon a 10 barev']],
];
$host = $demo ? (string) parse_url(tenant_url($demo, '/'), PHP_URL_HOST) : '';
?>
<section class="lpx-hero">
  <div class="wrap lpx-hero-grid">
    <div class="lpx-hero-text">
      <p class="lpx-eyebrow">Pro společenství vlastníků jednotek</p>
      <h1>Web pro Vaše společenství. Hotový za pět minut.</h1>
      <p class="lpx-lead">Veřejný web domu, část pro obyvatele a administrace pro výbor. Shromáždění s prezencí, hlasováním a zápisem, závady i dokumenty na jednom místě.</p>
      <div class="actions"><?= $cta() ?><?php if ($demo): ?><a class="btn btn-big btn-ghost" href="<?= e(tenant_url($demo, '/vstup')) ?>">Prohlédnout ukázku</a><?php endif ?></div>
      <?php if (!empty($_SESSION['ref']['name'])): ?><p class="lp-note lp-ref">Doporučilo Vás <strong><?= e($_SESSION['ref']['name']) ?></strong>.</p><?php endif ?>
      <?php if ($open): ?><p class="lp-note">Bez platební karty a bez časového omezení.</p>
      <?php else: ?><p class="lp-note">Nová společenství teď zakládáme po domluvě<?= pset('contact_email') !== '' ? ' – napište na ' . e(pset('contact_email')) : '' ?>.</p><?php endif ?>
    </div>
    <div class="lpx-devices" aria-label="Ukázka webu na počítači, tabletu a telefonu">
      <?= $browser('web-obyvatel.jpg', 'Úvodní stránka webu domu po přihlášení obyvatele', $host) ?>
      <div class="lpx-tablet"><?= $img('tablet.jpg', 'Prezence na shromáždění na tabletu: součet podílů a usnášeníschopnost') ?></div>
      <div class="lpx-phone"><?= $img('telefon.jpg', 'Web domu v telefonu') ?></div>
    </div>
  </div>
</section>

<section class="lpx-section lpx-meeting" id="shromazdeni">
  <div class="wrap">
    <p class="lpx-eyebrow">Nejvíc času ušetří</p>
    <h2>Shromáždění bez kalkulačky a bez přepisování.</h2>
    <p class="lpx-intro">Výbor na místě jen odklikává. Web sám sečte podíly, spočítá výsledky hlasování a připraví zápis – nic se nepočítá ručně a nic se nepřepisuje z papírů.</p>
    <ol class="lpx-steps">
      <li><div class="lpx-shot"><?= $img('prezence-soucet.jpg', 'Prezence: přítomno 74 % hlasů, shromáždění je usnášeníschopné') ?></div>
        <h3>Usnášeníschopnost hned</h3><p>U příchozích kliknete „osobně“ nebo „zmocněnec“ a web průběžně sčítá spoluvlastnické podíly. Plné moci vlastníci nahrají předem.</p></li>
      <li><div class="lpx-shot"><?= $img('hlasovani.jpg', 'Hlasování o návrhu: pro, proti a zdrželo se v procentech přítomných') ?></div>
        <h3>Hlasování na pár kliknutí</h3><p>Návrhy vzniknou z programu. Kdo je proti nebo se zdržel, označíte – ostatní přítomní hlasují pro. Výsledek podle podílů je hned vidět.</p></li>
      <li><div class="lpx-shot"><?= $img('zapis.jpg', 'Výsledky hlasování v zápisu ze shromáždění') ?></div>
        <h3>Zápis se napíše sám</h3><p>Účast i výsledky všech návrhů se propíšou do zápisu. Doplníte průběh jednání a uložíte jako PDF do dokumentů.</p></li>
    </ol>
  </div>
</section>

<section class="lpx-section lpx-meters" id="odecty">
  <div class="wrap">
    <div class="lpx-row">
      <div class="lpx-row-text">
        <p class="lpx-eyebrow lpx-law">Povinné od 1. 1. 2027</p>
        <h2>Dálkové odečty a měsíční informace o spotřebě – automaticky</h2>
        <p>Od 1. ledna 2027 musí jít měřiče tepla, teplé vody a indikátory topných nákladů odečíst na dálku, bez vstupu do bytu (zákon č. 406/2000 Sb., o hospodaření energií, § 7 odst. 11 a 12). Kde se odečítá na dálku, musí obyvatelé <strong>každý měsíc prokazatelně dostat informaci o své spotřebě</strong> (zákon č. 67/2013 Sb., § 8a).</p>
        <ol class="lpx-auto">
          <li><strong>Data přijdou sama.</strong> Odečtová firma posílá stavy měřidel na adresu Vašeho webu – nebo nahrajete její soubor.</li>
          <li><strong>Spotřeba se spočítá sama.</strong> Obyvatel vidí v telefonu spotřebu po měsících u každého měřidla.</li>
          <li><strong>Upozornění odejde samo.</strong> Každý měsíc dostanou obyvatelé e-mail a web si zaznamená, komu a kdy odešel.</li>
        </ol>
        <p class="lpx-fineprint">Shrnutí povinností, ne právní rada. Studená voda povinná není. Data o spotřebě dodává odečtová firma – web je přijme, ukáže a obyvatele upozorní.</p>
      </div>
      <div class="lpx-row-media lpx-meter-media">
        <?= $browser('odecty-sprava.jpg', 'Dálkové odečty v administraci: stav za minulý měsíc a nastavení', '') ?>
        <div class="lpx-phone"><?= $img('odecty-telefon.jpg', 'Spotřeba po měsících v telefonu obyvatele') ?></div>
      </div>
    </div>
  </div>
</section>

<section class="lpx-section">
  <div class="wrap">
    <div class="lpx-row">
      <div class="lpx-row-text"><p class="lpx-eyebrow">Pro obyvatele</p><h2>Všechno o domě na jednom místě</h2>
        <p>Každý vlastník a nájemník vidí to, co se týká jeho: aktuality, dokumenty, termíny, odečty své jednotky a hlášení závad.</p>
        <ul class="lp-check lpx-check"><li>Veřejná část pro návštěvníky, zbytek jen po přihlášení</li><li><strong>Upozornění přímo na displej telefonu</strong> – nová aktualita, zpráva od výboru, vyřízená závada nebo spotřeba za měsíc</li><li>Web na ploše telefonu jako aplikace, bez obchodu s aplikacemi (iPhone i Android)</li><li>Přihlášení na shromáždění a plná moc online</li></ul></div>
      <div class="lpx-row-media lpx-row-phone"><div class="lpx-phone lpx-phone-big"><?= $img('telefon.jpg', 'Web domu v telefonu obyvatele') ?></div></div>
    </div>
    <div class="lpx-row lpx-flip">
      <div class="lpx-row-text"><p class="lpx-eyebrow">Pro výbor</p><h2>Administrace bez školení</h2>
        <p>Průvodce nastavením, import jednotek z katastru a hromadné e-maily vlastníkům. Co není zřejmé na první pohled, má návod ověřený na skutečném webu.</p>
        <ul class="lp-check lpx-check"><li>Jednotky, vlastníci a podíly z katastru naráz</li><li>Odečty, revize, úkoly a dodavatelé</li><li>Záznam o tom, kdo co změnil</li></ul></div>
      <div class="lpx-row-media"><?= $browser('administrace.jpg', 'Přehled administrace pro výbor') ?></div>
    </div>
    <p class="lp-more"><a href="<?= url('/funkce') ?>">Všechny funkce a šablony vzhledu</a></p>
  </div>
</section>

<section class="lpx-section" id="cenik">
  <div class="wrap">
    <h2>Kolik to stojí</h2>
    <p class="lpx-intro">Ceny jsou bez DPH. Placené edice se platí ročně převodem a jejich funkce si můžete <?= PLAN_TRIAL_DAYS ?> dní zdarma vyzkoušet.</p>
    <div class="lpx-prices">
      <?php foreach (EDITIONS as $ek => $en): $price = edition_price($ek); [$who, $points] = $editions[$ek] ?? ['', []]; ?>
        <div class="lpx-price<?= $ek === 'standard' ? ' is-main' : '' ?>">
          <h3><?= e($en) ?></h3>
          <p class="lpx-amount"><?= $price > 0 ? money_czk($price) . ' <small>/ rok</small> <b class="vat-tag">bez DPH</b>' : '0 Kč <small>navždy</small>' ?></p>
          <p class="muted"><?= e($who) ?></p>
          <ul class="lp-check"><?php foreach ($points as $pt): ?><li><?= e($pt) ?></li><?php endforeach ?></ul>
          <?php if ($open): ?><a class="btn<?= $ek === 'standard' ? '' : ' btn-ghost' ?>" href="<?= url('/registrace') ?>"><?= $ek === 'free' ? 'Založit zdarma' : 'Vyzkoušet ' . PLAN_TRIAL_DAYS . ' dní' ?></a><?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
    <?php if (vat_payer()): ?><p class="vat-note"><?= e(vat_note()) ?></p><?php endif ?>
    <p class="lp-more"><a href="<?= url('/cenik') ?>">Podrobné porovnání edic, limity a doplňky</a></p>
  </div>
</section>

<section class="lpx-section" id="otazky">
  <div class="wrap lpx-faq">
    <div><h2>Časté otázky</h2><p class="lpx-intro">Další odpovědi najdete <a href="<?= url('/cenik') ?>">u ceníku</a>.<?= pset('contact_email') !== '' ? ' Nebo napište na <a href="mailto:' . e(pset('contact_email')) . '">' . e(pset('contact_email')) . '</a>.' : '' ?></p></div>
    <div>
      <details class="lp-faq"><summary>Je web opravdu zdarma?</summary><p>Ano. Edice Zdarma nemá časové omezení a nechceme po Vás platební kartu. Má limity (počet účtů, hromadných e-mailů a místa na dokumenty) a jeden pevný vzhled. Když limit vyčerpáte, web běží dál.</p></details>
      <details class="lp-faq"><summary>Kdo uvidí data našeho společenství?</summary><p>Každé společenství má vlastní oddělenou databázi, soubory i přihlašování. Co je veřejné a co jen pro přihlášené obyvatele, určuje Váš výbor.</p></details>
      <details class="lp-faq"><summary>Můžeme web přestat používat a data si odnést?</summary><p>Ano. V placené edici si správce stáhne úplnou zálohu webu včetně dokumentů; v edici Zdarma si zálohu může jednou na 30 dní zdarma zapnout. Každý uživatel si své údaje stáhne kdykoli a web lze v administraci zrušit.</p></details>
      <details class="lp-faq"><summary>Máme starý web. Půjde obsah převést?</summary><p>Ano. Dokumenty a aktuality přenesete sami, nebo si převod objednáte. Soubory se stáhnou na nový web, nezůstanou jen odkazy na ten starý.</p></details>
    </div>
  </div>
</section>

<?php if ($open): ?>
<section class="lpx-cta">
  <div class="wrap"><h2>Založte web svého domu ještě dnes.</h2><?= $cta('btn btn-big lpx-cta-btn') ?></div>
</section>
<?php endif ?>
