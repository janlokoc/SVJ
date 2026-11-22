<div class="wrap page">
  <header class="page-head"><h1>Platby</h1></header>
  <p class="lead">Výzvy k platbě vznikají samy, když si správce společenství otevře stránku Předplatné. Po připsání platby na účet ji tady podle variabilního symbolu potvrďte – předplatné se tím prodlouží.</p>
  <?php if (!$rows): ?><p class="empty">Zatím tu nejsou žádné platby.</p><?php else: ?>
  <label class="table-filter">Hledat <input type="search" data-filter="#payments tbody" data-filter-rows="tr" placeholder="Variabilní symbol nebo název…"></label>
  <table class="table" id="payments">
    <thead><tr><th>Var. symbol</th><th>Společenství</th><th>Částka</th><th>Období</th><th>Stav</th><th>Vytvořeno</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $p): $open = in_array($p['status'], ['pending', 'claimed'], true); ?>
      <tr<?= $p['status'] === 'cancelled' ? ' class="is-off"' : '' ?>>
        <td data-label="Var. symbol"><code><?= e($p['vs']) ?></code></td>
        <td data-label="Společenství"><?php if ($p['tenant_name']): ?><a href="<?= url('/svj/' . $p['tenant']) ?>"><?= e($p['tenant_name']) ?></a><?php else: ?><?= e($p['tenant']) ?> <small class="muted">(smazáno)</small><?php endif ?></td>
        <td data-label="Částka"><?= money_czk((int) $p['amount']) ?><?= ($p['kind'] ?? '') === 'domain' ? '<br><small class="muted">doména ' . e($p['item']) . '</small>' : (($p['kind'] ?? '') === 'ai' ? '<br><small class="muted">AI Plus</small>' : (($p['kind'] ?? '') === 'storage' ? '<br><small class="muted">úložiště +' . (int) $p['item'] . ' MB</small>' : (($p['kind'] ?? '') === 'migration' ? '<br><small class="muted">převod obsahu z ' . e($p['item']) . '</small>' : (isset(EDITIONS[$p['item'] ?? '']) ? '<br><small class="muted">edice ' . EDITIONS[$p['item']] . '</small>' : '')))) ?></td>
        <td data-label="Období"><?= $p['period_from'] ? cz_date($p['period_from']) . ' – ' . cz_date($p['period_to']) : ((int) $p['months'] === 12 ? '1 rok' : '1 měsíc') ?></td>
        <td data-label="Stav"><span class="status status-<?= ['pending' => 'in_progress', 'claimed' => 'new', 'paid' => 'done', 'cancelled' => 'rsvp-no'][$p['status']] ?>"><?= e(PAYMENT_STATUS[$p['status']]) ?></span><?= $p['paid_at'] ? '<br><small class="muted">' . cz_datetime($p['paid_at']) . ' · ' . e($p['confirmed_by']) . '</small>' : '' ?></td>
        <td data-label="Vytvořeno"><?= cz_date($p['created_at']) ?></td>
        <td class="t-actions"><a href="<?= url('/platby/' . $p['id'] . '/doklad') ?>"><?= $p['status'] === 'paid' ? 'Faktura ' . e($p['invoice_no']) : ($p['status'] === 'cancelled' ? '' : 'Zálohová faktura') ?></a> <?php if ($open): ?>
          <form method="post" action="<?= url('/platby/' . $p['id'] . '/potvrdit') ?>" class="inline-form" data-confirm="Potvrdit, že platba <?= money_czk((int) $p['amount']) ?> s variabilním symbolem <?= e($p['vs']) ?> dorazila na účet?"><?= csrf_field() ?><button class="btn btn-small" type="submit">Platba dorazila</button></form>
          <form method="post" action="<?= url('/platby/' . $p['id'] . '/zrusit') ?>" class="inline-form" data-confirm="Zrušit tuto výzvu k platbě?"><?= csrf_field() ?><button class="btn btn-small btn-ghost" type="submit">Zrušit</button></form>
        <?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif ?>
</div>
