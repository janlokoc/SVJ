<div class="wrap page">
  <header class="page-head"><h1>Partneři</h1>
    <p class="lead">Správcovské firmy, které spravují více domů. Mají vlastní <a href="<?= e(platform_url('/partner')) ?>">partnerský portál</a>, zakládají weby pro klienty a platí za ně jednou fakturou s množstevní slevou (nastavíte ji v <a href="<?= url('/nastaveni') ?>#partneri">Nastavení služby</a>).</p></header>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if ($rows): ?>
  <table class="table"><thead><tr><th>Firma</th><th>Domů</th><th>Čeká na výbor</th><th>Pracovníků</th><th>Založeno</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr<?= $r['active'] ? '' : ' class="is-off"' ?>>
      <td data-label="Firma"><a href="<?= url('/partneri/' . $r['id']) ?>"><strong><?= e($r['name']) ?></strong></a><?= $r['ico'] !== '' ? '<br><small class="muted">IČO ' . e($r['ico']) . '</small>' : '' ?><?= $r['active'] ? '' : ' <span class="tag">vypnuto</span>' ?></td>
      <td data-label="Domů"><?= (int) $r['houses'] ?></td><td data-label="Čeká na výbor"><?= (int) $r['pending'] ?: '—' ?></td>
      <td data-label="Pracovníků"><?= (int) $r['people'] ?></td><td data-label="Založeno"><?= cz_date(substr((string) $r['created_at'], 0, 10)) ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php else: ?><p class="empty">Zatím tu není žádný partner.</p><?php endif ?>
  <form method="post" class="card form">
    <h2>Přidat partnera</h2><?= csrf_field() ?>
      <?php $aresUrl = url('/ares'); $aresFirm = true; require __DIR__ . '/_ares.php'; ?>
    <div class="form-row"><label>Název firmy <input type="text" name="name" value="<?= e($in['name']) ?>" data-ares-field="name" required></label><label>IČO <input type="text" name="ico" data-ares-field="ico" value="<?= e($in['ico']) ?>" inputmode="numeric" maxlength="8"></label><label>DIČ <input type="text" name="dic" data-ares-field="dic" value="<?= e($in['dic']) ?>" maxlength="14"></label></div>
    <label>Sídlo (na faktury) <input type="text" name="address" data-ares-field="address" value="<?= e($in['address']) ?>"></label>
    <div class="form-row"><label>Fakturační e-mail <input type="email" name="email" value="<?= e($in['email']) ?>" required></label><label>Telefon <input type="text" name="phone" value="<?= e($in['phone']) ?>"></label></div>
    <fieldset><legend>První pracovník (správce účtu firmy)</legend>
      <div class="form-row"><label>Jméno a příjmení <input type="text" name="user_name" value="<?= e($in['user_name']) ?>" required></label><label>E-mail <input type="email" name="user_email" value="<?= e($in['user_email']) ?>" required></label></div>
      <p class="hint">Dostane e-mail s odkazem na nastavení hesla do partnerského portálu. Další kolegy si pozve sám.</p></fieldset>
    <button class="btn" type="submit">Přidat partnera</button>
  </form>
</div>
