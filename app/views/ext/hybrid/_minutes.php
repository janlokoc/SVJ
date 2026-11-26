<?php /* Modul H: forma shromáždění a online účast v zápisu. $ev, $h, $log */
$byUnit = [];
foreach ($log as $a) {
    $byUnit[unit_name($a)][] = $a;
} ?>
<h2 class="hybrid-minutes-h">Forma shromáždění</h2>
<p>Shromáždění se konalo jako <strong><?= e(mb_strtolower(HYBRID_FORMS[$h['form']] ?? HYBRID_FORMS['prezencni'])) ?></strong><?php if ($h['form'] !== 'prezencni' && $h['video_url'] !== ''): ?>;
  vlastníci se mohli účastnit a hlasovat technickými prostředky (<?= e(hybrid_provider($h['video_url'])) ?>), jak to umožňují stanovy. Vlastníci účastnící se online byli ověřeni přihlášením do webu společenství<?= setting('hybrid_email_code') === '1' ? ' a jednorázovým kódem zaslaným e-mailem' : '' ?> a hlasovali na webu<?php endif ?>.</p>
<?php if ($byUnit): ?>
<p>Online se účastnili vlastníci těchto jednotek (čas připojení – odchodu):</p>
<ul class="hybrid-minutes-list">
  <?php foreach ($byUnit as $unit => $rows): ?>
    <li><strong><?= e($unit) ?></strong> <?= e($rows[0]['owner_note'] ?: ($rows[0]['user_name'] ?? '')) ?> –
      <?= e(implode(', ', array_map(fn($a) => substr($a['joined_at'], 11, 5) . '–' . ($a['left_at'] !== '' ? substr($a['left_at'], 11, 5) : 'konec'), $rows))) ?></li>
  <?php endforeach ?>
</ul>
<?php elseif ($h['form'] !== 'prezencni'): ?>
<p>Online se nikdo neúčastnil.</p>
<?php endif ?>
