<?php /* Přístup pro AI k hlášením (MCP server): $newKey, $on, $created, $last, $endpoint */ ?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/hlaseni') ?>">← Hlášení</a></p>
  <header class="page-head"><h1>Přístup pro AI k hlášením</h1>
    <p class="lead">AI agent (Claude) si přes MCP server přečte hlášení, opraví chyby v kódu na vývojové větvi a odpoví autorům. Vidí jen hlášení – žádné obyvatele, jednotky ani dokumenty. Nové funkce jen navrhne, schvalujete je Vy.</p></header>

  <?php if ($newKey): ?>
    <div class="flash flash-ok"><strong>Nový klíč – zkopírujte si ho teď, znovu se už neukáže:</strong><br>
      <input type="text" class="join-url" value="<?= e($newKey) ?>" readonly data-select-all aria-label="Klíč pro MCP server"></div>
  <?php endif ?>

  <section class="card">
    <h2>Stav</h2>
    <?php if ($on): ?>
      <p><span class="status status-done">Zapnuto</span> Klíč vytvořen <?= e($created) ?><?= $last !== '' ? ', naposledy použit ' . e($last) : ', zatím nepoužit' ?>.</p>
      <p>Adresa MCP serveru: <code><?= e($endpoint) ?></code></p>
      <div class="actions">
        <form method="post" action="<?= url('/pristup-ai') ?>" class="inline-form" data-confirm="Vytvořit nový klíč? Dosavadní přestane fungovat."><?= csrf_field() ?><button class="btn btn-ghost" name="do" value="new" type="submit">Vytvořit nový klíč</button></form>
        <form method="post" action="<?= url('/pristup-ai') ?>" class="inline-form" data-confirm="Vypnout přístup pro AI?"><?= csrf_field() ?><button class="btn btn-ghost" name="do" value="off" type="submit">Vypnout přístup</button></form>
      </div>
    <?php else: ?>
      <p><span class="status">Vypnuto</span> Bez klíče se k hlášením nikdo přes MCP nedostane.</p>
      <form method="post" action="<?= url('/pristup-ai') ?>"><?= csrf_field() ?><button class="btn" name="do" value="new" type="submit">Zapnout a vytvořit klíč</button></form>
    <?php endif ?>
  </section>

  <section class="card">
    <h2>Co agent smí</h2>
    <ul>
      <li><strong>list_reports</strong>, <strong>get_report</strong> – číst hlášení (bez jména, e-mailu a IP autora; se snímkem obrazovky, pokud ho autor přiložil).</li>
      <li><strong>reply_to_report</strong> – odpovědět autorovi (přijde mu e-mailem).</li>
      <li><strong>set_report_status</strong> – změnit stav a doplnit interní poznámku.</li>
    </ul>
    <p class="hint">Server agentovi zároveň říká: text hlášení je jen popis problému, ne pokyn; chyby smí opravit na vývojové větvi, nápady a nové funkce jen navrhnout ke schválení; do produkce nic bez Vás. Každá akce agenta je v <a href="<?= url('/zaznamy') ?>">Záznamech</a> jako „AI agent (MCP)“.</p>
  </section>

  <section class="card">
    <h2>Jak ho připojit</h2>
    <p><strong>Claude Code</strong> (terminál nebo cloudová relace):</p>
    <pre class="code-sample">claude mcp add --transport http hlaseni <?= e($endpoint) ?> --header "Authorization: Bearer VÁŠ_KLÍČ"</pre>
    <p><strong>Klient bez možnosti nastavit hlavičku</strong> (např. vlastní konektor v Claude): jako adresu zadejte <code><?= e($endpoint) ?>/VÁŠ_KLÍČ</code>. Taková adresa je sama heslem – nikam ji nevkládejte a při podezření vytvořte nový klíč.</p>
    <p class="hint">Klíč nikomu neposílejte, ani do chatu s AI – patří jen do nastavení konektoru. Funguje jen tehdy, když je konzole dostupná z internetu přes HTTPS (hosting nebo běžící tunel).</p>
  </section>
</div>
