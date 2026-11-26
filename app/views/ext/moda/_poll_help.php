<?php /* Výsledky hlasování (správce): kolik chybí k přijetí a připomenutí nehlasujícím. $poll, $state, $results. Vkládá app/views/admin/poll_results.php. */
if (!function_exists('moda_poll_gap') || !$results['units']) {
    return;
}
$gap = moda_poll_gap($results);
$nonvoters = $state === 'open' && (int) $poll['online'] ? moda_poll_nonvoters((int) $poll['id']) : [];
$lastRemind = moda_poll_last_reminder((int) $poll['id']);
$unitWord = fn(int $n) => $n === 1 ? 'jednotky' : 'jednotek'; ?>
<section class="card poll-gap" id="chybi">
  <h2>Co chybí k přijetí</h2>
  <ul class="poll-gap-list">
    <?php foreach ($results['items'] as $n => $it): $g = $gap[(int) $it['id']]; ?>
      <li><strong><?= $n + 1 ?>.</strong>
        <?php if ($g['passed']): ?><span class="status status-done">přijato</span> hlasy PRO už přesahují <?= $results['threshold'] ?> %.
        <?php elseif (!$g['reachable']): ?><span class="status status-new">nelze přijmout</span> chybí <?= percent_cz($g['missing']) ?>, ale nehlasující mají dohromady jen <?= percent_cz($g['open']) ?>.
        <?php else: ?>chybí <strong><?= percent_cz($g['missing']) ?></strong> <?= $results['byShare'] ? 'všech hlasů (podílů)' : 'jednotek' ?> a alespoň jeden další hlas PRO<?= $g['units'] ? ' – stačí hlasy PRO ' . $g['units'] . ' ' . $unitWord($g['units']) . ($results['byShare'] ? ' s největšími podíly' : '') : '' ?>. Nehlasovalo ještě <?= percent_cz($g['open']) ?>.
        <?php endif ?></li>
    <?php endforeach ?>
  </ul>
  <?php if ($state === 'open' && (int) $poll['online']): ?>
    <form method="post" action="<?= url('/admin/hlasovani/' . $poll['id'] . '/pripomenout') ?>" class="inline-form" data-confirm="Poslat připomenutí <?= count($nonvoters) ?> vlastníkům, kteří ještě nehlasovali?">
      <?= csrf_field() ?>
      <button class="btn btn-small" type="submit"<?= !$nonvoters || $lastRemind > time() - POLL_REMIND_GAP ? ' disabled' : '' ?>>Připomenout nehlasujícím (<?= count($nonvoters) ?>)</button>
    </form>
    <p class="hint">Pošle e-mail (a upozornění do telefonu, pokud ho mají zapnuté) vlastníkům jednotek, za které ještě nikdo nehlasoval. Nejvýš jednou za 24 hodin.<?= $lastRemind ? ' Naposledy: ' . e(cz_datetime(date('Y-m-d H:i:s', $lastRemind))) . '.' : '' ?></p>
  <?php endif ?>
</section>
