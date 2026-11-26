<div class="wrap page">
  <header class="page-head"><h1>Katastr a rejstříky</h1>
    <p class="lead">Výbor se včas dozví o prodeji bytu, novém spoluvlastníkovi, insolvenci nebo exekuci vlastníka – a stihne přihlásit pohledávku nebo upravit evidenci.</p></header>
  <?php if (!$premium): ?>
  <div class="card"><p>Hlídání katastru a rejstříků je součástí edice Premium. <a href="<?= url('/admin/predplatne') ?>#edice">Předplatné</a></p></div>
  <?php endif ?>
  <div class="two-col">
    <?php if (isset($sum['katastr'])): $k = $sum['katastr']; ?>
    <section class="card">
      <h2>Změny v katastru<?= beta_is('katastr') ? ' <span class="tag tag-beta">Beta</span>' : '' ?></h2>
      <?php if (!$k['configured']): ?><p class="muted">Propojení s katastrem zatím provozovatel nenastavil.</p>
      <?php elseif (!$k['on']): ?><p>Sledování je vypnuté. Cena: <?= e(money_czk(rej_price('rej_price_katastr_house'))) ?> za dům a rok včetně 1 listu vlastnictví, každý další LV <?= e(money_czk(rej_price('rej_price_katastr_lv_extra'))) ?> (bez DPH)<?= $k['lv'] > 1 ? '; u Vašeho domu (' . (int) $k['lv'] . ' LV) ' . e(money_czk($k['price'])) . ' ročně' : '' ?>.</p>
      <?php else: ?><p><?= $k['open'] ? '<strong>Změn k vyřízení: ' . (int) $k['open'] . '</strong>' : 'Žádné nevyřízené změny.' ?>
        <?= ($k['last'][0] ?? '') !== '' ? '<br><small class="muted">Poslední kontrola ' . e($k['last'][0]) . ': ' . e($k['last'][2] ?? '') . '</small>' : '' ?></p><?php endif ?>
      <p><a class="btn btn-small" href="<?= url('/admin/rejstriky/katastr') ?>">Otevřít</a></p>
    </section>
    <?php endif ?>
    <?php if (isset($sum['insolvence'])): $i = $sum['insolvence']; ?>
    <section class="card">
      <h2>Insolvence vlastníků<?= beta_is('insolvence') ? ' <span class="tag tag-beta">Beta</span>' : '' ?></h2>
      <p>Hlídané firmy: <?= (int) $i['companies'] ?><?= $i['company_hits'] ? ', s řízením: <strong>' . (int) $i['company_hits'] . '</strong>' : '' ?><br>
        Hlídané fyzické osoby: <?= (int) $i['persons'] ?><?= $i['person_hits'] ? ', s řízením: <strong>' . (int) $i['person_hits'] . '</strong>' : '' ?>
        <?= ($i['last'][0] ?? '') !== '' ? '<br><small class="muted">Poslední kontrola osob ' . e($i['last'][0]) . ': ' . e($i['last'][2] ?? '') . '</small>' : '' ?></p>
      <p class="hint">Insolvenční rejstřík je zdarma, hlídání je v ceně edice Premium.</p>
      <p><a class="btn btn-small" href="<?= url('/admin/insolvence') ?>">Otevřít</a></p>
    </section>
    <?php endif ?>
    <?php if (isset($sum['exekuce'])): $x = $sum['exekuce']; ?>
    <section class="card">
      <h2>Exekuce<?= beta_is('exekuce') ? ' <span class="tag tag-beta">Beta</span>' : '' ?></h2>
      <?php if (!$x['configured']): ?><p class="muted">Propojení s evidencí exekucí zatím provozovatel nenastavil.</p><?php endif ?>
      <p>Lustrace v Centrální evidenci exekucí na kliknutí, <?= e(money_czk($x['price'])) ?> za dotaz (bez DPH) z kreditu služeb<?= $x['credit'] ? '' : ' – <strong>kredit nestačí</strong> ' . credit_topup_link() ?>.<br>
        Provedeno: <?= (int) $x['checks'] ?><?= $x['hits'] ? ', s nálezem: <strong>' . (int) $x['hits'] . '</strong>' : '' ?><?= $x['last'] !== '' ? ' · naposledy ' . e(cz_datetime($x['last'])) : '' ?></p>
      <p><a class="btn btn-small" href="<?= url('/admin/rejstriky/exekuce') ?>">Otevřít</a></p>
    </section>
    <?php endif ?>
  </div>
  <?php if ($usage): ?>
  <section class="card">
    <h2>Placené dotazy</h2>
    <table class="table">
      <thead><tr><th>Kdy</th><th>Co</th><th>Cena bez DPH</th></tr></thead>
      <tbody><?php foreach ($usage as $r): if ($r['kind'] === 'katastr_lv') continue; ?>
        <tr><td data-label="Kdy"><?= e(cz_datetime($r['at'])) ?></td><td data-label="Co"><?= e(REJ_USAGE_KINDS[$r['kind']] ?? $r['kind']) ?><?= $r['kind'] === 'katastr_year' ? ' (' . (int) $r['qty'] . ' LV)' : '' ?></td>
          <td data-label="Cena"><?= e(money_czk((int) $r['price'])) ?></td></tr>
      <?php endforeach ?></tbody>
    </table>
    <p class="hint">Lustrace se platí z <a href="<?= url('/admin/kredit') ?>">kreditu služeb</a>, sledování katastru s ročním předplatným.</p>
  </section>
  <?php endif ?>
  <p class="hint"><?= help_link('katastr-a-rejstriky') ?></p>
</div>
