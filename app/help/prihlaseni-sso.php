<p>Na web domu se můžete kromě e-mailu a hesla přihlásit i účtem <strong>Google</strong>, <strong>Microsoft</strong>, <strong>Apple</strong> nebo <strong>Facebook</strong> – podle toho, které z nich provozovatel služby zapnul. Heslo k těmto účtům web nikdy nevidí: přihlášení proběhne na stránce poskytovatele a web se dozví jen to, kdo jste.</p>

<h2>Přihlášení jedním kliknutím</h2>
<ol>
  <li>Na stránce <strong>Přihlášení</strong> klepněte pod formulářem na <strong>Přihlásit přes Google</strong> (nebo Microsoft, Apple, Facebook).</li>
  <li>Na stránce poskytovatele vyberte svůj účet a potvrďte.</li>
  <li>Vrátíte se na web domu už přihlášení. Máte-li zapnuté <strong>dvoufázové ověření</strong>, web se ještě zeptá na kód z aplikace v telefonu – stejně jako po zadání hesla.</li>
</ol>
<?= help_fig('sso-1-prihlaseni.jpg', 'Přihlášení: tlačítka poskytovatelů pod formulářem.') ?>
<p class="callout"><strong>Účet tím nevznikne.</strong> Přihlásit se tak může jen ten, komu už výbor účet založil (pozvánkou nebo odkazem pro přiřazení k jednotce) a kdo pozvánku přijal. Web Vás pozná podle e-mailu, který Vám poskytovatel potvrdil jako ověřený – musí to být stejný e-mail, na který máte účet zde.</p>

<h2>Jiný e-mail nebo skrytý e-mail Apple: propojte si účet</h2>
<p>Používáte u Googlu, Microsoftu nebo Facebooku jiný e-mail, než na který Vám výbor účet založil? Nebo jste u Apple zvolili <em>Skrýt můj e-mail</em>? Pak si účet jednou propojte:</p>
<ol>
  <li>Přihlaste se na web domu jako obvykle (e-mailem a heslem).</li>
  <li>Otevřete <strong>Můj účet</strong> a v části <strong>Přihlášení přes další účty</strong> klepněte u poskytovatele na <strong>Propojit účet</strong>.</li>
  <li>Přihlaste se u poskytovatele. Po návratu uvidíte u poskytovatele <span class="status status-done">Propojeno</span>.</li>
</ol>
<?= help_fig('sso-2-ucet.jpg', 'Můj účet: propojený účet Apple a tlačítka pro další poskytovatele.') ?>
<p>Od té doby Vás web pozná podle stálého identifikátoru, který mu poskytovatel posílá – e-mail už nehraje roli. Propojení zrušíte tamtéž tlačítkem <strong>Zrušit propojení</strong>; heslo ani dvoufázové ověření se tím nemění.</p>

<h2>Dobré vědět</h2>
<ul>
  <li><strong>Pracovní účty Microsoft</strong> (firemní Microsoft 365): e-mail z nich web bere jako ověřený, jen když to Microsoft u daného účtu výslovně potvrdí. Pokud přihlášení nepůjde, účet si propojte podle postupu výše.</li>
  <li><strong>Nepovedlo se?</strong> Web napíše proč: např. že s tímto e-mailem tu účet není, že poskytovatel e-mail nepotvrdil, nebo že účet ještě čeká na přijetí pozvánky.</li>
  <li><strong>Dokončení přihlášení platí 2 minuty</strong> a jen v prohlížeči, ve kterém jste začali. Odkaz z cizího zařízení nikoho nepřihlásí.</li>
  <li><strong>Záznamy:</strong> přihlášení i propojení vidí správci webu v Záznamech (kdo, kdy, přes kterého poskytovatele).</li>
  <li><strong>Co se ukládá:</strong> u propojeného účtu jen název poskytovatele, jeho identifikátor uživatele a e-mail, který poskytovatel poslal. Nic dalšího (kontakty, fotky…) web nežádá.</li>
</ul>

<h2>Pro výbor a správce webu</h2>
<p>Ve webu domu nic nastavovat nemusíte. Tlačítka se objeví sama, jakmile provozovatel služby přihlášení přes daného poskytovatele zapne. Uživatele dál zakládáte jako dosud (pozvánkou); kdo účet nemá, přes Google ani jiného poskytovatele se nedostane.</p>
<p class="hint">Stránky poskytovatelů (výběr účtu Google, Microsoft, Apple, Facebook) jsou cizí weby, jejich vzhled ani přesné texty tento návod neověřuje a mohou se měnit. Celé přihlášení u poskytovatele ověřovací běh nezkouší (nemá u nich účty): vyhledání účtu podle ověřeného e-mailu, dotaz na kód dvoufázového ověření a dokončení propojení ověřují jen automatické testy webu se vzorovými odpověďmi poskytovatelů.</p>
