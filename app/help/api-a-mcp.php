<p>Web domu má <strong>API</strong> (rozhraní pro programy) a <strong>MCP</strong> (rozhraní pro AI asistenty jako Claude, ChatGPT nebo Copilot). Přes ně mohou web plnit daty ti, kdo je mají: odečtová firma posílá odečty, účetní předpisy a platby, a obsah starého webu si můžete převést sami – skriptem nebo s pomocí AI asistenta, bez objednávky převodu. API a MCP jsou v edicích Standard a Premium.</p>

<h2>Klíč pro dodavatele nebo asistenta</h2>
<ol>
  <li>V administraci otevřete <strong>API a napojení</strong> (Správa webu).</li>
  <li>V části <em>Vytvořit klíč</em> napište, pro koho je (např. „Převod starého webu“), a zaškrtněte jen to, co má smět – třeba <code>documents:write</code>, <code>events:write</code>, <code>contacts:write</code> a <code>posts:write</code> pro převod obsahu.</li>
  <li>Klikněte na <em>Vytvořit klíč</em>. Klíč (začíná <code>swk_</code>) se ukáže <strong>jen jednou</strong> – zkopírujte si ho. Na webu je uložený jen jeho otisk.</li>
</ol>
<?= help_fig('api-1-klic.jpg', 'Nově vytvořený klíč se ukáže jen jednou; níže adresy pro dodavatele.') ?>
<p>Klíč předejte bezpečně (ne ve stejné zprávě jako adresu webu). Když už ho nepotřebujete, klikněte u něj na <em>Zrušit</em> – přestane platit okamžitě. Každé volání uvidíte v části <em>Poslední volání</em>; po 10 neplatných pokusech za hodinu web adresu zablokuje a pošle správcům e-mail.</p>

<h2>Převod obsahu ze starého webu</h2>
<p>Pro převod je v administraci samostatná stránka <strong>Převod ze starého webu</strong> (Správa webu): jedním tlačítkem vytvoří klíč s oprávněními jen pro převod, nabídne vzorová zadání pro asistenta – přes MCP, nebo přes API, když máte MCP zakázané – a po převodu klíč zrušíte.</p>
<p>Jednotky, účty obyvatel, dokumenty, termíny, kontakty i starší aktuality jde nahrát přes API. Každý záznam může nést označení z původního systému (<code>ext_id</code>) – když převod pustíte znovu, záznamy se jen přepíšou, nic se nezdvojí. <strong>Obyvatelům se při převodu nic neposílá</strong>: aktuality se zapíšou s původním datem bez upozornění a účty vzniknou bez pozvánek – pozvánky pošlete sami, až bude web připravený.</p>
<ul>
  <li><strong>S AI asistentem:</strong> v asistentovi přidejte vlastní MCP server s adresou <code>https://&lt;adresa webu&gt;/mcp</code> a klíčem (v hlavičce <code>Authorization: Bearer swk_…</code>, nebo jako adresu <code>…/mcp/swk_…</code>). Pak mu dejte exportovaná data a napište, ať je převede. Asistent vidí jen nástroje, na které má klíč oprávnění.</li>
  <li><strong>Skriptem:</strong> adresy a formáty jsou v technické dokumentaci (odkaz <em>Dokumentace</em> v API a napojení), včetně popisu OpenAPI.</li>
</ul>
<p class="hint">Napojení konkrétních AI asistentů (Claude, ChatGPT, Copilot) na MCP jsme <strong>neověřovali</strong> – jsou to cizí aplikace a jejich nastavení se mění. Ověřovací běh posílá na web stejné požadavky, jaké posílá asistent (protokol MCP přes HTTP), a ověřuje, že převod funguje.</p>
<?= help_fig('api-2-volani.jpg', 'Poslední volání: převod termínů, dokumentu a kontaktů přes API a MCP.') ?>

<h2>Odečty a účetnictví</h2>
<p>Pro odečtovou firmu je samostatný návod <a href="<?= url('/napoveda/odecty-api') ?>">Dálkové odečty</a>, pro konto bytu z účetnictví <a href="<?= url('/napoveda/pohoda-konto') ?>">Konto bytu z POHODY</a>.</p>
