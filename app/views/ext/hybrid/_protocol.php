<?php /* Modul H: příloha prezenční listiny – online účast a hlasy odevzdané online. $ev, $log, $votes */ ?>
<section class="hybrid-protocol">
  <?php if ($log): ?>
  <h2>Účast online</h2>
  <table class="table print-table">
    <thead><tr><th>Jednotka</th><th>Připojil(a) se</th><th>Připojení</th><th>Odchod</th><th>Ověření</th></tr></thead>
    <tbody><?php foreach ($log as $a): ?>
      <tr><td><strong><?= e(unit_name($a)) ?></strong></td><td><?= e($a['user_name'] ?? '') ?></td><td><?= e(substr($a['joined_at'], 11, 5)) ?></td>
        <td><?= $a['left_at'] !== '' ? e(substr($a['left_at'], 11, 5)) : '–' ?></td><td><?= $a['verified'] === 'email' ? 'přihlášení + kód z e-mailu' : 'přihlášení na web' ?></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif ?>
  <?php if ($votes): ?>
  <h2>Hlasy odevzdané online</h2>
  <table class="table print-table">
    <thead><tr><th>Návrh</th><th>Jednotka</th><th>Hlas</th><th>Čas</th></tr></thead>
    <tbody><?php foreach ($votes as $v): ?>
      <tr><td><?= e($v['title']) ?></td><td><?= e(unit_name($v)) ?></td><td><?= e(MOTION_CHOICES[$v['choice']] ?? $v['choice']) ?></td><td><?= e(substr((string) $v['voted_at'], 11, 8)) ?></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <p class="hint">Hlasy jednotek přítomných online, které nehlasovaly, se počítají jako „zdržel se“.</p>
  <?php endif ?>
</section>
