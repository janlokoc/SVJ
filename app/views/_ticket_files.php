<?php /** Přílohy jedné závady: $ticketFiles (řádky ticket_files). Obrázky jako náhledy, ostatní jako odkazy. */
if (!empty($ticketFiles)): ?>
<ul class="ticket-files">
  <?php foreach ($ticketFiles as $tf): $href = url('/zavady/priloha/' . $tf['id']); $isImg = (bool) preg_match('/\.(jpe?g|png)$/i', $tf['filename']); ?>
  <li><a href="<?= $href ?>" target="_blank" rel="noopener"><?php if ($isImg): ?><img src="<?= $href ?>" alt="Příloha: <?= e($tf['original_name']) ?>" loading="lazy"><?php else: ?><?= icon('doc') ?> <?= e($tf['original_name']) ?><?php endif ?></a></li>
  <?php endforeach ?>
</ul>
<?php endif ?>
