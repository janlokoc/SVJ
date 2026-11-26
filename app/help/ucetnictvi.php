<p>Web si s účetním programem vyměňuje údaje přes soubory: <strong>do účetnictví</strong> pošle adresář vlastníků, předpisy záloh a platby z banky, <strong>z účetnictví</strong> (nebo z programu správy domů) načte předpisy, stavy účtů jednotek a seznam jednotek s podíly a vlastníky. Najdete to v administraci v části <strong>Účetnictví – export a import</strong>.</p>

<h2>1. Export do účetnictví</h2>
<ol>
  <li>Vyberte program: <strong>POHODA</strong>, <strong>Money S3</strong>, <strong>ABRA Flexi</strong> nebo <strong>CSV</strong> (pro Excel a ostatní programy).</li>
  <li>Zaškrtněte, co chcete předat: adresář vlastníků, předpisy záloh, platby z banky. Předpisy se tvoří z měsíčních záloh v části Platby na jednotku – za každou jednotku a měsíc jeden doklad s variabilním symbolem jednotky.</li>
  <li>Zvolte první měsíc předpisů, počet měsíců a období plateb z banky a klikněte na <strong>Stáhnout export</strong>.</li>
</ol>
<p class="callout">Předpisy jsou v POHODĚ a Money <strong>ostatní pohledávky</strong>, ne faktury: společenství vlastníkům zálohy nefakturuje, ale u každého vlastníka potřebuje vidět, co dluží, a úhrady z banky párovat podle variabilního symbolu.</p>
<p>V části <strong>Nastavení exportu</strong> doplňte zkratku bankovního účtu, pod kterou má účet společenství Váš účetní program, a den splatnosti předpisů. Do Nastavení webu patří <strong>IČ společenství</strong> – POHODA i Money podle něj poznají, do které účetní jednotky soubor patří.</p>

<h2>2. Načtení v účetním programu</h2>
<ul>
  <li><strong>POHODA:</strong> Soubor → Datová komunikace → XML import/export.</li>
  <li><strong>Money S3:</strong> v agendě (Adresář, Ostatní pohledávky, Banka) volba XML přenosy → Import z XML.</li>
  <li><strong>ABRA Flexi:</strong> Nástroje → Import → Import z XML. Kódy typů dokladů (ostatní pohledávka, bankovní doklad) nastavte v Nastavení exportu podle číselníku Typy dokladů ve Flexi.</li>
</ul>
<p class="callout">Soubory odpovídají zveřejněným popisům formátů (POHODA – XML schémata verze 2 od Stormware, Money S3 – XML schémata od výrobce, ABRA Flexi – formát winstrom). <strong>Import do skutečné POHODY, Money ani ABRA Flexi jsme neověřili</strong> a postup v těchto programech je převzatý z jejich návodů. Proto první soubor zkuste naimportovat do zkušební účetní jednotky, nebo ho předejte účetní ke kontrole.</p>

<h2>3. Import z účetnictví nebo programu správy domů</h2>
<ol>
  <li>V účetním programu (nebo v programu správy domů) si vyexportujte tabulku do <strong>CSV</strong> nebo <strong>XLSX</strong>.</li>
  <li>V části Import vyberte, co soubor obsahuje: <strong>předpisy záloh a stavy účtů</strong>, nebo <strong>jednotky, podíly a vlastníky</strong>. Nahrajte soubor. Případně vyberte program, ze kterého soubor je (DOMSYS, IDES, MECOM, INTEGRI) – předvolby jsou jen <em>odhad</em> názvů sloupců.</li>
  <li>Web pozná oddělovač i kódování a podle záhlaví odhadne, který sloupec je jednotka, vlastník, podíl, záloha, saldo nebo variabilní symbol. Na obrazovce <strong>Přiřazení sloupců</strong> to zkontrolujte, případně opravte a klikněte na <strong>Zkontrolovat znovu</strong>.</li>
  <li>V náhledu vidíte každý řádek: co se uloží, co se založí nově a co má chybu (neznámá jednotka, částka, které nerozumíme…). Řádky s chybou se vynechají. Pak klikněte na <strong>Uložit</strong>.</li>
</ol>
<p>Přiřazení sloupců si web pamatuje – příští soubor ze stejného programu se přiřadí sám.</p>

<h2>Dobré vědět</h2>
<ul>
  <li><strong>Jednotka</strong> se pozná podle označení (např. 12A/1), podle vchodu a čísla, podle samotného čísla (je-li v domě jen jedno) nebo podle variabilního symbolu.</li>
  <li><strong>Záporný stav účtu</strong> je nedoplatek, kladný přeplatek. Částky mohou mít mezery, čárku i „Kč“.</li>
  <li>Sloupce, které nepřiřadíte, zůstanou na webu beze změny. Účty vlastníků na webu import nezakládá – ty vznikají pozvánkou.</li>
  <li>Správcovská firma v partnerském portálu stáhne export <strong>všech domů najednou</strong> a nahraje jeden soubor za více domů se sloupcem <strong>Dům</strong> (název domu, IČO nebo adresa webu).</li>
</ul>
