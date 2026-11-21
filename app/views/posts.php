<div class="wrap page narrow">
  <header class="page-head"><h1>Aktuality</h1><p class="lead">Oznámení výboru, pozvánky na shromáždění a informace o dění v domě.</p></header>
  <?php if (!$posts): ?><p class="empty">Zatím tu nejsou žádné aktuality.</p><?php endif ?>
  <div class="post-list">
    <?php foreach ($posts as $p): require __DIR__ . '/_postcard.php'; endforeach ?>
  </div>
</div>
