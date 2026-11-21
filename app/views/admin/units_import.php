<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/admin/jednotky') ?>">← Jednotky</a></p>
  <header class="page-head"><h1>Import z katastru</h1>
    <p class="lead">Založí jednotky a doplní k nim vlastníky a spoluvlastnické podíly podle katastru nemovitostí.</p></header>
  <?php if ($parsed && !$parsed['units']): ?><div class="flash flash-error">Ve vloženém textu se nepodařilo najít žádné vlastníky s jednotkami. Zkopírujte celou stránku stavby včetně části „Vlastníci, jiní oprávnění“.</div><?php endif ?>
  <form method="post" action="<?= url('/admin/jednotky/import') ?>" class="card form">
    <?= csrf_field() ?>
    <label>Text z Nahlížení do katastru nemovitostí
      <textarea name="text" id="cadastre-text" rows="<?= $parsed && $parsed['units'] ? 4 : 12 ?>" required><?= e($text) ?></textarea></label>
    <p class="hint">Na webu nahlizenidokn.cuzk.gov.cz otevřete stránku stavby (bytového domu), označte celý její obsah (Ctrl+A), zkopírujte (Ctrl+C) a vložte sem. Před uložením uvidíte náhled.</p>
    <div class="actions">
      <button class="btn<?= $parsed && $parsed['units'] ? ' btn-ghost' : '' ?>" type="submit" name="action" value="preview">Zobrazit náhled</button>
      <?php if ($parsed && $parsed['units']): ?><button class="btn btn-big" type="submit" name="action" value="save">Uložit <?= count($parsed['units']) ?> jednotek</button><?php endif ?>
    </div>
  </form>
  <?php if ($parsed && $parsed['units']): ?>
    <h2>Náhled</h2>
    <p>Nalezeno jednotek: <strong><?= count($parsed['units']) ?></strong>. Součet podílů: <strong><?= percent_cz($parsed['total'] * 100) ?></strong><?= abs($parsed['total'] - 1) < 0.0001 ? ' – sedí.' : ' – nevychází na 100 %, zkontrolujte, zda je text úplný.' ?></p>
    <?php foreach ($parsed['warnings'] as $w): ?><div class="flash flash-info"><?= e($w) ?></div><?php endforeach ?>
    <p class="hint">Uložením se u existujících jednotek přepíše vlastník dle evidence a podíl. Patro, poznámka a přiřazené účty zůstanou. Adresy vlastníků se neukládají.</p>
    <table class="table">
      <thead><tr><th>Jednotka</th><th>V katastru</th><th>Vlastník dle evidence</th><th>Podíl</th><th>Stav</th></tr></thead>
      <tbody>
      <?php foreach ($parsed['units'] as $key => $u): $ex = $existing[$u['entrance'] . '/' . $u['number']] ?? null; ?>
        <tr>
          <td data-label="Jednotka"><strong><?= e(unit_name($u)) ?></strong></td>
          <td data-label="V katastru"><?= e($key) ?></td>
          <td data-label="Vlastník dle evidence"><?= e($u['owner_note']) ?></td>
          <td data-label="Podíl"><?= e($u['share']) ?></td>
          <td data-label="Stav"><?php if (!$ex): ?><span class="status status-done">nová</span><?php elseif ($ex['share'] === $u['share'] && $ex['owner_note'] === $u['owner_note']): ?><span class="muted">beze změny</span><?php else: ?><span class="status status-in_progress">změna</span><?php endif ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
</div>
