<div class="wrap page narrow">
  <header class="page-head"><h1>Ankety</h1><p class="lead">Rychlé zjištění názoru obyvatel. Ankety nejsou závazné hlasování – to najdete v části Hlasování.</p></header>
  <?php if (!$surveys): ?><p class="empty">Teď tu žádná anketa není.</p><?php endif ?>
  <div class="ticket-list">
  <?php foreach ($surveys as $s): $showResults = $s['mine'] || !$s['open']; ?>
    <article class="ticket" id="a<?= $s['id'] ?>">
      <header><h2 class="survey-title"><?= e($s['title']) ?></h2><span class="status status-<?= $s['open'] ? 'done' : 'rsvp-no' ?>"><?= $s['open'] ? 'probíhá' : 'ukončena' ?></span></header>
      <p class="post-meta"><?= $s['open'] && $s['closes_at'] !== '' ? 'do ' . cz_date($s['closes_at']) . ' · ' : '' ?>odpovědí: <?= (int) $s['total'] ?><?= $s['anonymous'] ? ' · anonymní' : ' · výbor vidí, kdo jak odpověděl' ?></p>
      <?php if ($s['body'] !== ''): ?><div class="prose" translate="no"><?= format_text((string) $s['body']) ?></div><?php endif ?>
      <?php if ($s['open']): ?>
      <form method="post" action="<?= url('/ankety/' . $s['id']) ?>" class="form survey-form">
        <?= csrf_field() ?>
        <?php foreach ($s['options'] as $o): ?><label class="check" translate="no"><input type="radio" name="option" value="<?= $o['id'] ?>"<?= $s['mine'] === (int) $o['id'] ? ' checked' : '' ?> required> <span><?= e($o['label']) ?></span></label><?php endforeach ?>
        <div class="actions"><button class="btn btn-small" type="submit"><?= $s['mine'] ? 'Změnit odpověď' : 'Odeslat odpověď' ?></button></div>
      </form>
      <?php endif ?>
      <?php if ($showResults): ?>
      <div class="poll-results">
        <?php foreach ($s['options'] as $o): $pct = $s['total'] ? (int) round($o['votes'] / $s['total'] * 100) : 0; ?>
          <div class="survey-row"><span translate="no"><?= e($o['label']) ?><?= $s['mine'] === (int) $o['id'] ? ' ✓' : '' ?></span><span class="meter"><span class="meter-fill" data-width="<?= $pct ?>"></span></span><strong><?= $pct ?> % <small>(<?= (int) $o['votes'] ?>)</small></strong></div>
        <?php endforeach ?>
      </div>
      <?php elseif ($s['open']): ?><p class="hint">Výsledky uvidíte po odeslání odpovědi.</p><?php endif ?>
    </article>
  <?php endforeach ?>
  </div>
</div>
