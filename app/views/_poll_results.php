<?php /** @var array $results */ ?>
<div class="poll-results">
  <p class="hint">Hlasovalo jednotek: <strong><?= $results['voted'] ?></strong> z <?= count($results['units']) ?> (<?= percent_cz($results['turnout']) ?> <?= $results['byShare'] ? 'všech hlasů' : 'jednotek' ?>). Návrh je přijat, když je PRO více než <?= $results['threshold'] ?> % <?= $results['byShare'] ? 'všech hlasů v domě' : 'všech jednotek' ?>.</p>
  <?php foreach ($results['items'] as $n => $it): ?>
    <div class="quorum<?= $it['passed'] ? ' is-ok' : '' ?>">
      <p><strong><?= $n + 1 ?>. <?= e($it['question']) ?></strong></p>
      <div class="meter" role="img" aria-label="Pro <?= percent_cz($it['percent']['yes']) ?>"><span class="meter-fill" data-width="<?= round(min(100, $it['percent']['yes']), 1) ?>"></span><span class="meter-mark" data-left="<?= $results['threshold'] ?>"></span></div>
      <p>Pro <strong><?= percent_cz($it['percent']['yes']) ?></strong> (<?= $it['count']['yes'] ?>) · proti <?= percent_cz($it['percent']['no']) ?> (<?= $it['count']['no'] ?>) · zdrželo se <?= percent_cz($it['percent']['abstain']) ?> (<?= $it['count']['abstain'] ?>)
        — <strong><?= $it['passed'] ? 'přijato' : 'nepřijato' ?></strong></p>
    </div>
  <?php endforeach ?>
</div>
