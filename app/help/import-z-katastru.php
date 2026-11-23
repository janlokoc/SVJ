<p>Jednotky, jejich vlastníky a spoluvlastnické podíly nemusíte opisovat. Web je načte z textu, který zkopírujete z veřejné služby <strong>Nahlížení do katastru nemovitostí</strong>. Je zdarma a nepotřebujete k ní žádný účet.</p>

<h2>1. Najděte dům v katastru</h2>
<ol>
  <li>Otevřete <a href="https://nahlizenidokn.cuzk.gov.cz/" target="_blank" rel="noopener noreferrer">nahlizenidokn.cuzk.gov.cz</a>.</li>
  <li>Vyhledejte <strong>stavbu</strong> podle adresy nebo čísla popisného (ne parcelu ani jednotlivý byt).</li>
  <li>Otevře se stránka stavby. Dole na ní musí být oddíl <em>Vlastníci, jiní oprávnění</em> se seznamem vlastníků a řádky „Jednotka: …“.</li>
  <li>Označte celou stránku (<kbd>Ctrl</kbd>+<kbd>A</kbd>, na Macu <kbd>⌘</kbd>+<kbd>A</kbd>) a zkopírujte ji (<kbd>Ctrl</kbd>+<kbd>C</kbd>).</li>
</ol>
<p class="callout">Tuto část jsme nemohli ověřit automaticky – web katastru strojové procházení blokuje. Když bude stránka katastru vypadat jinak, než píšeme, dejte nám prosím vědět přes Hlášení.</p>

<h2>2. Vložte text do webu</h2>
<p>V administraci otevřete <strong>Jednotky → Import z katastru</strong>, vložte zkopírovaný text (<kbd>Ctrl</kbd>+<kbd>V</kbd>) a klikněte na <strong>Zobrazit náhled</strong>. Nic se zatím neukládá.</p>
<?= help_fig('katastr-1-vlozit.jpg', 'Import z katastru: sem vložíte celou zkopírovanou stránku stavby.') ?>

<h2>3. Zkontrolujte náhled</h2>
<p>Web ukáže, kolik jednotek našel, a sečte podíly. Zkontrolujte hlavně:</p>
<ul>
  <li><strong>Součet podílů 100 %.</strong> Pokud nevychází, nezkopírovali jste celou stránku – vraťte se ke kroku 1.</li>
  <li><strong>Upozornění pod součtem.</strong> Když má jeden vlastník v katastru jeden podíl pro více jednotek, web ho rozdělí rovným dílem a upozorní na to. Skutečné rozdělení ověřte v prohlášení vlastníka.</li>
  <li><strong>Sloupec Stav:</strong> „nová“ jednotka se založí, u „změna“ se přepíše vlastník a podíl.</li>
</ul>
<?= help_fig('katastr-2-nahled.jpg', 'Náhled: 6 jednotek, součet podílů 100 % – sedí.') ?>

<h2>4. Uložte</h2>
<p>Klikněte na <strong>Uložit … jednotek</strong>. U jednotek, které už na webu jsou, se přepíše jen vlastník a podíl; patro, poznámka a přiřazené účty obyvatel zůstanou.</p>
<?= help_fig('katastr-3-hotovo.jpg', 'Hotovo: jednotky jsou založené i s vlastníky a podíly.') ?>

<h2>Dobré vědět</h2>
<ul>
  <li><strong>Import můžete kdykoli zopakovat</strong>, třeba po prodeji bytu. Změní se jen to, co se v katastru změnilo.</li>
  <li><strong>Dům s více čísly popisnými:</strong> vchody se pojmenují podle čísel popisných a jednotky stejně jako v katastru (např. 528/4).</li>
  <li><strong>Adresy vlastníků</strong> z katastru se uloží k jednotce pro seznam členů a doručování. Vidí je jen správci webu.</li>
  <li><strong>Účty obyvatel</strong> import nezakládá. Lidé se přidají sami přes odkaz pro přiřazení k jednotce, nebo jim účet založíte v Uživatelích.</li>
</ul>
