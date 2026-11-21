<div class="wrap page narrow">
  <header class="page-head"><h1>Hlasování</h1><p class="lead">Hlasování vlastníků mimo zasedání a výsledky hlasování ze shromáždění.</p></header>
  <?php if (!$polls): ?><p class="empty">Zatím tu není žádné hlasování.</p><?php endif ?>
  <div class="post-list">
    <?php foreach ($polls as $p): $st = poll_state($p); ?>
      <article class="post-card">
        <p class="post-meta"><span class="status status-poll-<?= $st ?>"><?= POLL_STATES[$st] ?></span> <?= cz_date($p['opens_at']) ?> – <?= cz_date($p['closes_at']) ?></p>
        <h3><a href="<?= url('/hlasovani/' . $p['id']) ?>"><?= e($p['title']) ?></a></h3>
        <p><?= e(excerpt((string) $p['body'], 160)) ?></p>
      </article>
    <?php endforeach ?>
  </div>
</div>
