<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/dokumenty') ?>">← Dokumenty</a></p>
  <h1>Dokumenty pro jednotky</h1>
  <p class="lead">Nahraje najednou dokumenty, z nichž každý uvidí jen lidé z jedné jednotky – typicky roční vyúčtování služeb nebo předpis záloh.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if ($report): ?>
    <div class="flash">Nahráno souborů: <?= count($report['ok']) ?>.</div>
    <?php if ($report['skipped']): ?><div class="flash flash-error">Přeskočeno: <?= count($report['skipped']) ?><?php foreach ($report['skipped'] as $s): ?><div><?= e($s) ?></div><?php endforeach ?></div><?php endif ?>
    <?php if ($report['ok']): ?><details class="card"><summary>Co se kam nahrálo</summary><ul><?php foreach ($report['ok'] as $s): ?><li><?= e($s) ?></li><?php endforeach ?></ul></details><?php endif ?>
  <?php endif ?>
  <form method="post" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <label>Název <input type="text" name="title" value="<?= e($form['title']) ?>" required placeholder="Např. Vyúčtování služeb 2026"></label>
    <div class="form-row">
      <label>Kategorie <select name="category"><?php foreach (CATEGORIES as $k => $label): ?><option value="<?= $k ?>"<?= $form['category'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
      <label>Datum <input type="date" name="doc_date" value="<?= e($form['doc_date']) ?>"></label>
    </div>
    <label>Soubory <input type="file" name="files[]" multiple required accept=".<?= implode(',.', ALLOWED_EXT) ?>"></label>
    <p class="hint">Jednotka se pozná z názvu souboru: například <code>48A-5.pdf</code>, <code>B10.pdf</code> nebo <code>vyuctovani_48C_3.pdf</code>. Soubor, u kterého se jednotka nepozná, se nenahraje a vypíše se. Server přijme najednou nejvýše <?= e(ini_get('max_file_uploads')) ?> souborů o celkové velikosti <?= e(ini_get('post_max_size')) ?>.</p>
    <div class="actions"><button class="btn btn-big" type="submit">Nahrát</button></div>
  </form>
</div>
