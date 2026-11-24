<?php
$cta = fn(string $class = 'btn btn-big') => $open ? '<a class="' . $class . '" href="' . url('/registrace') . '">Založit web zdarma</a>' : '';
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
<section class="wrap lp-section lp-page-head">
  <h1>Co web umí</h1>
  <p class="lead">Všechno, co výbor potřebuje ke komunikaci se sousedy a ke správě domu, na jednom místě.</p>
</section>
<section class="wrap lp-section" id="funkce">
  <h2>Šest věcí, které tu máte pohromadě</h2>
  <ol class="lp-unique">
    <?php foreach ($unique as [$h, $p]): ?><li><h3><?= e($h) ?></h3><p><?= e($p) ?></p></li><?php endforeach ?>
  </ol>
</section>

<section class="lp-band" id="vzhledy"><div class="wrap lp-section">
  <h2>Pět šablon a pět barev, v Premium dvakrát tolik</h2>
  <p class="lead">Edice Standard má pět šablon a pět barev, edice Premium k nim přidá dalších pět šablon a pět barev. Zkuste si přepnout barvy; ve vlastním webu je změníte kdykoli jedním kliknutím, včetně loga. Edice Zdarma má jeden pevný vzhled.</p>
  <form class="lp-style-form">
    <?php $lookGroup = function (bool $premium) { ?>
      <div class="look-picker" role="radiogroup" aria-label="<?= $premium ? 'Barvy navíc v edici Premium' : 'Barvy v edici Standard' ?>">
        <?php foreach (LOOKS as $k => $label): if (in_array($k, PREMIUM_LOOKS, true) !== $premium) { continue; } ?><label class="look look-<?= $k ?>"><input type="radio" name="look" value="<?= $k ?>"><span class="look-swatch" aria-hidden="true"><i></i><i></i><i></i></span><span><?= e(explode(' – ', $label)[0]) ?></span></label><?php endforeach ?>
      </div>
    <?php }; ?>
    <h3 class="lp-sub">Edice Standard: 5 barev a 5 šablon</h3>
    <?php $lookGroup(false); ?>
    <div class="lp-looks look-cards">
      <?php foreach ($templates as $style => $look): $miniName = null; ?><figure><?php require __DIR__ . '/_mini.php'; ?><figcaption><strong><?= e(explode(' – ', STYLES[$style])[0]) ?></strong><span><?= e(explode(' – ', STYLES[$style])[1] ?? '') ?></span></figcaption></figure><?php endforeach ?>
    </div>
    <h3 class="lp-sub lp-sub-premium">Navíc v edici Premium: dalších 5 barev a 5 šablon</h3>
    <?php $lookGroup(true); ?>
    <div class="lp-looks look-cards">
      <?php foreach (['cedule' => 'pulnoc', 'panelak' => 'grafit', 'cinzak' => 'oliva', 'citelna' => 'nebe', 'vyveska' => 'malina'] as $style => $look): $miniName = null; ?><figure><?php require __DIR__ . '/_mini.php'; ?><figcaption><strong><?= e(explode(' – ', STYLES[$style])[0]) ?></strong><span><?= e(explode(' – ', STYLES[$style])[1] ?? '') ?></span></figcaption></figure><?php endforeach ?>
    </div>
  </form>
  <?php if ($demo): ?><div class="actions"><a class="btn btn-ghost" href="<?= e(tenant_url($demo, '/vstup')) ?>">Prohlédnout si hotový web v ukázce</a></div><?php endif ?>
</div></section>

<section class="wrap lp-section" id="vse">
  <h2>A všechno ostatní, co výbor potřebuje</h2>
  <div class="lp-groups">
    <?php foreach ($groups as $name => $items): ?><div><h3><?= e($name) ?></h3><ul class="lp-check"><?php foreach ($items as $it): ?><li><?= e($it) ?></li><?php endforeach ?></ul></div><?php endforeach ?>
  </div>
</section>

<section class="wrap lp-section narrow"><div class="actions lp-final"><?= $cta() ?><a class="btn btn-big btn-ghost" href="<?= url('/cenik') ?>">Ceník</a></div></section>
