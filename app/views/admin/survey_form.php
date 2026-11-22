<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/ankety') ?>">← Ankety</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Otázka <input type="text" name="title" value="<?= e($s['title']) ?>" required maxlength="250" placeholder="Chcete před dům stojan na kola?"></label>
    <label>Upřesnění (nepovinné) <textarea name="body" rows="3"><?= e((string) $s['body']) ?></textarea></label>
    <label>Možnosti (každá na samostatný řádek) <textarea name="options" rows="5"<?= $s['total'] ? ' disabled' : ' required' ?> placeholder="Ano&#10;Ne&#10;Je mi to jedno"><?= e($optionsText) ?></textarea></label>
    <?php if ($s['total']): ?><p class="hint">Už přišly odpovědi (<?= (int) $s['total'] ?>), možnosti proto nejdou měnit.</p><?php endif ?>
    <label>Odpovídat lze do <input type="date" name="closes_at" value="<?= e($s['closes_at']) ?>"></label>
    <label class="check"><input type="checkbox" name="anonymous"<?= $s['anonymous'] ? ' checked' : '' ?><?= $s['total'] ? ' disabled' : '' ?>> <span>Anonymní <small>Výbor uvidí jen počty. Bez zaškrtnutí uvidí i jména – obyvatelé jsou na to u ankety upozorněni.</small></span></label>
    <?php if ($s['total'] && $s['anonymous']): ?><input type="hidden" name="anonymous" value="1"><?php endif ?>
    <label class="check"><input type="checkbox" name="published"<?= $s['published'] ? ' checked' : '' ?>> <span>Zveřejnit obyvatelům</span></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit anketu</button><a class="btn btn-ghost" href="<?= url('/admin/ankety') ?>">Zrušit</a></div>
  </form>
  <?php if ($s['id'] && $s['total']): ?>
  <div class="card"><h2>Výsledky</h2>
    <?php foreach ($s['options'] as $o): $pct = (int) round($o['votes'] / $s['total'] * 100); ?>
      <div class="survey-row"><span><?= e($o['label']) ?></span><span class="meter"><span class="meter-fill" data-width="<?= $pct ?>"></span></span><strong><?= $pct ?> % <small>(<?= (int) $o['votes'] ?>)</small></strong></div>
      <?php $who = array_column(array_filter($names, fn($n) => (int) $n['option_id'] === (int) $o['id']), 'name'); if ($who): ?><p class="hint"><?= e(implode(', ', $who)) ?></p><?php endif ?>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
