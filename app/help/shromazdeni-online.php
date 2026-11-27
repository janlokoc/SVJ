<p>Vlastníci se mohou shromáždění zúčastnit a hlasovat na dálku – přes videohovor a web domu. Shromáždění může být <strong>hybridní</strong> (část vlastníků na místě, část online) nebo celé <strong>online</strong>. Prezence, usnášeníschopnost, hlasování i zápis počítají obě skupiny dohromady.</p>
<p class="callout"><strong>Nejdřív stanovy.</strong> Účast a hlasování technickými prostředky jsou platné jen tehdy, když to umožňují stanovy (§ 1206 odst. 2 občanského zákoníku). Bez takového ustanovení musí být shromáždění prezenční. Vzorové ustanovení najdete v administraci pod <strong>Shromáždění online</strong> – znění si nechte zkontrolovat; změnu stanov schvaluje shromáždění a zapisuje se do veřejného rejstříku.</p>

<h2>1. Zapnutí pro dům</h2>
<p><strong>Administrace → Shromáždění online.</strong> Zaškrtněte <em>Povolit shromáždění online a hybridní</em>. Pokud stanovy vyžadují silnější ověření totožnosti, zaškrtněte i <em>ověřit vlastníka kódem z e-mailu</em> – vlastník pak při připojení dostane šestimístný kód (platí 10 minut).</p>
<p>Shromáždění online je v ceně edice Standard i Premium.</p>
<?= help_fig('shrom-online-1-nastaveni.jpg', 'Shromáždění online: zapnutí pro dům a vzorové ustanovení stanov.') ?>

<h2>2. Forma u termínu a odkaz na videohovor</h2>
<p><strong>Termíny → termín shromáždění.</strong> V části <em>Forma shromáždění</em> zvolte <em>Hybridní</em> nebo <em>Online</em>. Do pole <em>Odkaz na videohovor</em> vložte adresu z pozvánky do Microsoft Teams, Zoomu nebo Google Meet, nebo klikněte <strong>Vytvořit místnost Jitsi</strong> – web doplní adresu nové místnosti na meet.jit.si s náhodným názvem. Nakonec zaškrtněte <em>Stanovy umožňují účast a hlasování technickými prostředky</em>; bez toho termín uložit nejde.</p>
<?= help_fig('shrom-online-2-termin.jpg', 'Forma shromáždění, odkaz na videohovor a potvrzení stanov.') ?>
<p class="hint">Jitsi Meet (meet.jit.si) je veřejná služba zdarma. Místnost musí otevřít přihlášený uživatel – předsedající se přihlásí účtem Google, GitHub nebo Facebook (podle oznámení Jitsi z 22. 8. 2023); vlastníci se připojují bez účtu. Podle podmínek služby (z roku 2020) je bezplatné použití omezené na 25 připojení měsíčně – u velkého domu proto zvažte Teams, Zoom nebo Google Meet, které už výbor možná používá. Kdo zná adresu místnosti, může do ní vstoupit. Proto má místnost náhodný název a odkaz vidí jen vlastníci, kteří se v den konání připojí na webu. Videohovory web neprovozuje ani nenahrává. Chování služeb Jitsi, Teams, Zoom a Google Meet jsme neověřovali – jde o cizí weby.</p>

<h2>3. Vlastník se připojí</h2>
<p>V den konání vlastník na stránce termínu klikne <strong>Připojit se online</strong> a potvrdí. Web ho zapíše do prezence za všechny jeho jednotky (kromě těch, které už jsou na místě osobně nebo přes zmocněnce) a uloží čas, IP adresu a způsob ověření. Videohovor se otevře v nové kartě; stránka na webu zůstane otevřená kvůli hlasování.</p>
<?= help_fig('shrom-online-3-pripojeni.jpg', 'Pohled vlastníka: přítomen online, odkaz na videohovor.') ?>
<p>Když vlastník odchází dřív, klikne <strong>Odcházím</strong> – jeho podíly se přestanou počítat do přítomných. Vrátit se může stejným tlačítkem.</p>

<h2>4. Prezence: na místě i online dohromady</h2>
<p>V <strong>Prezenci na místě</strong> vidíte u každé jednotky, zda je přítomna osobně, přes zmocněnce, nebo online. Souhrn podílů a usnášeníschopnost se obnovují samy každé 4 sekundy. V části <em>Účast a hlasování online</em> je seznam připojení (kdo, kdy, ověření, IP adresa).</p>
<?= help_fig('shrom-online-4-prezence.jpg', 'Prezence: součet podílů včetně online přítomných.') ?>

<h2>5. Hlasování online</h2>
<p>Až návrh přednesete, klikněte u něj v části <em>Účast a hlasování online</em> na <strong>Pustit k hlasování online</strong>. Připojení vlastníci ho do několika sekund uvidí na webu a hlasují <em>Pro</em>, <em>Proti</em> nebo <em>Zdržel se</em> – hlas platí za všechny jejich online jednotky podle podílů. Výsledek se Vám průběžně sčítá u návrhu spolu s hlasy na místě a pod ním je vidět, kolik hlasů přišlo online a kolik na místě.</p>
<?= help_fig('shrom-online-5-hlasovani.jpg', 'Návrh puštěný k hlasování online a průběžný výsledek.') ?>
<p class="callout">Na místě platí „kdo neřekne jinak, je pro“. <strong>Online přítomný, který nehlasuje, se počítá jako „zdržel se“</strong> – mlčení online může být jen výpadek spojení. Hlasování ukončíte jako obvykle tlačítkem <strong>Uzavřít hlasování</strong>; výsledek se zafixuje a online hlasovat už nejde.</p>

<h2>6. Zápis a prezenční listina</h2>
<p><strong>Zápis ze shromáždění</strong> uvede formu shromáždění, službu videohovoru, kdo se účastnil online (s časy připojení a odchodu) a u každého návrhu, kolik hlasů přišlo online a kolik na místě. <strong>Prezenční listina</strong> má u online jednotek „online“ a v příloze seznam připojení a hlasů odevzdaných online s časem.</p>
<?= help_fig('shrom-online-6-zapis.jpg', 'Zápis: forma shromáždění a hlasy online a na místě.') ?>
