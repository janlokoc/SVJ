<?php $okCount = $rows ? count(array_filter($rows, fn($r) => $r['status'] === 'ok')) : 0; ?>
<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/admin/uzivatele') ?>">← Uživatelé</a></p>
  <header class="page-head"><h1>Import uživatelů</h1>
    <p class="lead">Založí účty obyvatel najednou ze seznamu, který máte v tabulce.</p></header>
  <?php if ($rows !== null && !$rows): ?><div class="flash flash-error">Ve vloženém textu nebyl nalezen žádný řádek s uživatelem.</div><?php endif ?>
  <form method="post" action="<?= url('/admin/uzivatele/import') ?>" class="card form">
    <?= csrf_field() ?>
    <label>Seznam uživatelů
      <textarea name="text" id="users-text" rows="<?= $okCount ? 4 : 10 ?>" required placeholder="Jan;Novák;777 123 456;jan.novak@example.cz;vlastník;48A/5"><?= e($text) ?></textarea></label>
    <p class="hint">Jeden uživatel na řádek, sloupce v tomto pořadí: <strong>jméno, příjmení, telefon, e-mail, vlastník/nájemník</strong> a nepovinně <strong>jednotka</strong> (např. 48A/5). Nejjednodušší je označit buňky v Excelu a vložit je sem; funguje i text oddělený středníky nebo čárkami. Řádek se záhlavím se přeskočí. Prázdný typ znamená vlastník.</p>
    <?php if ($okCount): ?>
      <label class="check"><input type="checkbox" name="invite" id="users-invite"<?= $mailReady ? ' checked' : ' disabled' ?>> <span>Rozeslat novým uživatelům e-mail s odkazem na nastavení hesla
        <small><?= $mailReady ? 'Odkaz platí ' . setting_int('invite_days', 14, 1, 90) . ' dní. Bez pozvánky se uživatel nepřihlásí, dokud mu ji nepošlete později.' : 'Nejdřív v Nastavení vyplňte adresu odesílatele e-mailů. Pozvánky pak pošlete u jednotlivých účtů.' ?></small></span></label>
    <?php endif ?>
    <div class="actions">
      <button class="btn<?= $okCount ? ' btn-ghost' : '' ?>" type="submit" name="action" value="preview">Zobrazit náhled</button>
      <?php if ($okCount): ?><button class="btn btn-big" type="submit" name="action" value="save" data-confirm="Založit <?= $okCount ?> účtů?">Založit <?= $okCount ?> účtů</button><?php endif ?>
    </div>
  </form>
  <?php if ($rows): ?>
    <h2>Náhled</h2>
    <p>Řádků: <strong><?= count($rows) ?></strong>, založí se: <strong><?= $okCount ?></strong>, přeskočí se: <strong><?= count($rows) - $okCount ?></strong>.</p>
    <table class="table">
      <thead><tr><th>Řádek</th><th>Jméno</th><th>Telefon</th><th>E-mail</th><th>Typ</th><th>Jednotka</th><th>Stav</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'ok' ? '' : ' class="is-off"' ?>>
          <td data-label="Řádek"><?= (int) $r['line'] ?></td>
          <td data-label="Jméno"><strong><?= e($r['name']) ?></strong></td>
          <td data-label="Telefon"><?= e($r['phone']) ?></td>
          <td data-label="E-mail"><?= e($r['email']) ?></td>
          <td data-label="Typ"><?= mb_strtolower(KINDS[$r['kind']]) ?></td>
          <td data-label="Jednotka"><?= e(implode(', ', array_map('unit_name', $r['units']))) ?></td>
          <td data-label="Stav"><?php if ($r['status'] === 'ok'): ?><span class="status status-done">založí se</span><?php elseif ($r['status'] === 'exists'): ?><span class="status status-in_progress">přeskočí se</span><?php else: ?><span class="status status-new">chyba</span><?php endif ?>
            <?= $r['message'] ? '<br><small>' . e($r['message']) . '</small>' : '' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
</div>
