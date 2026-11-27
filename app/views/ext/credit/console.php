<?php
/** Konzole: Kredit služeb. @var string $month @var array $names @var array $balances @var array $usage @var array $totals @var array $rej @var array $fields @var array $addons @var array $ledger */
$sum = ['h' => 0];
?>
<div class="wrap page">
  <header class="page-head"><h1>Kredit služeb</h1>
    <p class="lead">Předplacený kredit domů v Kč (bez DPH) na služby podle spotřeby: SMS, lustrace exekucí a ověření hlasu přes Bank iD (shromáždění online je v ceně edic). Dobití se platí zálohovou fakturou (Platby → potvrdit platbu); kredit se pak připíše sám. Kredit platí <?= credit_setting('credit_valid_months') ?> měsíců od dobití.</p></header>

  <section class="card" id="domy">
    <h2>Zůstatky domů</h2>
    <?php if (!$balances): ?><p class="muted">Zatím žádný dům nemá pohyby kreditu.</p><?php else: ?>
    <table class="table"><thead><tr><th>Společenství</th><th>Zůstatek</th><th>Poslední pohyb</th></tr></thead><tbody>
      <?php foreach ($balances as $slug => $b): ?><tr><td data-label="Společenství"><a href="<?= url('/svj/' . $slug) ?>"><?= e($names[$slug] ?? $slug) ?></a></td>
        <td data-label="Zůstatek"><strong><?= e(credit_money((int) $b['h'])) ?></strong></td><td data-label="Poslední pohyb"><?= e(substr((string) $b['last'], 0, 16)) ?></td></tr><?php endforeach ?>
    </tbody></table>
    <?php endif ?>
    <div class="two-col">
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="fix">
        <h3>Ruční oprava</h3>
        <label>Společenství <select name="tenant" required><?php foreach ($names as $slug => $n): ?><option value="<?= e($slug) ?>"><?= e($n) ?> (<?= e(credit_money((int) ($balances[$slug]['h'] ?? 0))) ?>)</option><?php endforeach ?></select></label>
        <label>Částka v Kč <input type="text" name="amount" inputmode="decimal" required placeholder="např. 200, nebo -50 pro odečtení"></label>
        <label>Poznámka <input type="text" name="note" maxlength="200" required placeholder="např. kompenzace za výpadek brány"></label>
        <button class="btn" type="submit">Upravit kredit</button></form>
      <form method="post" class="form" data-confirm="Odepsat celý zůstatek kreditu? Peníze pak pošlete převodem."><?= csrf_field() ?><input type="hidden" name="action" value="refund">
        <h3>Vrátit zůstatek</h3>
        <p class="hint">Při zrušení služby: kredit domu se vynuluje (pohyb „Vrácení zůstatku“). Peníze pošlete převodem a k poslední faktuře za dobití vystavte opravný doklad.</p>
        <label>Společenství <select name="tenant" required><?php foreach ($balances as $slug => $b): if ((int) $b['h'] <= 0) { continue; } ?><option value="<?= e($slug) ?>"><?= e($names[$slug] ?? $slug) ?> (<?= e(credit_money((int) $b['h'])) ?>)</option><?php endforeach ?></select></label>
        <label>Poznámka <input type="text" name="note" maxlength="200" placeholder="např. zrušení webu, účet 123/0100"></label>
        <button class="btn btn-ghost" type="submit">Vrátit zůstatek</button></form>
    </div>
  </section>

  <section class="card" id="spotreba">
    <h2>Spotřeba za měsíc</h2>
    <form method="get" class="inline-form"><label>Měsíc <input type="month" name="mesic" value="<?= e($month) ?>"></label><button class="btn btn-small btn-ghost" type="submit">Zobrazit</button></form>
    <?php if (!$usage && !$rej): ?><p class="muted">V tomto měsíci žádná spotřeba.</p><?php else: ?>
    <table class="table"><thead><tr><th>Společenství</th><th>Služba</th><th>Počet</th><th>Z kreditu</th><th>Náklad (katastr)</th></tr></thead><tbody>
      <?php foreach ($usage as $slug => $kinds): foreach ($kinds as $kind => $r): $sum['h'] += $r['h']; ?><tr><td data-label="Společenství"><?= e($names[$slug] ?? $slug) ?></td>
        <td data-label="Služba"><?= e(CREDIT_KINDS[$kind] ?? $kind) ?></td><td data-label="Počet"><?= (int) $r['qty'] ?: (int) $r['n'] ?></td><td data-label="Z kreditu"><?= e(credit_money($r['h'])) ?></td><td data-label="Náklad"></td></tr><?php endforeach; endforeach ?>
      <?php foreach ($rej as $slug => $kinds): foreach ($kinds as $kind => $r): if (!str_starts_with($kind, 'katastr')) { continue; } ?><tr><td data-label="Společenství"><?= e($names[$slug] ?? $slug) ?></td>
        <td data-label="Služba"><?= e(REJ_USAGE_KINDS[$kind] ?? $kind) ?></td><td data-label="Počet"><?= (int) $r['n'] ?></td><td data-label="Z kreditu"><small class="muted"><?= $r['price'] ? e(money_czk($r['price'])) . ' mimo fakturu' : 'faktura předplatného' ?></small></td><td data-label="Náklad"><?= e(money_czk($r['cost'])) ?></td></tr><?php endforeach; endforeach ?>
    </tbody><tfoot><tr><th colspan="3">Čerpáno z kreditu celkem</th><th><?= e(credit_money($sum['h'])) ?></th><th></th></tr></tfoot></table>
    <?php endif ?>
    <p class="hint">Za měsíc: dobito <?= e(credit_money((int) ($totals['topup'] ?? 0))) ?>, propadlo <?= e(credit_money(-(int) ($totals['expire'] ?? 0))) ?>, vráceno <?= e(credit_money(-(int) ($totals['refund'] ?? 0))) ?>, opravy <?= e(credit_money((int) ($totals['fix'] ?? 0))) ?>. Lustrace exekucí má náklady na stránce <a href="<?= url('/rejstriky') ?>">Rejstříky</a>.</p>
  </section>

  <section class="card" id="doplnky">
    <h2>Doplňky k předplatnému</h2>
    <?php if (!$addons): ?><p class="muted">Žádný dům nemá zapnutý doplněk.</p><?php else: ?>
    <table class="table"><thead><tr><th>Společenství</th><th>Doplněk</th><th>Cena za rok</th><th>Zaplaceno do</th></tr></thead><tbody>
      <?php foreach ($addons as $a): ?><tr><td data-label="Společenství"><?= e($a['name'] ?? $a['tenant']) ?></td><td data-label="Doplněk"><?= e(addon_label($a['addon'], (int) $a['qty'])) ?></td>
        <td data-label="Cena"><?= e(money_czk(addon_price($a['addon'], (int) $a['qty']))) ?></td><td data-label="Zaplaceno do"><?= $a['paid_until'] !== '' ? e(cz_date($a['paid_until'])) : '<span class="muted">nezaplaceno / bez předplatného</span>' ?></td></tr><?php endforeach ?>
    </tbody></table>
    <?php endif ?>
  </section>

  <form method="post" class="card form" id="ceny"><?= csrf_field() ?><input type="hidden" name="action" value="prices">
    <h2>Ceny služeb a doplňků</h2>
    <p class="hint">Ceny bez DPH (tak se ukazují i na veřejném ceníku). Změna platí pro další čerpání; zaplacené faktury se nemění.</p>
    <?php foreach ($fields as $key => [$label, $unit, $get]): $v = $get(); ?>
      <label><?= e($label) ?> <input type="text" name="<?= e($key) ?>" inputmode="decimal" maxlength="9" value="<?= e($unit === 'h' ? rtrim(rtrim(number_format($v / 100, 2, '.', ''), '0'), '.') : (string) $v) ?>"></label>
    <?php endforeach ?>
    <button class="btn" type="submit">Uložit ceny</button>
  </form>

  <?php if ($ledger): ?>
  <section class="card">
    <h2>Poslední pohyby</h2>
    <table class="table"><thead><tr><th>Kdy</th><th>Společenství</th><th>Pohyb</th><th>Poznámka</th></tr></thead><tbody>
      <?php foreach ($ledger as $l): ?><tr><td data-label="Kdy"><?= e(substr($l['at'], 0, 16)) ?></td><td data-label="Společenství"><?= e($names[$l['tenant']] ?? $l['tenant']) ?></td>
        <td data-label="Pohyb"><?= (int) $l['delta_h'] > 0 ? '+' : '' ?><?= e(credit_money((int) $l['delta_h'])) ?> <small class="muted"><?= e(CREDIT_KINDS[$l['kind']] ?? $l['kind']) ?></small></td>
        <td data-label="Poznámka"><small><?= e($l['note']) ?><?= $l['by_whom'] !== '' ? ' · ' . e($l['by_whom']) : '' ?></small></td></tr><?php endforeach ?>
    </tbody></table>
  </section>
  <?php endif ?>
</div>
