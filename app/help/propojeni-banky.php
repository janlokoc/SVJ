<p>Má-li společenství účet u Fio banky, stačí token Fio API (viz <a href="<?= url('/admin/napoveda/parovani-plateb') ?>">Párování plateb s bankou</a>). U ostatních bank – Česká spořitelna, Komerční banka, ČSOB, Raiffeisenbank, Moneta, UniCredit, Air Bank, mBank a dalších – web stahuje příchozí platby přes <strong>licencovaného poskytovatele bankovních dat</strong> (GoCardless Bank Account Data). Přístup je jen pro čtení: web s ním nemůže nic zaplatit.</p>

<h2>1. Připojte banku</h2>
<ol>
  <li>V administraci otevřete <strong>Platby na jednotku</strong> a v části <strong>Párování plateb s bankou</strong> klikněte na <strong>Propojit účet u ČS, KB, ČSOB a dalších bank</strong>.</li>
  <li>Vyberte banku ze seznamu a klikněte na <strong>Pokračovat do banky</strong>.</li>
  <li>Otevře se přihlášení do internetového bankovnictví. Přihlaste se jako osoba s přístupem k účtu společenství, vyberte účet a potvrďte souhlas se sdílením pohybů.</li>
  <li>Banka Vás vrátí zpět na web. Web načte účet a hned stáhne příchozí platby za poslední dny (podle banky až 90 dní) a spáruje je s jednotkami podle variabilního symbolu.</li>
</ol>
<p class="callout">Obrazovky přihlášení a souhlasu jsou na webu banky a poskytovatele dat; vypadají u každé banky jinak a ověřit jsme je nemohli.</p>

<h2>2. Každý den samo</h2>
<p>Dál se platby stahují samy jednou denně. Tlačítkem <strong>Stáhnout teď</strong> je můžete stáhnout i hned – banky to ale dovolují nejvýš <strong>4× denně</strong> na účet (včetně automatického stažení).</p>

<h2>3. Obnovení souhlasu po 90 dnech</h2>
<p>Banky dovolují souhlas udělit nejvýš na <strong>90 dní</strong>. 14 a 3 dny před koncem pošle web správcům e-mail. Obnovíte ho tak, že banku připojíte znovu (krok 1) a staré propojení zrušíte. Platby se nezapočítají dvakrát.</p>

<h2>Dobré vědět</h2>
<ul>
  <li><strong>Variabilní symbol</strong> předávají banky přes toto rozhraní různě. Platbu, kterou web nespáruje, přiřadíte ručně v tabulce Poslední příchozí platby.</li>
  <li><strong>Zrušení propojení</strong> ukončí přístup k účtu; už stažené platby na webu zůstanou.</li>
  <li>Nahrávání výpisu (GPC/CSV) a Fio API fungují dál vedle tohoto propojení.</li>
</ul>
