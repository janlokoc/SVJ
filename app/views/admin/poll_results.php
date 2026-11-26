<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/admin/hlasovani') ?>">← Hlasování</a></p>
  <header class="page-head"><h1><?= e($poll['title']) ?></h1>
    <p class="lead"><span class="status status-poll-<?= $state ?>"><?= POLL_STATES[$state] ?></span> <?= cz_date($poll['opens_at']) ?> – <?= cz_date($poll['closes_at']) ?></p></header>
  <?php if (!$results['units']): ?><p class="empty">Nejdřív založte jednotky – hlasuje se po jednotkách.</p><?php else: ?>
  <?php if (!$results['byShare']): ?><div class="flash flash-info">Některé jednotky nemají vyplněný podíl, proto má zatím každá jednotka jeden hlas.</div><?php endif ?>
  <?php require __DIR__ . '/../_poll_results.php'; ?>
  <?php if (function_exists('rollam_method')) { require __DIR__ . '/../ext/rollam/_results_box.php'; } ?>
  <h2>Hlasy po jednotkách</h2>
  <p class="hint">Zde zapíšete hlas vlastníka, který hlasoval na papíře nebo na shromáždění, případně hlas opravíte. Každý zápis se ukládá do záznamů.</p>
  <table class="table">
    <thead><tr><th>Jednotka</th><th>Vlastník dle evidence</th><th>Podíl</th><th>Hlasy (podle pořadí bodů)</th><th>Zapsal(a)</th></tr></thead>
    <tbody>
    <?php foreach ($results['units'] as $un): $who = ''; ?>
      <tr id="j<?= $un['id'] ?>">
        <td data-label="Jednotka"><strong><?= e(unit_name($un)) ?></strong></td><td data-label="Vlastník dle evidence"><?= e($un['owner_note']) ?></td><td data-label="Podíl"><?= e($un['share']) ?></td>
        <td data-label="Hlasy"><form method="post" action="<?= url('/admin/hlasovani/' . $poll['id'] . '/vysledky') ?>" class="inline-form vote-form">
          <?= csrf_field() ?><input type="hidden" name="unit_id" value="<?= $un['id'] ?>">
          <?php foreach ($results['items'] as $n => $it): $v = $results['votes'][$it['id']][$un['id']] ?? null; if ($v) { $who = ($v['voter'] ?? '') . ' ' . cz_datetime($v['voted_at']); } ?>
            <label class="sr-only" for="v<?= $un['id'] ?>-<?= $it['id'] ?>">Bod <?= $n + 1 ?></label>
            <select name="choice[<?= $it['id'] ?>]" id="v<?= $un['id'] ?>-<?= $it['id'] ?>" title="<?= e($it['question']) ?>"><option value=""><?= $n + 1 ?>. —</option><?php foreach (CHOICES as $k => $label): ?><option value="<?= $k ?>"<?= ($v['choice'] ?? '') === $k ? ' selected' : '' ?>><?= $n + 1 ?>. <?= $label ?></option><?php endforeach ?></select>
          <?php endforeach ?>
          <button class="btn btn-small btn-ghost" type="submit">Zapsat</button></form></td>
        <td data-label="Zapsal(a)"><small><?= e(trim($who)) ?></small></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
