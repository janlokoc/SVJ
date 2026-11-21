<?php
$sent = count(array_filter($recipients, fn($r) => $r['sent_ok']));
$opened = count(array_filter($recipients, fn($r) => $r['opened_at']));
$clicked = count(array_filter($recipients, fn($r) => $r['clicked_at']));
?>
<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/admin/zpravy') ?>">← Zprávy e-mailem</a></p>
  <header class="page-head"><h1><?= e($m['subject']) ?></h1>
    <p class="lead">Odesláno <?= cz_datetime($m['sent_at']) ?> · <?= $m['audience'] === 'owners' ? 'vlastníkům' : 'všem obyvatelům' ?><?= $m['author_name'] ? ' · ' . e($m['author_name']) : '' ?></p></header>
  <div class="stat-grid">
    <div class="stat"><span class="stat-num"><?= $sent ?></span><span>předáno k odeslání</span></div>
    <?php if ($m['tracked']): ?>
    <div class="stat"><span class="stat-num"><?= $opened ?></span><span>otevřelo</span></div>
    <div class="stat"><span class="stat-num"><?= $clicked ?></span><span>kliklo na odkaz</span></div>
    <?php endif ?>
    <div class="stat<?= $m['failed_count'] ? ' stat-alert' : '' ?>"><span class="stat-num"><?= (int) $m['failed_count'] ?></span><span>nepodařilo se</span></div>
  </div>
  <?php if ($m['tracked']): ?>
    <p class="hint">Proklik je spolehlivý údaj. Otevření je jen orientační: řada poštovních programů obrázky blokuje (pak se otevření nezapíše), jiné je načítají samy předem (pak se zapíše, i když zprávu nikdo nečetl).</p>
  <?php else: ?>
    <p class="hint">Tato zpráva byla odeslána bez sledování otevření a prokliků.</p>
  <?php endif ?>
  <details class="card"><summary><strong>Text zprávy</strong></summary><div class="prose mail-body"><?= format_text($m['body']) ?></div></details>
  <?php if (!$recipients): ?><p class="empty">U této zprávy není seznam příjemců k dispozici (byla odeslána před zavedením evidence).</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Příjemce</th><th>Jednotka</th><th>Odeslání</th><?php if ($m['tracked']): ?><th>Otevřeno</th><th>Proklik</th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($recipients as $r): ?>
      <tr>
        <td data-label="Příjemce"><strong><?= e($r['name']) ?></strong><br><small class="muted"><?= e($r['email']) ?></small></td>
        <td data-label="Jednotka"><?= e($r['unit_names'] ?? '') ?></td>
        <td data-label="Odeslání"><?= $r['sent_ok'] ? 'ano' : '<span class="status status-new">selhalo</span>' ?></td>
        <?php if ($m['tracked']): ?>
        <td data-label="Otevřeno"><?= $r['opened_at'] ? cz_datetime($r['opened_at']) : '<span class="muted">—</span>' ?></td>
        <td data-label="Proklik"><?= $r['clicked_at'] ? '<span class="status status-done">' . cz_datetime($r['clicked_at']) . '</span>' : '<span class="muted">—</span>' ?></td>
        <?php endif ?>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
