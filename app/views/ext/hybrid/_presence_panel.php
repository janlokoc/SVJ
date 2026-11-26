<?php /* Modul H: panel na obrazovce Prezence na místě. $ev, $h, $p, $motions, $log, $live */
$hbase = '/admin/terminy/' . $ev['id'] . '/online'; ?>
<section class="card hybrid-panel" id="online" data-hybrid-admin-poll="<?= e(url($hbase . '/stav')) ?>">
  <h2>Účast a hlasování online</h2>
  <p class="hint"><?= e(HYBRID_FORMS[$h['form']]) ?> shromáždění · <?= e(hybrid_provider($h['video_url'])) ?> · <a href="<?= e($h['video_url']) ?>" target="_blank" rel="noopener noreferrer">otevřít videohovor</a>.
    Vlastníci se připojují na stránce termínu; prezence, podíly a výsledky se tady obnovují samy každé 4 sekundy <span data-hybrid-tick class="muted"></span>.</p>

  <?php $openMotions = array_filter($motions, fn($m) => $m['status'] !== 'closed'); ?>
  <?php if ($openMotions): ?>
  <h3>Hlasování online</h3>
  <p class="hint">Až návrh přednesete, pusťte ho k hlasování online – připojení vlastníci ho uvidí na webu a hlasují pro / proti / zdržel se. Kdo z nich nehlasuje, počítá se jako „zdržel se“. Hlasování online skončí, když návrh uzavřete.</p>
  <ul class="hybrid-live-list">
    <?php foreach ($openMotions as $m): $on = in_array((int) $m['id'], $live, true); ?>
      <li><span><?= e($m['title']) ?></span>
        <form method="post" action="<?= url($hbase) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="motion_id" value="<?= $m['id'] ?>">
          <?php if ($on): ?><span class="tag tag-ok">hlasuje se online</span> <button class="btn btn-small btn-ghost" name="do" value="live-off" type="submit">Zastavit</button>
          <?php else: ?><button class="btn btn-small" name="do" value="live-on" type="submit">Pustit k hlasování online</button><?php endif ?>
        </form></li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>

  <details<?= $log ? ' open' : '' ?>><summary>Připojení online (<?= count(array_filter($log, fn($a) => $a['left_at'] === '')) ?> právě připojeno)</summary>
    <?php if ($log): ?>
    <table class="table">
      <thead><tr><th>Jednotka</th><th>Kdo se připojil</th><th>Připojen</th><th>Odešel</th><th>Ověření</th><th>IP adresa</th></tr></thead>
      <tbody><?php foreach ($log as $a): ?>
        <tr><td data-label="Jednotka"><?= e(unit_name($a)) ?></td><td data-label="Kdo"><?= e($a['user_name'] ?? '') ?></td>
          <td data-label="Připojen"><?= e(substr($a['joined_at'], 11, 5)) ?></td><td data-label="Odešel"><?= $a['left_at'] !== '' ? e(substr($a['left_at'], 11, 5)) : '–' ?></td>
          <td data-label="Ověření"><?= $a['verified'] === 'email' ? 'přihlášení + kód z e-mailu' : 'přihlášení na web' ?></td><td data-label="IP"><small class="muted"><?= e($a['ip']) ?></small></td></tr>
      <?php endforeach ?></tbody>
    </table>
    <?php else: ?><p class="muted">Zatím se nikdo nepřipojil. Po obnovení stránky se tu objeví seznam.</p><?php endif ?>
  </details>
</section>
