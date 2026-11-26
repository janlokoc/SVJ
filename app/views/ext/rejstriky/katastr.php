<div class="wrap page">
  <header class="page-head"><h1>Změny v katastru</h1>
    <p class="lead">Web každých <?= (int) $days ?> dní stáhne z katastru nemovitostí výpis listu vlastnictví budovy a porovná ho s minulým stavem. Nový vlastník, změna podílu nebo probíhající řízení (plomba) se ukáže zde a správcům přijde e-mail.</p>
    <p><a href="<?= url('/admin/rejstriky') ?>">← Katastr a rejstříky</a> <?= help_link('katastr-a-rejstriky') ?></p></header>
  <?php $betaKey = 'katastr'; require __DIR__ . '/../../admin/_beta.php'; ?>
  <?php if (!$premium): ?>
  <div class="card"><p>Sledování katastru je součástí edice Premium. <a href="<?= url('/admin/predplatne') ?>#edice">Předplatné</a></p></div>
  <?php else: ?>
  <?php if (!$configured): ?>
  <div class="callout"><p>Propojení s katastrem (dálkový přístup ČÚZK) zatím provozovatel nenastavil. Budovu si už můžete připravit, kontrola začne, až bude propojení hotové.</p></div>
  <?php endif ?>
  <div class="two-col">
    <form method="post" class="card form">
      <?= csrf_field() ?><input type="hidden" name="action" value="target">
      <h2>Budova v katastru</h2>
      <label>Kód katastrálního území <input type="text" name="ku" value="<?= e($target['ku']) ?>" inputmode="numeric" maxlength="6" required></label>
      <label>Číslo listu vlastnictví budovy <input type="text" name="lv" value="<?= e(implode(', ', $target['lv'])) ?>" required></label>
      <p class="hint">Obojí najdete v Nahlížení do katastru na stránce stavby (např. „Katastrální území: Veveří [610372]“, „List vlastnictví: 1234“). Má-li dům víc budov, oddělte LV čárkou.</p>
      <button class="btn" type="submit">Uložit</button>
    </form>
    <div class="card">
      <h2>Sledování</h2>
      <?php if ($on === ''): ?>
      <p>Cena: <strong><?= e(money_czk(rej_price('rej_price_katastr_house'))) ?> za dům a rok</strong> včetně 1 listu vlastnictví, každý další LV <?= e(money_czk(rej_price('rej_price_katastr_lv_extra'))) ?> ročně (bez DPH).
        <?= $lvCount > 1 ? 'U Vašeho domu (' . (int) $lvCount . ' LV) <strong>' . e(money_czk($price)) . ' ročně</strong>. ' : '' ?><?= $billed ? 'Platí se s ročním předplatným; když sledování zapnete v průběhu zaplaceného období, zaplatíte jen poměrnou část do jeho konce.' : '' ?></p>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="activate">
        <label class="check"><input type="checkbox" name="confirm" value="1" required> <span>Souhlasím s cenou <?= e(money_czk($price)) ?> ročně bez DPH.</span></label>
        <button class="btn" type="submit"<?= $target['ku'] === '' ? ' disabled' : '' ?>>Zapnout sledování</button>
      </form>
      <?php else: ?>
      <?php if ($payment && rej_katastr_unpaid($payment)): ?><p class="callout">Sledování je <strong>pozastavené</strong>: výzva k platbě (VS <?= e($payment['vs']) ?>) je nezaplacená déle než <?= REJ_KATASTR_GRACE_DAYS ?> dní. Po zaplacení se kontroly obnoví – <a href="<?= url('/admin/predplatne') ?>#doplnky">platební údaje</a>.</p>
      <?php elseif ($payment): ?><p class="callout">Za zbytek zaplaceného období čeká platba <?= pay_money($payment) ?> (VS <?= e($payment['vs']) ?>) – <a href="<?= url('/admin/predplatne') ?>#doplnky">platební údaje</a>.</p><?php endif ?>
      <p><span class="status status-done">zapnuto</span> od <?= e(cz_date($on)) ?> · <?= e(money_czk($price)) ?> ročně bez DPH.
        <?= ($last[0] ?? '') !== '' ? '<br>Poslední kontrola ' . e($last[0]) . ': ' . e($last[2] ?? '') . '.' : '<br>Zatím bez kontroly.' ?></p>
      <form method="post" class="actions">
        <?= csrf_field() ?>
        <button class="btn btn-ghost" type="submit" name="action" value="run"<?= $configured ? '' : ' disabled' ?>>Zkontrolovat teď</button>
        <button class="btn btn-ghost btn-small" type="submit" name="action" value="deactivate">Vypnout sledování</button>
      </form>
      <p class="hint">Mimořádná kontrola stojí provozovatele další výpis z katastru – používejte ji jen po prodeji bytu, o kterém víte.</p>
      <?php endif ?>
    </div>
  </div>
  <section class="card">
    <h2>Zjištěné změny</h2>
    <p class="chips"><a href="<?= url('/admin/rejstriky/katastr') ?>"<?= $show === 'new' ? ' aria-current="page"' : '' ?>>K vyřízení</a><a href="<?= url('/admin/rejstriky/katastr?zobrazit=vse') ?>"<?= $show === 'all' ? ' aria-current="page"' : '' ?>>Všechny</a></p>
    <?php if (!$changes): ?><p class="muted"><?= $show === 'new' ? 'Žádné změny k vyřízení.' : 'Zatím žádné změny.' ?></p><?php else: ?>
    <table class="table">
      <thead><tr><th>Zjištěno</th><th>Jednotka</th><th>Změna</th><th>Dosud v evidenci</th><th>Podle katastru</th><th></th></tr></thead>
      <tbody><?php foreach ($changes as $c): ?>
        <tr>
          <td data-label="Zjištěno"><?= e(cz_datetime($c['found_at'])) ?></td>
          <td data-label="Jednotka"><?= (int) $c['unit_id'] && isset($unitNames[$c['unit_id']]) ? '<strong>' . e($unitNames[$c['unit_id']]) . '</strong>' : '' ?><?= $c['unit_key'] !== '' ? '<br><small class="muted">katastr ' . e($c['unit_key']) . '</small>' : '<small class="muted">budova</small>' ?></td>
          <td data-label="Změna"><?= e(REJ_CHANGE_KINDS[$c['kind']] ?? $c['kind']) ?></td>
          <td data-label="Dosud"><?= e((string) $c['old_value']) ?></td>
          <td data-label="Katastr"><?= e((string) $c['new_value']) ?></td>
          <td data-label=""><?php if ($c['status'] === 'new'): ?>
            <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <?php if (in_array($c['kind'], ['owner', 'share', 'new_unit'], true)): ?><button class="btn btn-small" type="submit" name="action" value="apply">Převzít do evidence</button><?php endif ?>
              <button class="btn btn-small btn-ghost" type="submit" name="action" value="dismiss">Vzít na vědomí</button></form>
            <?php else: ?><small class="muted"><?= e(REJ_CHANGE_STATUS[$c['status']] ?? $c['status']) ?>, <?= e(cz_datetime($c['handled_at'])) ?> · <?= e($c['handled_by']) ?></small><?php endif ?></td>
        </tr>
      <?php endforeach ?></tbody>
    </table>
    <p class="hint">„Převzít do evidence“ přepíše u jednotky vlastníka nebo podíl. Kontakty a přiřazené účty obyvatel zůstanou – nového vlastníka pozvěte v Uživatelích.</p>
    <?php endif ?>
  </section>
  <?php endif ?>
</div>
