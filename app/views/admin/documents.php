<div class="wrap page">
  <header class="page-head row"><h1>Dokumenty</h1><span class="actions"><a class="btn btn-ghost" href="<?= url('/admin/dokumenty/zakladni') ?>">Základní dokumenty</a><a class="btn btn-ghost" href="<?= url('/admin/dokumenty/hromadne') ?>">Dokumenty pro jednotky</a><a class="btn" href="<?= url('/admin/dokumenty/novy') ?>">+ Nahrát dokument</a></span></header>
  <nav class="chips" aria-label="Kategorie">
    <a href="<?= url('/admin/dokumenty') ?>"<?= $cat === '' ? ' aria-current="true"' : '' ?>>Naposledy přidané</a>
    <?php foreach (CATEGORIES as $k => $label): ?><a href="<?= url('/admin/dokumenty?kategorie=' . $k) ?>"<?= $cat === $k ? ' aria-current="true"' : '' ?>><?= $label ?></a><?php endforeach ?>
  </nav>
  <table class="table">
    <thead><tr><th>Název</th><th>Kategorie</th><th>Datum</th><th>Viditelnost</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): ?>
      <tr>
        <td data-label="Název"><a href="<?= url('/admin/dokumenty/' . $d['id']) ?>"><strong><?= e($d['title']) ?></strong></a>
          <?php if (!$d['filename']): ?><span class="tag">na starém webu</span><?php endif ?></td>
        <td data-label="Kategorie"><?= CATEGORIES[$d['category']] ?? e($d['category']) ?></td>
        <td data-label="Datum"><?= cz_date($d['doc_date']) ?></td>
        <td data-label="Viditelnost"><?php if ($d['unit_id']): ?>Jen jednotka <?= e(unit_options()[$d['unit_id']] ?? '') ?><?php else: $vOther = $d['visibility'] === 'public' ? 'residents' : 'public'; ?>
          <form method="post" action="<?= url('/admin/dokumenty/' . $d['id'] . '/viditelnost') ?>" class="vis-toggle"><?= csrf_field() ?><input type="hidden" name="visibility" value="<?= $vOther ?>">
            <button type="submit" class="vis-btn vis-<?= e($d['visibility']) ?>" title="Kliknutím změníte na: <?= e(mb_strtolower(VISIBILITY[$vOther])) ?>"><?= $d['visibility'] === 'residents' ? icon('lock') . ' ' : '' ?><?= e(VISIBILITY[$d['visibility']]) ?> <span class="vis-swap" aria-hidden="true">⇄</span></button></form><?php endif ?></td>
        <td class="t-actions"><a href="<?= url('/admin/dokumenty/' . $d['id']) ?>">Upravit</a> <a href="<?= url('/soubor/' . $d['id']) ?>">Otevřít</a>
          <form method="post" action="<?= url('/admin/dokumenty/' . $d['id'] . '/smazat') ?>" data-confirm="Opravdu smazat dokument „<?= e($d['title']) ?>“?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
