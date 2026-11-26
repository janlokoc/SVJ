<div class="wrap page narrow">
  <header class="page-head"><h1>Hromadné oznámení</h1>
    <p class="lead">Jedna aktualita do více domů najednou – třeba odstávka, změna kontaktů, termíny odečtů nebo nový ceník služeb. V každém domě se zveřejní jako běžná aktualita (s přílohou v dokumentech).</p>
    <p><a href="<?= url('/sablony') ?>">Šablony textů firmy</a> – připravené texty pro oznámení a e-maily, které použijete tady i v administraci každého domu.</p></header>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if (!$houses): ?><p class="empty">Zatím nespravujete žádný dům.</p><?php else: ?>
  <form method="post" class="card form" enctype="multipart/form-data"><?= csrf_field() ?>
    <?php $tplList = $templates; $tplTitle = '[name=title]'; $tplBody = '[name=body]'; require __DIR__ . '/_tpl_pick.php'; ?>
    <label>Nadpis <input type="text" name="title" value="<?= e($in['title']) ?>" maxlength="200" required></label>
    <label>Text <textarea name="body" rows="8" required><?= e($in['body']) ?></textarea></label>
    <label>Příloha <small class="muted">(nepovinné – PDF, dokument nebo obrázek do 10 MB)</small> <input type="file" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.jpg,.jpeg,.png"></label>
    <fieldset class="check-list"><legend>Kdo ho uvidí</legend>
      <label class="check"><input type="radio" name="visibility" value="residents"<?= $in['visibility'] === 'residents' ? ' checked' : '' ?>> Jen přihlášení obyvatelé</label>
      <label class="check"><input type="radio" name="visibility" value="public"<?= $in['visibility'] === 'public' ? ' checked' : '' ?>> Všichni (veřejná část webu)</label></fieldset>
    <fieldset class="check-list"><legend>Domy</legend>
      <?php foreach ($houses as $h): ?><label class="check"><input type="checkbox" name="houses[]" value="<?= e($h['slug']) ?>"<?= in_array($h['slug'], $in['houses'], true) ? ' checked' : '' ?>> <?= e($h['name']) ?></label><?php endforeach ?></fieldset>
    <button class="btn btn-big" type="submit">Zveřejnit ve vybraných domech</button>
  </form>
  <?php endif ?>
</div>
