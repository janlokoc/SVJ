<div class="wrap page">
  <header class="page-head"><h1>Katastr a rejstříky</h1>
    <p class="lead">Placený doplněk edice Premium: sledování změn v katastru (dálkový přístup ČÚZK), insolvence vlastníků (ISIR, zdarma) a lustrace exekucí (Centrální evidence exekucí). Zapíná se v <a href="<?= url('/nastaveni') ?>#beta">Nastavení služby → Nové funkce</a>.</p></header>
  <div class="two-col">
    <section class="card">
      <h2>Propojení</h2>
      <ul>
        <li>Katastr (WSDP): <?= $wsdp ? '<span class="status status-done">nastaveno</span>' : '<span class="muted">chybí účet</span>' ?> · funkce <?= e($stages['katastr']) ?></li>
        <li>Insolvenční rejstřík: veřejná služba bez účtu · funkce <?= e($stages['insolvence']) ?></li>
        <li>Evidence exekucí (CEE): <?= $cee ? '<span class="status status-done">nastaveno</span>' : '<span class="muted">chybí API klíč</span>' ?> · funkce <?= e($stages['exekuce']) ?></li>
      </ul>
      <p class="hint">Přihlašovací údaje se zadávají jen v souboru <code>storage/config.php</code>:<br>
        <code>'katastr' => ['user' => '…', 'password' => '…', 'endpoint' => 'https://katastr.cuzk.gov.cz/ws/wsdp/3.1']</code><br>
        <code>'exekuce' => ['api_key' => '…', 'api_secret' => '…', 'url' => 'https://www.ceecr.cz/api/v4']</code></p>
      <form method="post" class="actions"><?= csrf_field() ?>
        <button class="btn btn-small btn-ghost" type="submit" name="action" value="test_wsdp"<?= $wsdp ? '' : ' disabled' ?>>Vyzkoušet katastr</button>
        <button class="btn btn-small btn-ghost" type="submit" name="action" value="test_cee"<?= $cee ? '' : ' disabled' ?>>Vyzkoušet exekuce</button></form>
      <p class="hint">Zkouška katastru volá bezplatnou operaci stavWS, zkouška exekucí jen vydání tokenu – nic se neúčtuje.</p>
    </section>
    <form method="post" class="card form">
      <?= csrf_field() ?><input type="hidden" name="action" value="prices">
      <h2>Ceny a náklady (bez DPH)</h2>
      <?php foreach (REJ_PRICES as $key => [$default, $label]): ?>
      <label><?= e($label) ?> <input type="text" name="<?= e($key) ?>" value="<?= rej_price($key) ?>" inputmode="numeric" maxlength="7"></label>
      <?php endforeach ?>
      <button class="btn" type="submit">Uložit</button>
    </form>
  </div>
  <section class="card">
    <h2>Spotřeba v měsíci</h2>
    <form method="get" class="actions"><label>Měsíc <input type="month" name="mesic" value="<?= e($month) ?>"></label><button class="btn btn-small btn-ghost" type="submit">Zobrazit</button></form>
    <?php if (!$report): ?><p class="muted">V tomto měsíci žádné placené dotazy.</p><?php else: $sumPrice = $sumCost = 0; ?>
    <table class="table">
      <thead><tr><th>Společenství</th><th>Služba</th><th>Počet</th><th>Fakturovat</th><th>Náklad</th></tr></thead>
      <tbody><?php foreach ($report as $slug => $kinds): foreach ($kinds as $kind => $r): $sumPrice += $r['price']; $sumCost += $r['cost']; ?>
        <tr><td data-label="Společenství"><a href="<?= url('/svj/' . $slug) ?>"><?= e($names[$slug] ?? $slug) ?></a></td><td data-label="Služba"><?= e(REJ_USAGE_KINDS[$kind] ?? $kind) ?></td>
          <td data-label="Počet"><?= (int) $r['n'] ?><?= $kind === 'katastr_year' ? ' (' . (int) $r['qty'] . ' LV)' : '' ?></td><td data-label="Fakturovat"><?= e(money_czk($r['price'])) ?></td><td data-label="Náklad"><?= e(money_czk($r['cost'])) ?></td></tr>
      <?php endforeach; endforeach ?></tbody>
      <tfoot><tr><th colspan="3">Celkem</th><th><?= e(money_czk($sumPrice)) ?></th><th><?= e(money_czk($sumCost)) ?></th></tr></tfoot>
    </table>
    <p class="hint">„Fakturovat“ = cena pro společenství podle ceníku v době dotazu; „Náklad“ = co účtuje ČÚZK nebo CEE (podle odpovědi služby, jinak podle nastavení vpravo nahoře). Lustrace se strhává z kreditu služeb (Kredit), sledování katastru je doplněk na faktuře za předplatné; „Fakturovat“ u katastru zbývá jen u domů bez předplatného.</p>
    <?php endif ?>
  </section>
</div>
