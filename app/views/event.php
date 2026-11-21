<?php $t = strtotime($ev['event_date']); ?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/') ?>">← Úvod</a></p>
  <p class="eyebrow"><?= WEEKDAYS[(int) date('w', $t)] ?> <?= cz_date($ev['event_date'], true) ?><?= $ev['event_time'] ? ' v ' . e($ev['event_time']) : '' ?></p>
  <h1><?= e($ev['title']) ?></h1>
  <p class="lead"><?= e(implode(' · ', array_filter([$ev['place'], $ev['note']]))) ?></p>

  <?php if ($ev['rsvp']): ?>
    <section class="rsvp">
      <h2>Přijdete?</h2>
      <?php if (!$mine): ?>
        <p class="note">Účast potvrzuje vlastník za svou jednotku. K Vašemu účtu zatím není přiřazena žádná jednotka, nebo nejste vedeni jako vlastník – obraťte se prosím na výbor.</p>
      <?php endif ?>
      <?php foreach ($mine as $un): ?>
        <form method="post" action="<?= url('/terminy/' . $ev['id'] . '/ucast') ?>" class="card rsvp-card">
          <?= csrf_field() ?><input type="hidden" name="unit_id" value="<?= $un['id'] ?>">
          <h3>Jednotka <?= e(unit_name($un)) ?></h3>
          <?php if ($un['status']): ?><p class="rsvp-current">Zaznamenáno: <strong><?= RSVP[$un['status']] ?></strong><?= $un['responder'] ? ' <small>(' . e($un['responder']) . ')</small>' : '' ?></p><?php endif ?>
          <?php if ($open): ?>
          <div class="actions">
            <?php foreach (RSVP as $k => $label): ?>
              <button class="btn<?= $un['status'] === $k ? '' : ' btn-ghost' ?>" type="submit" name="status" value="<?= $k ?>"><?= $label ?></button>
            <?php endforeach ?>
          </div>
          <?php endif ?>
        </form>
      <?php endforeach ?>
      <?php if ($mine && $open): ?><p class="hint">Za jednotku stačí jedna odpověď, kterou může kdykoli změnit kterýkoli z jejích vlastníků. Pro zastoupení je potřeba plná moc – vzor najdete v <a href="<?= url('/dokumenty?kategorie=formulare') ?>">Dokumentech</a>.</p><?php endif ?>

      <?php if ($att['units']): ?>
      <div class="quorum<?= $att['quorate'] ? ' is-ok' : '' ?>">
        <p class="eyebrow">Zatím přihlášeno</p>
        <p class="quorum-num"><?= percent_cz($att['percent']) ?> <small><?= $att['byShare'] ? 'všech hlasů' : 'jednotek' ?></small></p>
        <div class="meter" role="img" aria-label="Přihlášeno <?= percent_cz($att['percent']) ?>, potřeba více než <?= $att['threshold'] ?> %"><span class="meter-fill" data-width="<?= round(min(100, $att['percent']), 1) ?>"></span><span class="meter-mark" data-left="<?= $att['threshold'] ?>"></span></div>
        <p><?= $att['quorate'] ? 'Podle přihlášek bude shromáždění usnášeníschopné.' : 'Aby bylo shromáždění usnášeníschopné, je potřeba více než ' . $att['threshold'] . ' %.' ?></p>
      </div>
      <?php endif ?>
    </section>
  <?php endif ?>
  <?php if (is_admin()): ?><p><a class="btn btn-small btn-ghost" href="<?= url('/admin/terminy/' . $ev['id'] . '/ucast') ?>">Přehled účasti pro výbor</a></p><?php endif ?>
</div>
