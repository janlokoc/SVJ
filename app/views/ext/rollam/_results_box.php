<?php /* Modul B: ověření hlasu, stav protokolu a odkazy (admin/poll_results.php). */ $rollamChk = rollam_chain_check((int) $poll['id']); ?>
<div class="card">
  <p><strong>Ověření hlasu:</strong> <?= e(ROLLAM_VERIFY[rollam_method((int) $poll['id'])]) ?> ·
    <strong>Protokol:</strong> <?= $rollamChk['count'] ?> záznamů,
    <?php if ($rollamChk['ok']): ?><span class="status status-done">otisky v pořádku</span>
    <?php else: ?><span class="status status-new"><?= $rollamChk['bad'] ? 'porušený řetěz otisků' : 'hlasy bez záznamu: ' . count($rollamChk['mismatch']) ?></span><?php endif ?></p>
  <p class="actions"><a class="btn btn-small" href="<?= url('/admin/hlasovani/' . $poll['id'] . '/protokol') ?>">Protokol o hlasování</a>
    <a class="btn btn-small btn-ghost" href="<?= url('/admin/hlasovani/' . $poll['id'] . '/listky') ?>">Hlasovací lístky k tisku</a>
    <a class="btn btn-small btn-ghost" href="<?= url('/admin/hlasovani/' . $poll['id'] . '/listina') ?>">Zapsat listinný hlas</a>
    <?= function_exists('help_link') ? help_link('hlasovani-per-rollam') : '' ?></p>
</div>
