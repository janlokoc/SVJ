<?php /* Vlákno zpráv k hlášení. $thread = zprávy, $imgBase = adresa snímků zpráv (+ id zprávy), $forStaff = zobrazuje vyřizující (jinak autor). */ ?>
<?php if (!empty($thread)): ?>
<ol class="report-thread">
  <?php foreach ($thread as $m): $staff = $m['author'] === 'staff'; ?>
  <li class="report-msg <?= $staff ? 'report-msg-staff' : 'report-msg-user' ?>">
    <p class="report-msg-head"><strong><?= $staff ? ($forStaff ? 'Odpověď' . ($m['by_name'] !== '' ? ' – ' . e($m['by_name']) : '') : 'Odpověď') : ($forStaff ? 'Doplnil autor' : 'Vaše doplnění') ?></strong>
      <small class="muted"><?= cz_datetime($m['created_at']) ?></small></p>
    <div class="prose" translate="no"><?= format_text($m['body']) ?></div>
    <?php if ($m['screenshot'] !== ''): ?><p><a href="<?= url($imgBase . $m['id']) ?>" target="_blank" rel="noopener" data-zoom><img class="report-img" src="<?= url($imgBase . $m['id']) ?>" alt="Přiložený snímek" loading="lazy"></a></p><?php endif ?>
  </li>
  <?php endforeach ?>
</ol>
<?php endif ?>
