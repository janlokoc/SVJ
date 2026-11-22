<div class="wrap page narrow">
  <header class="page-head"><h1>Termíny</h1>
    <p class="lead">Shromáždění, odečty, revize a další akce v domě. Každý termín si můžete uložit do svého kalendáře.</p></header>
  <?php if (!$upcoming): ?><p class="empty">Žádný termín teď není naplánován.</p><?php endif ?>
  <div class="ticket-list">
    <?php foreach ($upcoming as $ev): $t = strtotime($ev['event_date']); ?>
    <article class="ticket event-row">
      <div class="event-date"><span class="event-day"><?= date('j', $t) ?></span><span class="event-month"><?= month_name((int) date('n', $t), true) ?> <?= date('Y', $t) ?></span></div>
      <div>
        <h2 class="event-title"><a href="<?= url('/terminy/' . $ev['id']) ?>"><?= e($ev['title']) ?></a></h2>
        <p class="event-meta"><?= e(implode(' · ', array_filter([weekday_name((int) date('w', $t)), $ev['event_time'], $ev['place']]))) ?></p>
        <?php if ($ev['note']): ?><p class="event-note"><?= e($ev['note']) ?></p><?php endif ?>
        <p class="actions cal-actions"><a class="btn btn-small" href="<?= url('/terminy/' . $ev['id'] . '.ics') ?>">Přidat do kalendáře</a>
          <a class="btn btn-small btn-ghost" href="<?= e(google_calendar_url($ev)) ?>" target="_blank" rel="noopener noreferrer">Google Kalendář</a>
          <?php if ($ev['rsvp']): ?><a class="more" href="<?= url('/terminy/' . $ev['id']) ?>">Dát vědět, zda přijdu <?= icon('arrow') ?></a><?php endif ?></p>
      </div>
    </article>
    <?php endforeach ?>
  </div>
  <div class="card card-soft cal-feed">
    <h2>Odebírat všechny termíny</h2>
    <p>Přidejte si kalendář domu do telefonu nebo počítače jednou a nové termíny se v něm budou objevovat samy.</p>
    <p class="actions"><a class="btn btn-small" href="<?= e(preg_replace('~^https?://~', 'webcal://', $feed)) ?>">Přidat do kalendáře v zařízení</a>
      <a class="btn btn-small btn-ghost" href="https://calendar.google.com/calendar/r?cid=<?= e(rawurlencode(preg_replace('~^https?://~', 'webcal://', $feed))) ?>" target="_blank" rel="noopener noreferrer">Přidat do Google Kalendáře</a></p>
    <p class="hint">Nebo v kalendářové aplikaci zvolte „přidat kalendář z adresy“ a vložte: <code translate="no"><?= e($feed) ?></code><br>Adresu nesdílejte mimo dům – kdo ji zná, vidí termíny bez přihlášení.</p>
  </div>
  <?php if ($past): ?>
  <h2>Proběhlé termíny</h2>
  <ul class="thread-list"><?php foreach ($past as $ev): ?><li><a href="<?= url('/terminy/' . $ev['id']) ?>"><span class="thread-title"><?= e($ev['title']) ?></span><span class="thread-meta"><?= cz_date($ev['event_date'], true) ?><?= $ev['place'] ? ' · ' . e($ev['place']) : '' ?></span></a></li><?php endforeach ?></ul>
  <?php endif ?>
</div>
