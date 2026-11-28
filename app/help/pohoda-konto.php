<p>Když společenství (nebo jeho správce) vede účetnictví v programu <strong>POHODA</strong>, můžete vlastníkům ukázat jejich konto přímo na webu: měsíční předpis záloh po položkách (fond oprav, teplo, voda…), pohledávky se splatností, kolik je uhrazeno a kolik zbývá, a přijaté platby. Každý vidí jen své jednotky.</p>

<h2>Co je potřeba v POHODĚ</h2>
<ul>
  <li>Předpisy záloh vedené v agendě <strong>Ostatní pohledávky</strong> – jedna pohledávka na jednotku a měsíc, s <strong>variabilním symbolem jednotky</strong> a s položkami (fond oprav, teplo, studená voda…). Stav úhrady POHODA doplní sama při likvidaci z banky.</li>
  <li>Platby od vlastníků v agendě <strong>Banka</strong> (příjmy se stejným variabilním symbolem).</li>
  <li>XML export těchto dokladů. V POHODĚ se dělá přes datovou komunikaci (XML export); vyberte doklady agendy Ostatní pohledávky a Banka za období, které chcete na webu ukázat (stačí posledních 12 měsíců).</li>
</ul>
<p class="hint">Postup a názvy položek v samotné POHODĚ jsme <strong>neověřovali</strong> – POHODA je cizí program a ověřovací běh ji nemá. Formát souboru odpovídá dokumentaci Stormware (<code>api.stormware.cz/pohoda</code>), ověřený je jen na vzorovém souboru podle ní, ne na skutečném exportu ostatních pohledávek. Kdyby web Váš soubor nepřečetl, pošlete nám ho (bez osobních údajů) a import doladíme.</p>

<h2>Na webu: variabilní symboly jednotek</h2>
<p>Doklady se k jednotkám přiřazují podle <strong>variabilního symbolu</strong>. V administraci <strong>Platby na jednotku</strong> proto nejdřív nahrajte (nebo doplňte) u každé jednotky její VS – v části <em>Nahrát z účetnictví</em> stačí řádky <code>jednotka ; záloha ; stav ; VS</code>. Úvodní nuly nevadí (<code>004812</code> = <code>4812</code>).</p>

<h2>Na webu: nahrání exportu</h2>
<ol>
  <li>V administraci <strong>Platby na jednotku</strong> v části <em>Konto bytu z účetnictví POHODA</em> vyberte XML soubor a klikněte na <em>Zkontrolovat soubor</em>.</li>
  <li>Web ukáže, kolik našel pohledávek a plateb, k kolika jednotkám je přiřadí a které variabilní symboly nezná (takové doklady se uloží bez jednotky – doplňte VS a nahrajte soubor znovu). Jiné doklady (vydané faktury, výdaje z banky) přeskočí.</li>
  <li>Klikněte na <em>Uložit doklady</em>. U přiřazených jednotek se zároveň přepočte měsíční záloha a stav účtu v tabulce, takže dál fungují upomínky a QR platby.</li>
</ol>
<?= help_fig('pohoda-1-nahled.jpg', 'Náhled importu: počty dokladů a jednotek, neznámý variabilní symbol.') ?>
<p>Export můžete nahrávat opakovaně (třeba jednou měsíčně) – stejné doklady se přepíšou, takže se projeví mezitím přijaté úhrady, nic se nezdvojí.</p>

<h2>Co uvidí vlastník</h2>
<p>V části <strong>Platby a zálohy</strong> u svého bytu: předpis záloh po položkách, pohledávky za posledních 12 měsíců (a starší neuhrazené) se splatností, uhrazenou a zbývající částkou – po splatnosti zvýrazněné – a přijaté platby.</p>
<?= help_fig('pohoda-2-vlastnik.jpg', 'Konto bytu u vlastníka: předpis po položkách, pohledávky a platby.') ?>
