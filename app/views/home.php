<section class="hero">
  <div class="wrap hero-grid">
    <div class="hero-text">
      <p class="eyebrow"><?= e(site('tagline')) ?></p>
      <h1><?= e(site('title')) ?><?php if (site('accent') !== ''): ?> <span class="h1-sub"><?= e(site('accent')) ?></span><?php endif ?></h1>
      <p class="lead"><?= e(setting('intro')) ?></p>
      <div class="actions">
        <a class="btn btn-big" href="<?= url('/aktuality') ?>">Aktuální oznámení <?= icon('arrow') ?></a>
        <a class="btn btn-big btn-ghost" href="<?= url('/dokumenty') ?>">Dokumenty</a>
      </div>
    </div>
    <div class="hero-side">
      <?php $doors = array_slice(array_values(array_filter(entrances())), 0, 6) ?: ['']; $hw = count($doors) * 100 + 20; ?>
      <svg class="house" viewBox="0 0 <?= $hw ?> 210" role="img" aria-label="Ilustrace domu">
        <g class="house-body">
          <?php foreach ($doors as $i => $l): $x = 10 + $i * 100; $top = $i % 2 ? 22 : 40; ?>
            <rect x="<?= $x ?>" y="<?= $top ?>" width="96" height="<?= 190 - $top ?>" rx="4"/>
          <?php endforeach ?>
        </g>
        <g class="house-win">
          <?php foreach (array_keys($doors) as $i): $x = 10 + $i * 100; $top = $i % 2 ? 22 : 40; for ($y = $top + 14; $y < 140; $y += 26): ?>
            <rect x="<?= $x + 12 ?>" y="<?= $y ?>" width="20" height="14" rx="2"/><rect x="<?= $x + 38 ?>" y="<?= $y ?>" width="20" height="14" rx="2"/><rect x="<?= $x + 64 ?>" y="<?= $y ?>" width="20" height="14" rx="2"/>
          <?php endfor; endforeach ?>
        </g>
        <g class="house-door">
          <?php foreach ($doors as $i => $l): $x = 10 + $i * 100; ?>
            <rect x="<?= $x + 36 ?>" y="152" width="24" height="38" rx="3"/><text x="<?= $x + 48 ?>" y="178" text-anchor="middle"><?= mb_strlen($l) <= 2 ? e(mb_strtolower($l)) : '' ?></text>
          <?php endforeach ?>
        </g>
        <rect class="house-ground" x="0" y="190" width="<?= $hw ?>" height="4" rx="2"/>
      </svg>
      <?php if ($event): $t = strtotime($event['event_date']); ?>
      <div class="event-card">
        <div class="event-date"><span class="event-day"><?= date('j', $t) ?></span><span class="event-month"><?= month_name((int) date('n', $t), true) ?> <?= date('Y', $t) ?></span></div>
        <div>
          <p class="eyebrow">Nejbližší termín</p>
          <strong class="event-title"><?= e($event['title']) ?></strong>
          <p class="event-meta"><?= e(implode(' · ', array_filter([weekday_name((int) date('w', $t)), $event['event_time'], $event['place']]))) ?></p>
          <?php if ($event['note']): ?><p class="event-note"><?= e($event['note']) ?></p><?php endif ?>
          <p class="event-rsvp"><a class="more" href="<?= url('/terminy/' . $event['id'] . '.ics') ?>">Přidat do kalendáře</a></p>
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
      <p>Po přihlášení uvidíte neveřejné dokumenty, můžete diskutovat se sousedy a poslat výboru závadu nebo požadavek.</p>
      <a class="btn" href="<?= url('/prihlaseni') ?>"><?= icon('lock') ?> Přihlásit se</a>
    </div>
    <?php endif ?>
  </aside>
</section>
