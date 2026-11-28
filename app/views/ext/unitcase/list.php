<?php /* Závady v bytě – přehled: $units (unitcase_my_units), $cases, $side ('tenant'|'owner'). */
$canWrite = (bool) array_filter($units, fn($x) => $x['other'] > 0);
$names = array_column(array_map(fn($k, $v) => ['k' => $k, 'n' => $v['name']], array_keys($units), $units), 'n', 'k'); ?>
<div class="wrap page narrow">
  <header class="page-head row"><h1>Závady v bytě</h1>
    <?php if ($canWrite): ?><a class="btn" href="<?= url('/byt/novy') ?>"><?= $side === 'tenant' ? 'Napsat vlastníkovi' : 'Napsat nájemníkovi' ?></a><?php endif ?></header>
  <?php if ($side === 'tenant'): ?>
  <p class="lead">Závadu uvnitř bytu (spotřebič, vybavení, okna, kohoutky…) nebo jinou věc k nájmu napište tady vlastníkovi bytu. Dostane e-mail a upozornění; výbor ani správa domu zprávy nevidí.</p>
  <p class="hint">Závada ve společných prostorách domu (výtah, světlo na chodbě, topení v domě) patří výboru: <a href="<?= url('/zavady') ?>">Závady a požadavky</a>. Při havárii volejte rovnou <a href="<?= url('/kontakty') ?>#havarie">havarijní servis</a>.</p>
  <?php else: ?>
  <p class="lead">Zprávy a hlášení závad od nájemníků Vašeho bytu. Výbor ani správa domu je nevidí.</p>
  <?php endif ?>
  <?php foreach ($units as $unitId => $x): if ($x['other'] === 0): ?>
    <p class="callout"><?php if ($side === 'tenant'): ?>Vlastník bytu <?= e($x['name']) ?> zatím nemá na webu účet, proto mu tady napsat nejde. Požádejte ho, ať se na web přihlásí (pozvánku mu může poslat výbor).<?php else: ?>U bytu <?= e($x['name']) ?> teď není přihlášený žádný nájemník.<?php endif ?></p>
  <?php endif; endforeach ?>
  <?php if (!$cases): ?>
    <p class="empty">Zatím tu nic není.</p>
  <?php else: ?>
  <ul class="ucase-list">
    <?php foreach ($cases as $c): ?>
    <li><a href="<?= url('/byt/' . $c['id']) ?>" class="ucase-row<?= $c['unread'] ? ' is-unread' : '' ?>">
      <span class="ucase-subj"><strong translate="no"><?= e($c['subject']) ?></strong>
        <small class="muted"><?= e(UNITCASE_KINDS[$c['kind']] ?? '') ?> · <span translate="no"><?= e($names[$c['unit_id']] ?? '') ?></span> · <?= e(cz_datetime($c['updated_at'])) ?></small></span>
      <?php if ($c['kind'] === 'zavada'): ?><span class="status status-<?= e($c['status']) ?>"><?= e(UNITCASE_STATUS[$c['status']] ?? '') ?></span><?php endif ?>
      <?php if ($c['unread']): ?><span class="nav-count"><?= (int) $c['unread'] ?></span><?php endif ?>
    </a></li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</div>
