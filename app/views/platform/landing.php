<?php
$first = money_czk(pset_int('price_first', 0, 1000000)); $next = money_czk(pset_int('price_next', 0, 1000000)); $per = period_label(); $trial = pset_int('trial_days', 0, 365);
$cta = fn(string $class = 'btn btn-big') => $open ? '<a class="' . $class . '" href="' . url('/registrace') . '">Vyzkoušet ' . ($trial ? $trial . ' dní zdarma ' : '') . icon('arrow') . '</a>' : '';
$ai = ai_configured();
// Čím se web liší: věci, které výbor jinde skládá z několika nástrojů.
$unique = [
    ['Skutečný web domu, ne jen přihlašovací portál', 'Veřejná část s aktualitami, dokumenty a kontakty, kterou najde i vyhledávač. Pět šablon, pět barevných variant, vlastní logo a na přání vlastní doména.'],
    ['Shromáždění bez počítání na papíře', 'Vlastníci potvrdí účast na webu a Vy předem vidíte, zda bude shromáždění usnášeníschopné. Plná moc a prezenční listina k tisku, po schůzi se zápis sestaví sám z účasti a hlasování.'],
    ['E-maily, u kterých víte, kdo je četl', 'Pozvánku rozešlete jedním kliknutím a u každého vlastníka vidíte, zda ji otevřel a zda se proklikl na web.'],
    ['Platby s QR kódem', 'Výbor nahraje z účetnictví zálohy a stav účtu. Každý vlastník vidí jen svou jednotku a nedoplatek uhradí načtením QR kódu; dlužníkům pošlete upomínku jedním tlačítkem.'],
    ['Pět jazyků pro sousedy', 'Čeština, slovenština, angličtina, ukrajinština a vietnamština. Každý obyvatel si jazyk přepne sám.'],
    $ai ? ['AI pomocník v ceně', 'Z pár poznámek napíše oznámení nebo e-mail vlastníkům, uhladí text a navrhne odpověď na nahlášenou závadu. Text vždy schvaluje výbor.']
        : ['Vývěska u vchodu', 'Veřejná stránka s oznámeními, termíny a havarijními čísly. Cedulku s QR kódem vytisknete ke vchodu, nebo stránku pustíte na obrazovce v hale.'],
];
$groups = [
    'Komunikace' => ['Aktuality a dokumenty – veřejné, pro přihlášené, nebo jen pro jednu jednotku', 'Hlasování per rollam podle spoluvlastnických podílů', 'Ankety pro rychlé zjištění názoru', 'Hlášení závad se stavem a přiřazeným dodavatelem', 'Diskuze sousedů', 'Termíny do kalendáře v telefonu'],
    'Dům a lidé' => ['Jednotky, vlastníci a podíly s importem z katastru', 'Pozvánky pro členy domácnosti a nájemníky', 'Evidence klíčů a čipů', 'Samoodečty měřidel', 'Rezervace společných prostor', 'Základní dokumenty: stanovy, domovní řád a další na jednom místě'],
    'Práce výboru' => ['Úkoly a usnesení – kdo co zařídí a do kdy', 'Kalendář revizí s upozorněním předem', 'Evidence dodavatelů', 'Zápis ze shromáždění jedním kliknutím', 'Veřejná vývěska s QR kódem ke vchodu', 'Převod obsahu ze stávajícího webu'],
    'Bezpečnost' => ['Oddělená databáze a soubory každého společenství', 'Dvoufázové přihlášení', 'Záznamy o práci správců, které nejdou smazat', 'Úplná záloha ke stažení kdykoli', 'Web jde na telefonu přidat na plochu', 'Tlačítko Nahlásit na každé stránce'],
];
$templates = ['klasik' => 'les', 'modern' => 'more', 'vecna' => 'grafit', 'noviny' => 'cihla', 'vlidna' => 'svestka'];
?>
<section class="hero lp-hero">
  <div class="wrap hero-grid">
    <div class="hero-text">
      <p class="eyebrow">Pro výbory a správce SVJ</p>
      <h1>Web Vašeho SVJ <span class="h1-sub">za pět minut</span></h1>
      <p class="lead">Vlastní web domu s částí pro obyvatele a administrací pro výbor. Vyberete šablonu a barvy, zvolíte adresu, vytvoříte si účet – a web běží.</p>
      <ul class="lp-points"><li>25 kombinací vzhledu</li><li>shromáždění, hlasování, platby</li><li>5 jazyků</li><?= $ai ? '<li>AI pomocník v ceně</li>' : '' ?></ul>
      <div class="actions"><?= $cta() ?><?php if ($demo): ?><a class="btn btn-big btn-ghost" href="<?= e(tenant_url($demo)) ?>">Prohlédnout ukázkový web</a><?php else: ?><a class="btn btn-big btn-ghost" href="#cenik">Ceník</a><?php endif ?></div>
      <?php if ($open): ?><p class="lp-note"><?= $trial ? 'Prvních ' . $trial . ' dní zdarma, bez platební karty. ' : '' ?>Potom <?= $first ?> za <?= $per ?>.</p>
      <?php else: ?><p class="lp-note">Nová společenství teď zakládáme po domluvě<?= pset('contact_email') !== '' ? ' – napište na ' . e(pset('contact_email')) : '' ?>.</p><?php endif ?>
    </div>
    <div class="hero-side lp-stack">
      <?php $look = 'les'; $miniName = 'Zahradní 12'; require __DIR__ . '/_mini.php'; $look = 'more'; $miniName = 'Na Vyhlídce 8'; require __DIR__ . '/_mini.php'; ?>
    </div>
  </div>
</section>

<section class="wrap lp-section" id="funkce">
  <p class="eyebrow">Na čem si zakládáme</p>
  <h2>Šest věcí, které tu máte pohromadě</h2>
  <ol class="lp-unique">
    <?php foreach ($unique as [$h, $p]): ?><li><h3><?= e($h) ?></h3><p><?= e($p) ?></p></li><?php endforeach ?>
  </ol>
</section>

<section class="lp-band" id="vzhledy"><div class="wrap lp-section">
  <p class="eyebrow">Šablony a barvy</p>
  <h2>Pět šablon, každá v pěti barvách</h2>
  <p class="lead">Klasická, moderní, věcná, novinová nebo vlídná. Zkuste si přepnout barvy – a ve vlastním webu je změníte kdykoli jedním kliknutím, včetně loga.</p>
  <form class="lp-style-form">
    <div class="look-picker" role="radiogroup" aria-label="Barvy náhledů">
      <?php foreach (LOOKS as $k => $label): ?><label class="look look-<?= $k ?>"><input type="radio" name="look" value="<?= $k ?>"><span class="look-swatch" aria-hidden="true"><i></i><i></i><i></i></span><span><?= e(explode(' – ', $label)[0]) ?></span></label><?php endforeach ?>
    </div>
    <div class="lp-looks look-cards">
      <?php foreach ($templates as $style => $look): $miniName = null; ?><figure><?php require __DIR__ . '/_mini.php'; ?><figcaption><strong><?= e(explode(' – ', STYLES[$style])[0]) ?></strong><span><?= e(explode(' – ', STYLES[$style])[1] ?? '') ?></span></figcaption></figure><?php endforeach ?>
    </div>
  </form>
  <?php if ($demo): ?><div class="actions"><a class="btn btn-ghost" href="<?= e(tenant_url($demo)) ?>">Prohlédnout si hotový web v ukázce <?= icon('arrow') ?></a></div><?php endif ?>
</div></section>

<section class="wrap lp-section" id="vse">
  <h2>A všechno ostatní, co výbor potřebuje</h2>
  <div class="lp-groups">
    <?php foreach ($groups as $name => $items): ?><div><h3><?= e($name) ?></h3><ul class="lp-check"><?php foreach ($items as $it): ?><li><?= e($it) ?></li><?php endforeach ?></ul></div><?php endforeach ?>
  </div>
</section>

<section class="lp-band lp-band-soft"><div class="wrap lp-section">
  <h2>Jak začít</h2>
  <ol class="lp-steps">
    <li><strong>Zvolte název a adresu</strong><span>Například <code><?= e(tenant_address('zahradni')) ?></code>.</span></li>
    <li><strong>Vyberte šablonu a barvy</strong><span>25 kombinací; kdykoli je změníte a nahrajete vlastní logo.</span></li>
    <li><strong>Vytvořte si účet správce</strong><span>Hned potom jste v administraci a můžete zvát vlastníky.</span></li>
  </ol>
  <div class="actions"><?= $cta() ?><?php if ($demo): ?><a class="btn btn-big btn-ghost" href="<?= e(tenant_url($demo)) ?>">Nejdřív si prohlédnout ukázku</a><?php endif ?></div>
</div></section>

<section class="lp-band" id="cenik"><div class="wrap lp-section">
  <h2>Ceník</h2>
  <div class="lp-pricing">
    <div class="card price-card">
      <p class="eyebrow">Provoz webu</p>
      <ul class="price-rows">
        <?php if ($trial): ?><li><span>Prvních <?= $trial ?> dní</span><strong>zdarma</strong></li><?php endif ?>
        <li><span>První rok</span><strong><?= $first ?> <small>/ <?= $per ?></small></strong></li>
        <li><span>Další roky</span><strong><?= $next ?> <small>/ <?= $per ?></small></strong></li>
      </ul>
      <p class="hint">Jedna cena za celé společenství, bez omezení počtu jednotek a účtů. Platí se převodem na zálohovou fakturu s QR kódem; po úhradě dostanete fakturu.</p>
      <div class="actions"><?= $cta('btn') ?></div>
    </div>
    <div>
      <h3>V ceně</h3>
      <ul class="lp-check lp-included">
        <li>Web domu, část pro obyvatele a administrace bez omezení počtu jednotek a účtů</li>
        <li>Všechny šablony, barvy, jazyky a funkce z přehledu výše</li>
        <li>Zálohy, aktualizace a podpora e-mailem</li>
        <?php if ($ai): ?><li>AI pomocník pro výbor: <?= pset_int('ai_free_limit', 0, 100000) ?> použití měsíčně</li><?php endif ?>
      </ul>
    </div>
  </div>

  <h3 class="lp-sub">Doplňky a služby</h3>
  <table class="table price-table"><tbody>
    <tr><td><strong>Vlastní doména</strong><br><small class="muted">Web na adrese jako tabor48.cz; doménu zaregistrujeme, nebo připojíme Vaši.</small></td><td class="t-actions"><strong><?= money_czk(pset_int('price_domain', 0, 1000000)) ?> / rok</strong></td></tr>
    <tr><td><strong>AI Plus</strong><?= $ai ? '' : ' <span class="tag">již brzy</span>' ?><br><small class="muted">AI pomocník píše oznámení, e-maily, odpovědi na závady i zápis ze shromáždění z Vašich poznámek nebo nahraného přepisu. <?= pset_int('ai_plus_limit', 0, 100000) ?> použití měsíčně<?= $ai ? ' místo ' . pset_int('ai_free_limit', 0, 100000) . ' v ceně' : '' ?>.</small></td><td class="t-actions"><strong><?= money_czk(pset_int('price_ai', 0, 1000000)) ?> / rok</strong></td></tr>
    <?php foreach (preg_split('/\R/', pset('pricelist')) as $line): if (trim($line) === '' || str_contains($line, 'v ceně') || str_contains(mb_strtolower($line), 'vlastní doména')) { continue; } [$item, $price] = array_map('trim', explode('|', $line . '|')); ?>
      <tr><td><?= e($item) ?></td><td class="t-actions"><strong><?= e($price) ?></strong></td></tr>
    <?php endforeach ?>
  </tbody></table>

  <h3 class="lp-sub" id="pripravujeme">Připravujeme</h3>
  <p class="muted">Tyto funkce ještě nejsou hotové. Klikněte na „Mám zájem“ u těch, které byste využili – podle zájmu rozhodujeme, co udělat dřív. K ničemu se tím nezavazujete.</p>
  <ul class="planned">
    <?php foreach (PLANNED_FEATURES as $key => [$name, $desc, $price]): ?>
    <li><div><strong><?= e($name) ?></strong> <span class="tag">již brzy</span><?= $price !== '' ? ' <small class="muted">· ' . e($price) . '</small>' : '' ?><p><?= e($desc) ?></p></div>
      <form method="post" action="<?= url('/zajem') ?>" class="planned-form"><?= csrf_field() ?><input type="hidden" name="feature" value="<?= e($key) ?>">
        <span class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></span>
        <input type="email" name="email" placeholder="e-mail (nepovinné)" aria-label="E-mail pro zprávu, až bude funkce <?= e($name) ?> hotová"><button class="btn btn-small btn-ghost" type="submit">Mám zájem</button></form></li>
    <?php endforeach ?>
  </ul>
</div></section>

<?php $op = operator(); if ($op['name'] !== ''): ?>
<section class="wrap lp-section" id="provozovatel">
  <h2>Kdo službu provozuje</h2>
  <div class="lp-operator">
    <p><strong><?= e($op['name']) ?></strong><br><?= e($op['address']) ?><?= $op['ico'] !== '' ? '<br>IČO ' . e($op['ico']) : '' ?><?= $op['register'] !== '' ? '<br><small class="muted">' . e($op['register']) . '</small>' : '' ?></p>
    <p><?= $op['email'] !== '' ? '<a href="mailto:' . e($op['email']) . '">' . e($op['email']) . '</a><br>' : '' ?><?= $op['phone'] !== '' ? e($op['phone']) . '<br>' : '' ?>
      <a href="<?= url('/podminky') ?>">Obchodní podmínky</a> · <a href="<?= url('/zpracovani-udaju') ?>">Zpracování osobních údajů</a></p>
  </div>
</section>
<?php endif ?>

<section class="wrap lp-section narrow" id="otazky">
  <h2>Časté otázky</h2>
  <details class="lp-faq"><summary>Co se stane po zkušební době?</summary><p>V administraci najdete platební údaje s QR kódem a před koncem zkušební doby Vám přijde připomenutí e-mailem. Když se rozhodnete nepokračovat, nic neplatíte – web se po <?= pset_int('grace_days', 0, 365) ?> dnech pozastaví.</p></details>
  <details class="lp-faq"><summary>Jak se platí?</summary><p>Bankovním převodem. V administraci najdete zálohovou fakturu s QR kódem; po připsání platby vystavíme fakturu. Platba kartou zatím není k dispozici.</p></details>
  <details class="lp-faq"><summary>Kdo uvidí data našeho společenství?</summary><p>Každé společenství má vlastní oddělenou databázi, soubory i přihlašování. Co je veřejné a co jen pro přihlášené obyvatele, určuje Váš výbor.</p></details>
  <?php if (pset('hosting_note') !== ''): ?><details class="lp-faq"><summary>Kde jsou data uložena?</summary><p><?= e(pset('hosting_note')) ?></p></details><?php endif ?>
  <details class="lp-faq"><summary>Můžeme web přestat používat a data si odnést?</summary><p>Ano. Správce si kdykoli stáhne úplnou zálohu webu včetně dokumentů – i po skončení předplatného.</p></details>
  <details class="lp-faq"><summary>Můžeme mít vlastní doménu?</summary><p>Ano, web může běžet i na Vaší doméně. Nastavení je v ceníku jako samostatná položka.</p></details>
  <div class="actions lp-final"><?= $cta() ?></div>
</section>
