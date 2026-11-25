<?php foreach ($rows as &$r) { $r['bank'] = $bank[(int) $r['id']] ?? 0.0; $r['eff'] = (float) $r['balance'] + $r['bank']; } unset($r);
$with = array_filter($rows, fn($r) => $r['as_of'] !== null); $debtors = array_filter($with, fn($r) => $r['eff'] < 0); $unitOpts = $rows; ?>
<div class="wrap page">
  <header class="page-head"><h1>Platby na jednotku</h1>
    <p class="lead">Předpis záloh a stav účtu každé jednotky, jak je vede Vaše účetní. Vlastník vidí jen své jednotky, s QR kódem pro platbu. Web sám nic neúčtuje – údaje sem nahrajete z účetnictví.</p></header>
  <?php if (!$iban): ?><div class="flash flash-info">V <a href="<?= url('/admin/nastaveni') ?>">Nastavení</a> není vyplněné platné číslo účtu společenství, proto se vlastníkům nezobrazí QR platba.</div><?php endif ?>
  <div class="stat-grid">
    <div class="stat"><span class="stat-num"><?= count($with) ?></span> jednotek s údaji</div>
    <div class="stat"><span class="stat-num<?= $debtors ? ' is-debt' : '' ?>"><?= count($debtors) ?></span> s nedoplatkem</div>
    <div class="stat"><span class="stat-num"><?= account_money(-array_sum(array_map(fn($r) => $r['eff'], $debtors))) ?></span> dlužná částka celkem</div>
  </div>
  <form method="post" class="card form">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <h2>Nahrát z účetnictví</h2>
    <label>Řádky ve tvaru: jednotka ; měsíční záloha ; stav účtu ; variabilní symbol ; poznámka
      <textarea name="text" rows="6" required placeholder="<?= e(($rows ? unit_name($rows[0]) : '48A/1') . ' ; 4 250 ; -1 200 ; 4801') ?>&#10;… záporný stav = nedoplatek, kladný = přeplatek"></textarea></label>
    <p class="hint">Zkopírujte sloupce z Excelu nebo exportu účetního programu (oddělené středníkem nebo tabulátorem). Jednotka se pozná podle označení (<?= $rows ? e(unit_name($rows[0])) : '48A/1' ?>). Nahrané jednotky se přepíší, ostatní zůstanou.</p>
    <label>Stav platí ke dni <input type="date" name="as_of" value="<?= date('Y-m-d') ?>" required></label>
    <div class="actions"><button class="btn" type="submit">Nahrát</button></div>
  </form>
  <?php if ($with): ?>
  <div class="actions">
    <?php if ($debtors): ?><form method="post" data-confirm="Poslat e-mail s upomínkou vlastníkům <?= count($debtors) ?> jednotek s nedoplatkem?"><?= csrf_field() ?><input type="hidden" name="action" value="remind"><button class="btn" type="submit">Poslat upomínku dlužníkům (<?= count($debtors) ?>)</button></form><?php endif ?>
    <form method="post" data-confirm="Smazat celý přehled plateb? Vlastníci ho přestanou vidět."><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn btn-ghost" type="submit">Smazat přehled</button></form>
  </div>
  <?php endif ?>
  <section class="card" id="banka">
    <h2>Párování plateb s bankou</h2>
    <p>Příchozí platby z výpisu účtu společenství se podle variabilního symbolu přiřadí k jednotkám a <strong>přičtou se ke stavu z účetnictví</strong> – jen ty, které přišly po dni, ke kterému stav platí. Nic se tak nezapočítá dvakrát; po dalším nahrání z účetnictví se počítá zase od nového dne.</p>
    <div class="two-col">
      <form method="post" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="bank_upload">
        <h3>Nahrát výpis</h3>
        <label>Soubor výpisu <input type="file" name="statement" accept=".gpc,.abo,.csv,.txt" required></label>
        <p class="hint">Formát GPC/ABO (umí ho většina bank: KB, ČSOB, Česká spořitelna, Raiffeisenbank, Fio…) nebo CSV se sloupci Datum, Částka/Objem a VS. Stejnou platbu můžete nahrát i víckrát – podruhé se přeskočí.</p>
        <button class="btn" type="submit">Načíst výpis</button></form>
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="bank_token">
        <h3>Automaticky z Fio banky</h3>
        <?php if ($fioToken): ?><p><span class="status status-done">Zapnuto</span> Platby se stahují samy jednou denně.<?= ($bankLast[0] ?? '') !== '' ? '<br><small class="muted">Naposledy ' . e($bankLast[0]) . ': ' . e($bankLast[2] ?? '') . '</small>' : '' ?></p><?php endif ?>
        <label>Token Fio API <input type="password" name="token" autocomplete="off" placeholder="<?= $fioToken ? 'token je uložený – pro změnu vložte nový, prázdné = vypnout' : 'vložte token z internetového bankovnictví' ?>"></label>
        <p class="hint">Token vytvoříte v internetovém bankovnictví Fio (Nastavení → API) s oprávněním <strong>jen pro čtení</strong> – web s ním nemůže nic zaplatit. Služba je zdarma.</p>
        <button class="btn btn-ghost" type="submit"><?= $fioToken ? 'Uložit' : 'Zapnout' ?></button></form>
    </div>
    <?php if ($bankTx): ?>
    <h3>Poslední příchozí platby</h3>
    <table class="table bank-tx"><thead><tr><th>Datum</th><th>Částka</th><th>VS</th><th>Od</th><th>Jednotka</th></tr></thead><tbody>
      <?php foreach ($bankTx as $t): ?><tr<?= $t['unit_id'] ? '' : ' class="is-unmatched"' ?>>
        <td data-label="Datum"><?= cz_date($t['tx_date']) ?></td><td data-label="Částka"><?= account_money((float) $t['amount']) ?></td><td data-label="VS"><?= e($t['vs']) ?: '—' ?></td>
        <td data-label="Od"><small><?= e($t['counter_name'] !== '' ? $t['counter_name'] : $t['counter_account']) ?><?= $t['message'] !== '' ? '<br><span class="muted">' . e(mb_strimwidth($t['message'], 0, 60, '…')) . '</span>' : '' ?></small></td>
        <td data-label="Jednotka"><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="bank_assign"><input type="hidden" name="tx" value="<?= (int) $t['id'] ?>">
          <select name="unit_id" aria-label="Jednotka pro platbu"><option value="0"><?= $t['unit_id'] ? 'zrušit přiřazení' : 'nespárováno – vyberte' ?></option><?php foreach ($unitOpts as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $t['unit_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e(unit_name($u)) ?></option><?php endforeach ?></select>
          <button class="btn btn-small btn-ghost" type="submit">Uložit</button></form></td></tr><?php endforeach ?>
    </tbody></table>
    <?php endif ?>
  </section>
  <table class="table">
    <thead><tr><th>Jednotka</th><th>Vlastník dle evidence</th><th>Měsíční záloha</th><th>Stav účtu</th><th>Var. symbol</th><th>K datu</th><th>Upomínka</th></tr></thead>
    <tbody><?php foreach ($rows as $r): $bal = (float) $r['balance']; ?>
      <tr<?= $r['as_of'] === null ? ' class="is-off"' : '' ?>>
        <td data-label="Jednotka"><strong><?= e(unit_name($r)) ?></strong><?= $r['accounts'] ? '' : ' <span class="tag">bez účtu na webu</span>' ?></td>
        <td data-label="Vlastník dle evidence"><?= e($r['owner_note']) ?></td>
        <td data-label="Měsíční záloha"><?= $r['as_of'] === null ? '—' : account_money((float) $r['prescribed']) ?></td>
        <td data-label="Stav účtu"><?php $bal = $r['eff']; ?><?= $r['as_of'] === null ? '—' : '<strong class="' . ($bal < 0 ? 'is-debt' : 'is-ok') . '">' . ($bal < 0 ? 'nedoplatek ' . account_money(-$bal) : ($bal > 0 ? 'přeplatek ' . account_money($bal) : 'vyrovnáno')) . '</strong>' . ($r['bank'] ? '<br><small class="muted">vč. plateb z banky ' . account_money($r['bank']) . '</small>' : '') ?></td>
        <td data-label="Var. symbol"><?= e((string) $r['vs']) ?></td>
        <td data-label="K datu"><?= $r['as_of'] ? cz_date($r['as_of']) : '' ?></td>
        <td data-label="Upomínka"><?= $r['reminded_at'] ? cz_date($r['reminded_at']) : '' ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table>
</div>
