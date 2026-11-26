<?php
/* Část stránky /admin/insolvence: hlídání insolvence vlastníků – fyzických osob (modul E). Proměnné si načte sama. */
$rejPersons = rej_persons();
$rejUnits = all_units();
$rejLast = explode('|', setting('rej_isir_last'), 3);
$rejSurnames = $rejFirst = [];
foreach ($rejUnits as $ru) {
    foreach (rej_owner_people((string) $ru['owner_note']) as $rp) {
        $rejSurnames[$rp['surname']] = true;
        $rejFirst[$rp['first']] = true;
    }
}
?>
<section class="card" id="osoby">
  <h2>Fyzické osoby</h2>
  <p>U vlastníků, kteří nejsou firmy, rejstřík hledá podle příjmení, jména a data narození. Datum narození doplňte jen u vlastníků, u kterých má hlídání smysl (např. dlužníci nebo pronajímané byty). Kontrola proběhne jednou týdně spolu s firmami.
    <?= ($rejLast[0] ?? '') !== '' ? 'Poslední kontrola osob ' . e($rejLast[0]) . ': ' . e($rejLast[2] ?? '') . '.' : '' ?></p>
  <p class="hint">Údaje vidí jen správci webu a slouží jen k ochraně pohledávek společenství (oprávněný zájem SVJ). Rodné číslo se nezadává ani neukládá. Když se v katastru převezme nový vlastník jednotky, údaje bývalého vlastníka se smažou. <?= help_link('katastr-a-rejstriky', 'Ochrana osobních údajů') ?></p>
  <?php if ($rejPersons): ?>
  <table class="table">
    <thead><tr><th>Jednotka</th><th>Vlastník</th><th>Datum narození</th><th>Insolvenční rejstřík</th><th></th></tr></thead>
    <tbody><?php foreach ($rejPersons as $p): $found = (array) json_decode((string) $p['isir_found'], true); ?>
      <tr<?= $found ? ' class="is-unmatched"' : '' ?>>
        <td data-label="Jednotka"><strong><?= $p['number'] !== null ? e(unit_name($p)) : '–' ?></strong></td>
        <td data-label="Vlastník"><?= e(trim($p['surname'] . ' ' . $p['first_name'])) ?></td>
        <td data-label="Narození"><?= e(cz_date($p['birth_date'])) ?></td>
        <td data-label="Rejstřík"><?php if ($p['isir_checked'] === ''): ?><span class="muted">zatím neověřeno</span>
          <?php elseif (!$found): ?><span class="status status-done">bez řízení</span> <small class="muted"><?= e($p['isir_checked']) ?></small>
          <?php else: foreach ($found as $f): ?><strong><?= e($f['sz']) ?></strong><?= $f['state'] !== '' ? ' · ' . e($f['state']) : '' ?><?= $f['url'] !== '' ? ' · <a href="' . e($f['url']) . '" target="_blank" rel="noopener noreferrer">detail</a>' : '' ?><br><?php endforeach ?><small class="muted"><?= e($p['isir_checked']) ?></small><?php endif ?></td>
        <td data-label=""><form method="post" action="<?= url('/admin/insolvence/osoby') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="btn btn-small btn-ghost" type="submit" name="action" value="delete">Nehlídat a smazat</button></form></td>
      </tr>
    <?php endforeach ?></tbody>
  </table>
  <form method="post" action="<?= url('/admin/insolvence/osoby') ?>" class="actions"><?= csrf_field() ?><button class="btn btn-ghost" type="submit" name="action" value="run">Zkontrolovat osoby teď</button></form>
  <?php endif ?>
  <form method="post" action="<?= url('/admin/insolvence/osoby') ?>" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <h3>Přidat osobu</h3>
    <label>Jednotka <select name="unit_id" required><option value="">– vyberte –</option><?php foreach ($rejUnits as $ru): ?><option value="<?= (int) $ru['id'] ?>"><?= e(unit_name($ru) . ($ru['owner_note'] !== '' ? ' – ' . mb_strimwidth($ru['owner_note'], 0, 50, '…') : '')) ?></option><?php endforeach ?></select></label>
    <label>Příjmení <input type="text" name="surname" maxlength="100" required list="rej-surnames" autocomplete="off"></label>
    <label>Jméno <input type="text" name="first_name" maxlength="100" list="rej-first" autocomplete="off"></label>
    <label>Datum narození <input type="date" name="birth_date" required max="<?= e(date('Y-m-d', strtotime('-15 years'))) ?>"></label>
    <datalist id="rej-surnames"><?php foreach (array_keys($rejSurnames) as $n): ?><option value="<?= e($n) ?>"><?php endforeach ?></datalist>
    <datalist id="rej-first"><?php foreach (array_keys($rejFirst) as $n): ?><option value="<?= e($n) ?>"><?php endforeach ?></datalist>
    <button class="btn" type="submit">Hlídat</button>
  </form>
</section>
