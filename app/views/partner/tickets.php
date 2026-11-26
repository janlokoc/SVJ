<div class="wrap page">
  <header class="page-head"><h1>Hlášení z domů</h1>
    <p class="lead">Závady a požadavky obyvatel ze všech domů, které spravujete. Vyřizují se ve webu domu – tlačítko Otevřít Vás tam přihlásí rovnou k hlášení. Nová hlášení chodí e-mailem (nastavíte v <a href="<?= url('/ucet') ?>">Můj účet</a>).</p></header>
  <form method="get" class="filter-row">
    <label>Dům <select name="dum" data-autosubmit><option value="">Všechny domy</option><?php foreach ($houses as $h): ?><option value="<?= e($h['slug']) ?>"<?= $house === $h['slug'] ? ' selected' : '' ?>><?= e($h['name']) ?></option><?php endforeach ?></select></label>
    <input type="hidden" name="f" value="<?= e($filter) ?>"><button class="btn btn-small btn-ghost" type="submit">Zobrazit</button>
  </form>
  <nav class="chips" aria-label="Filtr hlášení">
    <?php foreach (['otevrene' => 'Otevřená', 'moje' => 'Přiřazená mně', 'po-terminu' => 'Po termínu', 'vse' => 'Všechna'] as $k => $label): ?>
      <a href="<?= url('/hlaseni?' . http_build_query(array_filter(['f' => $k !== 'otevrene' ? $k : '', 'dum' => $house]))) ?>"<?= $filter === $k ? ' aria-current="page"' : '' ?>><?= $label ?> <small>(<?= $counts[$k] ?>)</small></a>
    <?php endforeach ?>
  </nav>
  <?php if (!$houses): ?><p class="empty">Zatím nespravujete žádný dům.</p>
  <?php elseif (!$rows): ?><p class="empty">V tomto výběru nic není.</p>
  <?php else: ?>
  <table class="table"><thead><tr><th>Hlášení</th><th>Dům</th><th>Stav</th><th>Řeší</th><th>Termín</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr<?= $r['late'] ? ' class="is-unmatched"' : '' ?>>
      <td data-label="Hlášení"><strong><?= e($r['subject']) ?></strong><br><small class="muted"><?= cz_datetime($r['created_at']) ?><?= $r['location'] !== '' ? ' · ' . e($r['location']) : '' ?><?= $r['author_name'] ? ' · ' . e($r['author_name']) : '' ?></small></td>
      <td data-label="Dům"><?= e($r['house']) ?></td>
      <td data-label="Stav"><span class="status status-<?= e($r['status']) ?>"><?= TICKET_STATUS[$r['status']] ?? e($r['status']) ?></span></td>
      <td data-label="Řeší"><?= $r['assignee_name'] ? e($r['assignee_name']) : '<span class="muted">—</span>' ?></td>
      <td data-label="Termín"><?= $r['due_date'] !== '' ? cz_date($r['due_date']) . ($r['late'] ? ' <span class="tag tag-late">po termínu</span>' : '') : '<span class="muted">—</span>' ?></td>
      <td class="t-actions"><a class="btn btn-small" href="<?= url('/domy/' . $r['slug'] . '/vstoupit?zavada=' . (int) $r['id']) ?>">Otevřít</a></td></tr>
    <?php endforeach ?>
  </tbody></table>
  <?php endif ?>
</div>
