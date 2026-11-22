<?php $done = count(array_filter($slots, fn($s) => $s['doc'])); ?>
<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/admin/dokumenty') ?>">← Dokumenty</a></p>
  <header class="page-head"><h1>Základní dokumenty</h1>
    <p class="lead">Dokumenty, které vlastníci na webu společenství hledají nejčastěji. Nahráno <?= $done ?> z <?= count($slots) ?>. Žádný není povinný – nahrajte ty, které máte.</p></header>
  <div class="core-docs">
    <?php foreach ($slots as $key => $s): $d = $s['doc']; ?>
    <section class="card core-doc<?= $d ? ' is-done' : '' ?>" id="<?= e($key) ?>">
      <div class="core-doc-head"><span class="status status-<?= $d ? 'done' : 'rsvp-no' ?>"><?= $d ? 'nahráno' : 'chybí' ?></span><h2><?= e($s[0]) ?></h2></div>
      <p class="muted"><?= e($s[3]) ?></p>
      <?php if ($d): ?><p><a href="<?= url('/soubor/' . $d['id']) ?>"><?= e($d['original_name']) ?></a> <small class="muted">· <?= e(file_size((int) $d['size'])) ?> · <?= cz_date($d['doc_date']) ?> · <?= e(mb_strtolower(VISIBILITY[$d['visibility']])) ?></small>
        · <a href="<?= url('/admin/dokumenty/' . $d['id']) ?>">upravit</a></p><?php endif ?>
      <form method="post" action="<?= url('/admin/dokumenty/zakladni') ?>" enctype="multipart/form-data" class="core-doc-form">
        <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>">
        <label class="sr-only" for="file-<?= e($key) ?>">Soubor – <?= e($s[0]) ?></label>
        <input type="file" name="file" id="file-<?= e($key) ?>" required accept=".<?= implode(',.', ALLOWED_EXT) ?>">
        <select name="visibility" aria-label="Kdo dokument uvidí"><?php foreach (VISIBILITY as $k => $label): ?><option value="<?= $k ?>"<?= ($d['visibility'] ?? $s[2]) === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
        <button class="btn btn-small<?= $d ? ' btn-ghost' : '' ?>" type="submit"><?= $d ? 'Nahrát novou verzi' : 'Nahrát' ?></button>
      </form>
    </section>
    <?php endforeach ?>
  </div>
  <p class="hint">Povolené soubory: <?= implode(', ', ALLOWED_EXT) ?>. Cokoli dalšího (zápisy, pozvánky, závěrky, nabídky) nahrajete běžně přes <a href="<?= url('/admin/dokumenty/novy') ?>">Nahrát dokument</a>.</p>
</div>
