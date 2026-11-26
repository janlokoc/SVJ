<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/aktuality') ?>">← Aktuality</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <?php if (function_exists('partner_house_templates')) { $tplList = partner_house_templates('post'); $tplTitle = '[name=title]'; $tplBody = 'textarea[name=body]'; require __DIR__ . '/../partner/_tpl_pick.php'; } ?>
    <label>Nadpis <input type="text" name="title" value="<?= e($post['title']) ?>" maxlength="200" required></label>
    <label>Text
      <textarea name="body" rows="10"><?= e($post['body']) ?></textarea>
      <div class="text-preview" data-preview-for="body" hidden><p class="h-small">Náhled – takhle text uvidí obyvatelé</p><div class="prose" aria-live="polite"></div></div>
      <?php $aiTarget = 'textarea[name=body]'; $aiTasks = ['draft', 'polish', 'shorten']; $aiContext = ''; require __DIR__ . '/_ai.php'; ?>
    </label>
    <p class="hint">Odstavce oddělte prázdným řádkem. Odrážky začněte pomlčkou a mezerou (- ), tučné písmo zapište **takto**, odkazy stačí vložit celé (https://…).</p>
    <div class="form-row">
      <label>Datum zveřejnění <input type="date" name="published_at" value="<?= e($post['published_at']) ?>" required></label>
      <?php $current = $post['visibility']; require __DIR__ . '/_vis.php'; ?>
    </div>
    <p class="hint">Aktualita s budoucím datem se na webu objeví až v daný den.</p>
    <label class="check"><input type="checkbox" name="pinned"<?= $post['pinned'] ? ' checked' : '' ?>> Důležité – připnout nahoru</label>
    <fieldset>
      <legend>Přílohy</legend>
      <label>Nahrát nové soubory <input type="file" name="files[]" multiple accept=".<?= implode(',.', ALLOWED_EXT) ?>"></label>
      <p class="hint">Nahrané soubory se zároveň zařadí do sekce Dokumenty (kategorie Oznámení). Limit serveru: <?= e(ini_get('upload_max_filesize')) ?> na soubor.</p>
      <?php $pickId = 'doc-pick'; $pickLabel = 'Nebo vyberte z existujících dokumentů'; $pickSelected = $selected; require __DIR__ . '/_doc_pick.php'; ?>
    </fieldset>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit aktualitu</button><a class="btn btn-ghost" href="<?= url('/admin/aktuality') ?>">Zrušit</a></div>
  </form>
</div>
