<?php /* Moje vyúčtování (vlastník): soubory, potvrzení převzetí, námitky. $items = modf_items_for_user(), $objections. */
$today = date('Y-m-d'); ?>
<div class="wrap page narrow">
  <header class="page-head"><h1>Moje vyúčtování</h1>
    <p class="lead">Vyúčtování služeb Vašich jednotek. Po přečtení prosím potvrďte převzetí. Nesouhlasíte-li s ním, můžete do 30 dnů od doručení podat námitku – výbor ji musí vyřídit do 30 dnů.</p></header>
  <?php if (!$items): ?><p class="empty"><?= (user()['kind'] ?? '') === 'owner' ? 'Zatím pro Vaše jednotky žádné vyúčtování není.' : 'Vyúčtování služeb dostávají vlastníci jednotek.' ?></p><?php endif ?>
  <?php foreach ($items as $it): $obs = $objections[(int) $it['id']] ?? []; $until = modf_objection_deadline($it['delivered_on']); $open = modf_objection_open($it); ?>
    <article class="card modf-my" id="v<?= (int) $it['id'] ?>">
      <h2><?= e($it['title']) ?> <small class="muted">· jednotka <?= e(unit_name($it)) ?></small></h2>
      <p class="muted">Období <?= cz_date($it['period_from']) ?> – <?= cz_date($it['period_to']) ?> · doručeno <?= cz_date($it['delivered_on']) ?></p>
      <p><a class="btn btn-small btn-ghost" href="<?= url('/vyuctovani/soubor/' . $it['id']) ?>" target="_blank" rel="noopener"><?= icon('doc') ?> Otevřít vyúčtování (PDF)</a></p>
      <?php if ($it['confirmed_at'] !== ''): ?>
        <p><span class="status status-done">Převzetí potvrzeno</span> <span class="muted"><?= e(cz_datetime($it['confirmed_at'])) ?><?= (int) $it['confirmed_by'] !== (int) user()['id'] ? ' (' . e($it['confirmed_name']) . ')' : '' ?></span></p>
      <?php else: ?>
        <form method="post" action="<?= url('/vyuctovani/' . $it['id'] . '/potvrdit') ?>"><?= csrf_field() ?><button class="btn" type="submit">Potvrzuji převzetí</button></form>
      <?php endif ?>
      <?php foreach ($obs as $o): ?>
        <div class="modf-objection">
          <p class="h-small">Vaše námitka z <?= e(cz_datetime($o['created_at'])) ?> <?= $o['answered_at'] !== '' ? '<span class="tag tag-ok">vyřízeno</span>' : '<span class="tag">výbor vyřídí do ' . cz_date(modf_answer_deadline(substr($o['created_at'], 0, 10))) . '</span>' ?></p>
          <div class="prose"><?= format_text($o['text']) ?></div>
          <?php if ($o['filename'] !== ''): ?><p><a href="<?= url('/vyuctovani/namitka/' . $o['id'] . '/priloha') ?>" target="_blank" rel="noopener">Příloha: <?= e($o['original_name']) ?></a></p><?php endif ?>
          <?php if ($o['answered_at'] !== ''): ?><div class="modf-answer"><p class="h-small">Odpověď výboru (<?= e(cz_datetime($o['answered_at'])) ?>)</p><div class="prose"><?= format_text($o['answer']) ?></div></div><?php endif ?>
        </div>
      <?php endforeach ?>
      <?php if ($open): ?>
        <details class="modf-objection-form"><summary>Námitka k vyúčtování (do <?= cz_date($until) ?>)</summary>
          <form method="post" action="<?= url('/vyuctovani/' . $it['id'] . '/namitka') ?>" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <label>S čím nesouhlasíte <textarea name="text" rows="5" required minlength="10" maxlength="5000" placeholder="Např. odečet vodoměru v kuchyni neodpovídá – fotografie je v příloze."></textarea></label>
            <label>Příloha (nepovinné) <input type="file" name="file" accept=".<?= implode(',.', ALLOWED_EXT) ?>"></label>
            <div class="actions"><button class="btn btn-small" type="submit">Podat námitku</button></div>
          </form></details>
      <?php elseif (!$obs): ?><p class="hint">Lhůta pro námitky skončila <?= cz_date($until) ?>.</p><?php endif ?>
    </article>
  <?php endforeach ?>
</div>
