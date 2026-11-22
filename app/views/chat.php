<?php $person = function (array $p, string $extra = ''): string {
    return '<li><a href="' . url('/zpravy/' . $p['id']) . '"><span class="thread-title">' . e($p['name']) . position_tag($p) . '</span><span class="thread-meta">'
        . e(implode(' · ', array_filter([$p['units'] ?? '', $extra]))) . '</span></a></li>';
}; ?>
<div class="wrap page narrow">
  <header class="page-head"><h1>Zprávy</h1>
    <p class="lead">Soukromé zprávy mezi lidmi z domu. Vidíte je jen Vy a ten, komu píšete – výbor ani správce webu je nečtou.</p></header>

  <?php if ($conversations): ?>
  <h2>Rozhovory</h2>
  <ul class="thread-list chat-list">
    <?php foreach ($conversations as $c): $m = $c['m']; ?>
    <li><a href="<?= url('/zpravy/' . $c['user']['id']) ?>"<?= $c['unread'] ? ' class="is-unread"' : '' ?>>
      <span class="thread-title"><?= e($c['user']['name']) ?><?= position_tag($c['user']) ?><?= $c['unread'] ? ' <span class="count count-new">' . (int) $c['unread'] . '</span>' : '' ?></span>
      <span class="thread-meta"><?= cz_datetime($m['created_at']) ?></span>
      <span class="chat-snippet"><?= (int) $m['from_id'] === (int) user()['id'] ? 'Vy: ' : '' ?><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', $m['body']), 0, 110, '…')) ?></span></a></li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>

  <h2>Napsat zprávu</h2>
  <?php if ($committee || $neighbours): ?>
  <label class="chat-filter">Hledat podle jména nebo jednotky <input type="search" data-filter-list="#chat-people" placeholder="např. Novák nebo 12A/3" autocomplete="off"></label>
  <div id="chat-people">
    <?php if ($committee): ?>
    <h3>Výbor a kontrolní komise</h3>
    <ul class="thread-list"><?php foreach ($committee as $p) { echo $person($p); } ?></ul>
    <?php endif ?>
    <?php if ($neighbours): ?>
    <h3>Sousedé</h3>
    <ul class="thread-list"><?php foreach ($neighbours as $p) { echo $person($p); } ?></ul>
    <?php endif ?>
  </div>
  <?php else: ?><p class="empty">Zatím tu není nikdo další, komu by šlo napsat.</p><?php endif ?>
  <p class="hint">Nechcete dostávat zprávy od sousedů? Vypnete je ve <a href="<?= url('/muj-ucet') ?>">svém účtu</a>; výbor a kontrolní komise Vám napsat mohou vždy. Nevhodnou zprávu nahlaste výboru.</p>
</div>
