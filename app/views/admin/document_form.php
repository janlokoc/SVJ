<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/dokumenty') ?>">← Dokumenty</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <label><?= $doc['id'] ? 'Nahradit soubor (nepovinné)' : 'Soubor' ?>
      <input type="file" name="file" accept=".<?= implode(',.', ALLOWED_EXT) ?>"<?= $doc['id'] ? '' : ' required' ?>></label>
    <p class="hint"><?php if ($doc['id']): ?>Současný soubor: <a href="<?= url('/soubor/' . $doc['id']) ?>"><?= e($doc['original_name'] ?: 'otevřít') ?></a>. <?php endif ?>Povolené typy: <?= implode(', ', ALLOWED_EXT) ?>. Limit serveru: <?= e(ini_get('upload_max_filesize')) ?>.</p>
    <label>Název <input type="text" name="title" value="<?= e($doc['title']) ?>" maxlength="200" placeholder="Když nevyplníte, použije se název souboru"></label>
    <div class="form-row">
      <label>Kategorie
        <select name="category"><?php foreach (CATEGORIES as $k => $label): ?><option value="<?= $k ?>"<?= $doc['category'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
      </label>
      <label>Datum dokumentu <input type="date" name="doc_date" value="<?= e($doc['doc_date']) ?>"></label>
    </div>
    <?php $current = $doc['visibility']; require __DIR__ . '/_vis.php'; ?>
    <label>Jen pro jednotku (nepovinné)
      <select name="unit_id"><option value="">— pro všechny podle volby výše —</option><?php foreach (unit_options() as $uid => $uname): ?><option value="<?= $uid ?>"<?= (int) $doc['unit_id'] === (int) $uid ? ' selected' : '' ?>><?= e($uname) ?></option><?php endforeach ?></select></label>
    <p class="hint">Dokument přiřazený jednotce uvidí jen lidé z této jednotky a výbor – hodí se pro vyúčtování nebo předpis záloh.</p>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit dokument</button><a class="btn btn-ghost" href="<?= url('/admin/dokumenty') ?>">Zrušit</a></div>
  </form>
</div>
