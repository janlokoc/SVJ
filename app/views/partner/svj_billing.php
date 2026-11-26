<?php $def = (int) ($partner['resale'] ?? 0); ?>
<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/fakturace') ?>">← Fakturace</a></p>
  <header class="page-head"><h1>Faktury společenstvím</h1>
    <p class="lead">Portál je pro Vás zdarma – platíte jen za weby domů, a ty Vám platí jednotlivá SVJ. Tady jim vystavíte fakturu Vaší cenou, výbor ji dostane e-mailem a najde ji i v administraci svého webu. My Vám pak fakturujeme jen nákupní cenu s množstevní slevou (<a href="<?= url('/fakturace') ?>">Fakturace</a>).</p></header>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if ($missing): ?><div class="flash flash-info">Pro vystavení faktur doplňte na stránce <a href="<?= url('/firma') ?>#fakturace-svj">Firma</a>: <?= e(implode(', ', $missing)) ?>.</div><?php endif ?>
  <h2>Vystavit fakturu</h2>
  <?php if (!$houses): ?><p class="empty">Zatím nespravujete žádný dům.</p><?php else: ?>
  <div class="table-scroll"><table class="table"><thead><tr><th>Dům</th><th>Vyfakturováno do</th><th>Období</th><th>Částka bez DPH</th><th></th></tr></thead><tbody>
    <?php foreach ($houses as $h): $from = isset($last[$h['slug']]) ? date('Y-m-d', strtotime($last[$h['slug']] . ' +1 day')) : date('Y-m-d'); $to = date('Y-m-d', strtotime($from . ' +12 month -1 day')); $price = (int) ($h['partner_resale'] ?? 0) ?: $def; ?>
    <tr><td data-label="Dům"><strong><?= e($h['name']) ?></strong></td><td data-label="Vyfakturováno do"><?= isset($last[$h['slug']]) ? cz_date($last[$h['slug']]) : '<span class="muted">zatím nic</span>' ?></td>
      <td colspan="3"><form method="post" class="svj-issue"><?= csrf_field() ?><input type="hidden" name="action" value="issue"><input type="hidden" name="slug" value="<?= e($h['slug']) ?>">
        <label>Od <input type="date" name="from" value="<?= $from ?>" required></label><label>Do <input type="date" name="to" value="<?= $to ?>" required></label>
        <label>Kč <input type="text" name="amount" value="<?= $price ?: '' ?>" inputmode="numeric" size="7" required placeholder="cena"></label>
        <label class="check"><input type="checkbox" name="send" value="1" checked> poslat výboru</label>
        <button class="btn btn-small" type="submit"<?= $missing ? ' disabled' : '' ?>>Vystavit</button></form></td></tr>
    <?php endforeach ?>
  </tbody></table></div>
  <p class="hint">Částku předvyplňuje Vaše cena pro SVJ ze stránky <a href="<?= url('/firma') ?>#cena">Firma</a>. Číslo faktury a variabilní symbol se přidělí samy (rok a pořadí).</p>
  <?php endif ?>
  <h2>Vystavené faktury <?php if ($invoices): ?><a class="btn btn-small btn-ghost" href="<?= url('/fakturace/svj?export=csv') ?>">Stáhnout CSV pro účetnictví</a><?php endif ?></h2>
  <?php if (!$invoices): ?><p class="empty">Zatím žádné.</p><?php else: ?>
  <div class="table-scroll"><table class="table"><thead><tr><th>Číslo</th><th>Společenství</th><th>Období</th><th>Částka</th><th>Splatnost</th><th>Stav</th><th></th></tr></thead><tbody>
    <?php foreach ($invoices as $i): $c = (array) (json_decode($i['data'], true)['customer'] ?? []); $late = $i['status'] === 'issued' && $i['due'] < date('Y-m-d'); ?><tr<?= $late ? ' class="is-unmatched"' : '' ?>>
      <td data-label="Číslo"><a href="<?= url('/fakturace/svj/' . (int) $i['id']) ?>"><?= e($i['number']) ?></a></td><td data-label="Společenství"><?= e($c['name'] ?? $i['tenant']) ?></td>
      <td data-label="Období"><?= cz_date($i['period_from']) ?> – <?= cz_date($i['period_to']) ?></td><td data-label="Částka"><?= money_czk((int) $i['amount']) ?><?= $i['vat_rate'] ? ' <small class="muted">+ DPH</small>' : '' ?></td>
      <td data-label="Splatnost"><?= cz_date($i['due']) ?><?= $late ? ' <span class="tag tag-late">po splatnosti</span>' : '' ?></td>
      <td data-label="Stav"><?= e(PARTNER_SVJ_STATUS[$i['status']]) ?></td>
      <td class="t-actions"><?php if ($i['status'] !== 'cancelled'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><?php if ($i['status'] === 'issued'): ?><button class="btn btn-small" name="action" value="paid">Zaplaceno</button> <button class="link-danger" name="action" value="cancel" data-confirm="Zrušit fakturu?">Zrušit</button><?php else: ?><button class="link" name="action" value="unpaid">Vrátit na nezaplaceno</button><?php endif ?></form><?php endif ?></td></tr>
    <?php endforeach ?>
  </tbody></table></div>
  <?php endif ?>
</div>
