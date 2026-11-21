<div class="wrap page">
  <header class="page-head"><h1>Dokumenty</h1><p class="lead">Stanovy, pozvánky, zápisy, účetní závěrky a formuláře společenství.</p></header>
  <form class="doc-filter" method="get" action="<?= url('/dokumenty') ?>">
    <?php if ($cat): ?><input type="hidden" name="kategorie" value="<?= e($cat) ?>"><?php endif ?>
    <label class="sr-only" for="q">Hledat v dokumentech</label>
    <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Hledat v dokumentech…">
    <button class="btn" type="submit">Hledat</button>
  </form>
  <nav class="chips" aria-label="Kategorie dokumentů">
    <a href="<?= url('/dokumenty') ?>"<?= $cat === '' ? ' aria-current="true"' : '' ?>>Vše</a>
    <?php foreach (CATEGORIES as $k => $label): ?>
      <a href="<?= url('/dokumenty?kategorie=' . $k) ?>"<?= $cat === $k ? ' aria-current="true"' : '' ?>><?= $label ?></a>
    <?php endforeach ?>
  </nav>
  <?php if (!$groups): ?><p class="empty">Žádný dokument neodpovídá výběru.</p><?php endif ?>
  <?php foreach (CATEGORIES as $k => $label): if (empty($groups[$k])) continue; ?>
    <section class="doc-group">
      <h2><?= $label ?> <span class="count"><?= count($groups[$k]) ?></span></h2>
      <?php if ($k === 'uzaverky'):
        $byYear = [];
        foreach ($groups[$k] as $d) { $byYear[$d['doc_date'] ? substr($d['doc_date'], 0, 4) : 'Ostatní'][] = $d; }
        foreach ($byYear as $year => $docs): ?>
          <h3 class="year">Rok <?= e($year) ?></h3>
          <?php require __DIR__ . '/_doclist.php'; endforeach;
      else: $docs = $groups[$k]; require __DIR__ . '/_doclist.php'; endif ?>
    </section>
  <?php endforeach ?>
  <?php if ($hidden): ?>
    <p class="note"><?= icon('lock') ?> Další dokumenty (<?= $hidden ?>) jsou dostupné jen přihlášeným obyvatelům domu. <a href="<?= url('/prihlaseni') ?>">Přihlásit se</a></p>
  <?php endif ?>
</div>
