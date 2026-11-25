<p>Web umí sám zjistit, kdo zálohy zaplatil: příchozí platby z výpisu účtu společenství přiřadí podle <strong>variabilního symbolu</strong> k jednotkám a připočte je ke stavu z účetnictví. Vlastníci pak vidí aktuální stav a upomínky dostanou jen ti, kdo opravdu dluží.</p>

<h2>1. Stav z účetnictví a variabilní symboly</h2>
<p>V administraci otevřete <strong>Platby na jednotku</strong> a nahrajte stav účtů jednotek z účetnictví (jednotka ; měsíční záloha ; stav účtu ; variabilní symbol). Důležitý je <strong>variabilní symbol</strong> – podle něj se platby z banky přiřazují – a <strong>den, ke kterému stav platí</strong>.</p>
<p class="callout">Z banky se připočítají jen platby, které přišly <strong>po</strong> tomto dni. Ty starší už má v sobě stav z účetnictví, takže se nic nezapočítá dvakrát. Když příště nahrajete nový stav z účetnictví, počítá se zase od nového dne.</p>

<h2>2. Nahrajte výpis z banky</h2>
<ol>
  <li>V internetovém bankovnictví si stáhněte výpis nebo pohyby na účtu společenství ve formátu <strong>GPC (ABO)</strong>, případně <strong>CSV</strong>. GPC nabízí většina českých bank; CSV musí mít sloupce Datum, Částka (nebo Objem) a VS.</li>
  <li>Na stránce Platby na jednotku v části <strong>Párování plateb s bankou</strong> vyberte soubor a klikněte na <strong>Načíst výpis</strong>.</li>
</ol>
<p class="callout">Kde přesně se výpis v bankovnictví stahuje, se u každé banky liší a ověřit jsme to nemohli – hledejte volbu „Export“ nebo „Stáhnout výpis“ a formát GPC/ABO.</p>
<?= help_fig('banka-1-nacteno.jpg', 'Po načtení: platba s VS jednotky se spárovala, platba s neznámým VS čeká na ruční přiřazení.') ?>
<p>Výpis můžete nahrát i opakovaně nebo se překrývajícím obdobím – platby, které už web má, přeskočí.</p>

<h2>3. Přiřaďte nespárované platby</h2>
<p>Platba bez variabilního symbolu nebo s neznámým symbolem je v tabulce <strong>Poslední příchozí platby</strong> podbarvená. Vyberte u ní jednotku a klikněte na <strong>Uložit</strong>. Stav jednotky se hned přepočítá.</p>
<?= help_fig('banka-2-prirazeno.jpg', 'Ručně přiřazená platba se připočetla ke stavu jednotky.') ?>

<h2>Automaticky z Fio banky</h2>
<p>Má-li společenství účet u Fio banky, nemusíte výpisy nahrávat: v internetovém bankovnictví Fio si vytvořte <strong>token pro API s oprávněním jen pro čtení</strong> (web s ním nemůže nic zaplatit; služba je zdarma) a vložte ho do pole <strong>Token Fio API</strong>. Web pak pohyby stahuje sám jednou denně a hned po uložení tokenu zkusí první stažení.</p>
<p class="callout">Postup v bankovnictví Fio jsme nemohli ověřit – je na webu banky. Ověřené je, že web pohyby ve formátu Fio API načte a spáruje.</p>

<h2>Dobré vědět</h2>
<ul>
  <li><strong>Odchozí platby</strong> (poplatky, faktury dodavatelům) web ignoruje – páruje jen příchozí.</li>
  <li><strong>Variabilní symbol s úvodními nulami</strong> („0004801“) se spáruje stejně jako „4801“.</li>
  <li><strong>Vlastník</strong> v části Platby a zálohy vidí platby přijaté po dni stavu z účetnictví a stav včetně nich.</li>
</ul>
