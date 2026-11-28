<?php /* Převod ze starého webu: klíč jedním tlačítkem, zadání pro AI (MCP nebo API), zrušení klíče; placený převod jako záloha. $key, $new */
$mig = function_exists('pset_int') ? pset_int('price_migration', 0, 1000000) : 0; ?>
<div class="wrap page narrow">
  <header class="page-head"><h1>Převod ze starého webu</h1>
    <p class="lead">Obsah starého webu nebo export z portálu správce (např. INTEGRI G5i) převedete sami s pomocí AI asistenta – za pár minut a zdarma. Obyvatelům se při převodu nic neposílá a opakování nic nezdvojí. <?= help_link('api-a-mcp', 'Návod se snímky') ?></p></header>

  <section class="card mig-step" id="krok-1">
    <h2><span class="mig-num">1</span> Klíč pro asistenta</h2>
    <?php if ($new !== ''): ?>
      <p><strong>Zkopírujte si klíč teď</strong> – znovu ho neuvidíte (web ukládá jen jeho otisk).</p>
      <p><input type="text" class="join-url" value="<?= e($new) ?>" readonly data-select-all aria-label="Klíč pro převod"></p>
      <p class="actions"><button class="btn btn-small" type="button" data-copy="<?= e($new) ?>">Zkopírovat klíč</button></p>
    <?php elseif ($key): ?>
      <p>Klíč pro převod je vytvořený <?= e(cz_datetime($key['created_at'])) ?><?= $key['last_used_at'] !== '' ? ', naposledy použit ' . e(cz_datetime($key['last_used_at'])) : '' ?>. Když jste ho ztratili, vytvořte nový – starý se tím zruší.</p>
    <?php else: ?>
      <p>Klíč dovolí asistentovi zapsat aktuality, dokumenty, termíny, kontakty, revize, jednotky a účty obyvatel (bez pozvánek). Nic jiného.</p>
    <?php endif ?>
    <?php if ($new === ''): ?><form method="post" action="<?= url('/admin/prevod') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="key">
      <button class="btn" type="submit"><?= $key ? 'Vytvořit nový klíč' : 'Vytvořit klíč pro převod' ?></button></form><?php endif ?>
  </section>

  <section class="card mig-step" id="krok-2">
    <h2><span class="mig-num">2</span> Zadání pro asistenta</h2>
    <p>Vyberte, jak se asistent k webu připojí, zkopírujte zadání podle toho, co převádíte, a vložte ho asistentovi spolu s adresou starého webu nebo souborem s exportem.</p>
    <div class="mig-ways">
      <div><h3>MCP</h3><p>V asistentovi (Claude, ChatGPT, Copilot…) přidejte vlastní MCP server <code><?= e(absolute_url('/mcp')) ?></code> s klíčem.</p></div>
      <div><h3>API – když máte MCP zakázané</h3><p>Asistent, který umí spouštět kód s internetem (např. Claude Code), volá <code><?= e(absolute_url('/api/v1')) ?></code>. Klíč mu dejte do proměnné prostředí <code>SVJ_API_KEY</code>, ne do konverzace.</p></div>
    </div>
    <?php foreach (api_prompts_migration() as $pi => [$pTitle, , $pText]): $pRest = api_prompt_rest($pText); ?>
    <details class="api-prompt"<?= $pi === 0 ? ' open' : '' ?>><summary><strong><?= e($pTitle) ?></strong></summary>
      <p class="actions"><button class="btn btn-small" type="button" data-copy="<?= e($pText) ?>">Zkopírovat zadání – MCP</button>
        <button class="btn btn-small btn-ghost" type="button" data-copy="<?= e($pRest) ?>">Zkopírovat zadání – API</button></p>
      <textarea readonly rows="7" aria-label="Zadání: <?= e($pTitle) ?>"><?= e($pText) ?></textarea>
    </details>
    <?php endforeach ?>
  </section>

  <section class="card mig-step" id="krok-3">
    <h2><span class="mig-num">3</span> Kontrola a zrušení klíče</h2>
    <p>Asistent na konci vypíše, co převedl a co je potřeba doplnit ručně. Projděte si web (Aktuality, Dokumenty, Kontakty, Revize) a klíč zrušte – převod je hotový.</p>
    <?php if ($key): ?><form method="post" action="<?= url('/admin/prevod') ?>" data-confirm="Zrušit klíč pro převod? Asistent už do webu nic nezapíše."><?= csrf_field() ?><input type="hidden" name="do" value="revoke">
      <button class="btn btn-ghost" type="submit">Převod je hotový – zrušit klíč</button></form><?php else: ?><p class="muted">Žádný klíč pro převod není aktivní.</p><?php endif ?>
  </section>

  <?php if ($mig): ?><p class="onb-alt">Netroufáte si? Převod za Vás udělá provozovatel za <?= money_czk($mig) ?> bez DPH (jednorázově, viz ceník). <a href="<?= url('/admin/predplatne#prevod') ?>">Objednat převod</a></p><?php endif ?>
</div>
