<?php /* API a napojení: $keys, $new (právě vytvořený klíč, jen jednou), $log, $fails. */
$base = absolute_url('/api/v1');
$docs = function_exists('platform_url') ? platform_url('/api') : ''; ?>
<div class="wrap page">
  <header class="page-head"><h1>API a napojení</h1>
    <p class="lead">Klíč pro dodavatele, kteří mají plnit web daty: odečtová firma (odečty měřidel), účetní nebo správce (předpisy, pohledávky, platby), případně AI asistent výboru (MCP). Každý klíč smí jen to, co mu zaškrtnete, a jde kdykoli zrušit.</p></header>
  <?php if ($new !== ''): ?>
  <div class="card api-new">
    <h2>Nový klíč</h2>
    <p><strong>Zkopírujte si ho teď</strong> a předejte dodavateli bezpečně (ne ve stejném e-mailu jako adresu). Na webu se ukládá jen jeho otisk, znovu ho neuvidíte.</p>
    <p><input type="text" class="join-url" value="<?= e($new) ?>" readonly data-select-all aria-label="Nový klíč API"></p>
  </div>
  <?php endif ?>
  <section class="card">
    <h2>Adresy pro dodavatele</h2>
    <dl class="pay-data">
      <dt>REST API</dt><dd><code><?= e($base) ?></code></dd>
      <dt>MCP (AI asistenti)</dt><dd><code><?= e(absolute_url('/mcp')) ?></code></dd>
      <?php if ($docs !== ''): ?><dt>Dokumentace</dt><dd><a href="<?= e($docs) ?>" target="_blank" rel="noopener"><?= e($docs) ?></a></dd><?php endif ?>
    </dl>
    <p class="hint">Klíč se posílá v hlavičce <code>Authorization: Bearer swk_…</code>. <?= help_link('api-a-mcp', 'Návod: klíč pro dodavatele a AI asistenta') ?></p>
  </section>
  <section class="card" id="zadani">
    <h2>Vzorová zadání pro AI asistenta</h2>
    <p>V AI asistentovi (Claude, ChatGPT, Copilot…) přidejte vlastní MCP server s adresou <code><?= e(absolute_url('/mcp')) ?></code> a klíčem s oprávněními uvedenými u zadání. Pak zadání zkopírujte, doplňte údaje v hranatých závorkách a vložte asistentovi.</p>
    <?php foreach (api_prompts() as $pi => [$pTitle, $pScopes, $pText]): ?>
    <details class="api-prompt"<?= $pi === 0 ? ' open' : '' ?>><summary><strong><?= e($pTitle) ?></strong> <small class="muted">oprávnění: <?= e(implode(', ', $pScopes)) ?></small></summary>
      <textarea readonly rows="8" aria-label="Zadání: <?= e($pTitle) ?>"><?= e($pText) ?></textarea>
      <p class="actions"><button class="btn btn-small" type="button" data-copy="<?= e($pText) ?>">Zkopírovat zadání</button></p>
    </details>
    <?php endforeach ?>
  </section>
  <form method="post" action="<?= url('/admin/api') ?>" class="card form" id="novy">
    <?= csrf_field() ?><input type="hidden" name="do" value="create">
    <h2>Vytvořit klíč</h2>
    <label>Pro koho (název) <input type="text" name="name" maxlength="100" required placeholder="např. Techem – odečty, Účetní Nováková"></label>
    <fieldset class="checks"><legend>Co smí</legend>
      <?php foreach (API_SCOPES as $k => $label): ?><label class="check"><input type="checkbox" name="scopes[]" value="<?= e($k) ?>"> <span><code><?= e($k) ?></code> <small><?= e($label) ?></small></span></label><?php endforeach ?>
    </fieldset>
    <label>Jen z IP adres dodavatele (nepovinné) <input type="text" name="ips" placeholder="203.0.113.10, 198.51.100.0/24"></label>
    <div class="actions"><button class="btn" type="submit">Vytvořit klíč</button></div>
  </form>
  <section class="card">
    <h2>Klíče</h2>
    <?php if (!$keys): ?><p class="empty">Zatím žádný klíč.</p><?php else: ?>
    <table class="table"><thead><tr><th>Název</th><th>Oprávnění</th><th>Naposledy použit</th><th></th></tr></thead><tbody>
      <?php foreach ($keys as $k): ?><tr<?= $k['revoked_at'] !== '' ? ' class="muted"' : '' ?>>
        <td data-label="Název"><strong><?= e($k['name']) ?></strong> <small class="muted"><code><?= e($k['prefix']) ?>…</code></small><?= $k['ips'] !== '' ? '<br><small class="muted">jen z ' . e($k['ips']) . '</small>' : '' ?></td>
        <td data-label="Oprávnění"><small><?= e(str_replace(' ', ', ', $k['scopes'])) ?></small></td>
        <td data-label="Naposledy použit"><?= $k['last_used_at'] !== '' ? e(cz_datetime($k['last_used_at'])) : '–' ?></td>
        <td data-label=""><?php if ($k['revoked_at'] === ''): ?><form method="post" action="<?= url('/admin/api') ?>" data-confirm="Zrušit klíč „<?= e($k['name']) ?>“? Dodavatel přestane posílat data."><?= csrf_field() ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>"><button class="btn btn-small btn-ghost" type="submit">Zrušit</button></form><?php else: ?><small>zrušen <?= e(cz_date($k['revoked_at'])) ?></small><?php endif ?></td>
      </tr><?php endforeach ?>
    </tbody></table><?php endif ?>
  </section>
  <section class="card">
    <h2>Poslední volání</h2>
    <?php if ($fails): ?><p class="callout">Neplatné pokusy za 7 dní: <strong><?= $fails ?></strong>. Po 10 za hodinu web adresu na hodinu zablokuje a pošle správcům e-mail.</p><?php endif ?>
    <?php if (!$log): ?><p class="empty">Zatím žádné.</p><?php else: ?>
    <table class="table"><thead><tr><th>Kdy</th><th>Klíč</th><th>Adresa</th><th>Výsledek</th></tr></thead><tbody>
      <?php foreach ($log as $l): ?><tr><td data-label="Kdy"><?= e(cz_datetime($l['at'])) ?></td><td data-label="Klíč"><?= e($l['key_name'] ?? '–') ?> <small class="muted"><?= e($l['ip']) ?></small></td>
        <td data-label="Adresa"><code><?= e($l['method'] . ' ' . $l['path']) ?></code></td><td data-label="Výsledek"><?= $l['ok'] ? (int) $l['status'] : '<strong>' . (int) $l['status'] . '</strong>' ?> <?= e($l['note']) ?></td></tr><?php endforeach ?>
    </tbody></table><?php endif ?>
  </section>
</div>
