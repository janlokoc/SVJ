<main class="board">
  <header class="board-head">
    <div><p class="eyebrow"><?= e(site('tagline')) ?></p><h1><?= e(trim(site('title') . ' ' . site('accent'))) ?></h1></div>
    <p class="board-date"><?= weekday_name((int) date('w')) ?> <?= cz_date(date('Y-m-d'), true) ?></p>
  </header>
  <div class="board-grid">
    <section>
      <h2>Oznámení</h2>
      <?php if (!$posts): ?><p class="empty">Žádné aktuální oznámení.</p><?php endif ?>
      <?php foreach ($posts as $p): ?>
        <article class="board-post<?= $p['pinned'] ? ' is-pinned' : '' ?>"><p class="post-meta"><time><?= cz_date($p['published_at'], true) ?></time><?= $p['pinned'] ? ' <span class="tag tag-pin">důležité</span>' : '' ?></p>
          <h3><?= e($p['title']) ?></h3><p><?= e(mb_strimwidth(trim(preg_replace('/\s+/u', ' ', str_replace(['**', '## '], '', $p['body']))), 0, 260, '…')) ?></p></article>
      <?php endforeach ?>
    </section>
    <aside>
      <?php if ($events): ?><h2>Termíny</h2>
        <?php foreach ($events as $ev): $t = strtotime($ev['event_date']); ?>
          <div class="event-card"><div class="event-date"><span class="event-day"><?= date('j', $t) ?></span><span class="event-month"><?= month_name((int) date('n', $t), true) ?></span></div>
            <div><strong class="event-title"><?= e($ev['title']) ?></strong><p class="event-meta"><?= e(implode(' · ', array_filter([weekday_name((int) date('w', $t)), $ev['event_time'], $ev['place']]))) ?></p></div></div>
        <?php endforeach ?>
      <?php endif ?>
      <?php if ($phones): ?><h2>Havárie</h2><ul class="phone-list"><?php foreach ($phones as $c): ?><li><span><?= e($c['role'] !== '' ? $c['role'] : $c['name']) ?></span> <strong><?= e($c['phone']) ?></strong></li><?php endforeach ?></ul><?php endif ?>
      <div class="board-qr"><?= qr_svg(absolute_url('/'), 'QR kód webu') ?><p>Celý web společenství:<br><strong><?= e(preg_replace('~^https?://~', '', rtrim(absolute_url('/'), '/'))) ?></strong></p></div>
    </aside>
  </div>
  <p class="board-back no-print"><a href="<?= url('/') ?>">← Zpět na web</a></p>
</main>
