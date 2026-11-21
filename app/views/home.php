<section class="hero">
  <div class="wrap hero-grid">
    <div class="hero-text">
      <p class="eyebrow">Společenství vlastníků jednotek · Brno</p>
      <h1>Tábor 48 <span class="h1-sub">a–d</span></h1>
      <p class="lead"><?= e(setting('intro')) ?></p>
      <div class="actions">
        <a class="btn btn-big" href="<?= url('/aktuality') ?>">Aktuální oznámení <?= icon('arrow') ?></a>
        <a class="btn btn-big btn-ghost" href="<?= url('/dokumenty') ?>">Dokumenty</a>
      </div>
    </div>
    <div class="hero-side">
      <svg class="house" viewBox="0 0 420 210" role="img" aria-label="Ilustrace domu se čtyřmi vchody a, b, c, d">
        <g class="house-body">
          <?php foreach (['a', 'b', 'c', 'd'] as $i => $l): $x = 10 + $i * 100; $top = $i % 2 ? 22 : 40; ?>
            <rect x="<?= $x ?>" y="<?= $top ?>" width="96" height="<?= 190 - $top ?>" rx="4"/>
          <?php endforeach ?>
        </g>
        <g class="house-win">
          <?php foreach ([0, 1, 2, 3] as $i): $x = 10 + $i * 100; $top = $i % 2 ? 22 : 40; for ($y = $top + 14; $y < 140; $y += 26): ?>
            <rect x="<?= $x + 12 ?>" y="<?= $y ?>" width="20" height="14" rx="2"/><rect x="<?= $x + 38 ?>" y="<?= $y ?>" width="20" height="14" rx="2"/><rect x="<?= $x + 64 ?>" y="<?= $y ?>" width="20" height="14" rx="2"/>
          <?php endfor; endforeach ?>
        </g>
        <g class="house-door">
          <?php foreach (['a', 'b', 'c', 'd'] as $i => $l): $x = 10 + $i * 100; ?>
            <rect x="<?= $x + 36 ?>" y="152" width="24" height="38" rx="3"/><text x="<?= $x + 48 ?>" y="178" text-anchor="middle"><?= $l ?></text>
          <?php endforeach ?>
        </g>
        <rect class="house-ground" x="0" y="190" width="420" height="4" rx="2"/>
      </svg>
      <?php if ($event): $t = strtotime($event['event_date']); ?>
      <div class="event-card">
        <div class="event-date"><span class="event-day"><?= date('j', $t) ?></span><span class="event-month"><?= MONTHS_SHORT[(int) date('n', $t)] ?> <?= date('Y', $t) ?></span></div>
        <div>
          <p class="eyebrow">Nejbližší termín</p>
          <strong class="event-title"><?= e($event['title']) ?></strong>
          <p class="event-meta"><?= e(implode(' · ', array_filter([WEEKDAYS[(int) date('w', $t)], $event['event_time'], $event['place']]))) ?></p>
          <?php if ($event['note']): ?><p class="event-note"><?= e($event['note']) ?></p><?php endif ?>
          <?php if ($event['rsvp']): ?><p class="event-rsvp"><a class="more" href="<?= url('/terminy/' . $event['id']) ?>">Dát vědět, zda přijdu <?= icon('arrow') ?></a></p><?php endif ?>
        </div>
      </div>
      <?php endif ?>
    </div>
  </div>
</section>

<section class="wrap home-grid">
  <div>
    <div class="section-head"><h2>Aktuality</h2><a class="more" href="<?= url('/aktuality') ?>">Všechny aktuality <?= icon('arrow') ?></a></div>
    <?php if (!$posts): ?><p class="empty">Zatím tu nejsou žádné aktuality.</p><?php endif ?>
    <div class="post-list">
      <?php foreach ($posts as $p): require __DIR__ . '/_postcard.php'; endforeach ?>
    </div>
  </div>
  <aside class="home-side">
    <div class="card card-alert">
      <h2><?= icon('alert') ?> Havárie</h2>
      <ul class="phone-list">
        <?php foreach ($emergency as $c): ?>
          <li><span><?= e($c['role']) ?><small><?= e($c['name']) ?></small></span><a href="<?= e(tel_href($c['phone'])) ?>"><?= e($c['phone']) ?></a></li>
        <?php endforeach ?>
      </ul>
      <a class="more" href="<?= url('/kontakty') ?>#havarie">Pravidla pro objednání servisu <?= icon('arrow') ?></a>
    </div>
    <div class="card">
      <h2>Rychlé odkazy</h2>
      <ul class="quick">
        <?php foreach (['formulare' => 'Formuláře a plné moci', 'zapisy' => 'Zápisy ze shromáždění', 'uzaverky' => 'Účetní závěrky', 'zakladni' => 'Stanovy a smlouvy'] as $k => $label): ?>
          <li><a href="<?= url('/dokumenty?kategorie=' . $k) ?>"><?= icon('doc') ?><span><?= $label ?></span><em><?= (int) ($counts[$k] ?? 0) ?></em></a></li>
        <?php endforeach ?>
      </ul>
    </div>
    <?php if (!user()): ?>
    <div class="card card-soft">
      <h2>Pro obyvatele domu</h2>
      <p>Po přihlášení uvidíte neveřejné dokumenty, můžete diskutovat se sousedy a nahlásit závadu výboru.</p>
      <a class="btn" href="<?= url('/prihlaseni') ?>"><?= icon('lock') ?> Přihlásit se</a>
    </div>
    <?php endif ?>
  </aside>
</section>
