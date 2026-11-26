<?php /* Zprávy u hlášení závady. $t = hlášení, $history = záznamy ticket_log, $forStaff = zobrazuje správce (jinak autor). */
$msgs = array_values(array_filter($history, fn($r) => in_array($r['kind'], ['msg_admin', 'msg_author'], true))); ?>
<?php if ($t['admin_note'] !== '' || $msgs): ?>
<ol class="report-thread">
  <?php if ($t['admin_note'] !== ''): ?><li class="report-msg report-msg-staff"><p class="report-msg-head"><strong>Odpověď výboru</strong></p><div class="prose"><?= format_text($t['admin_note']) ?></div></li><?php endif ?>
  <?php foreach ($msgs as $m): $staff = $m['kind'] === 'msg_admin'; ?>
  <li class="report-msg <?= $staff ? 'report-msg-staff' : 'report-msg-user' ?>">
    <p class="report-msg-head"><strong><?= $staff ? 'Odpověď' . ($m['user_name'] !== '' ? ' – ' . e($m['user_name']) : '') : ($forStaff ? 'Odpověď obyvatele' : 'Vaše odpověď') ?></strong> <small class="muted"><?= cz_datetime($m['at']) ?></small></p>
    <div class="prose"><?= format_text($m['text']) ?></div>
  </li>
  <?php endforeach ?>
</ol>
<?php endif ?>
