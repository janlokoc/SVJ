<div class="wrap page">
  <header class="page-head"><?php if (function_exists('rej_nav_visible') && rej_nav_visible()): ?><p class="crumbs"><a href="<?= url('/admin/rejstriky') ?>">← Katastr a rejstříky</a></p><?php endif ?><h1>Insolvence vlastníků</h1>
    <p class="lead">Web jednou týdně ověří v insolvenčním rejstříku vlastníky jednotek, kteří jsou firmy, a když se u některého objeví řízení, pošle správcům e-mail. Na přihlášení pohledávky (dlužných záloh) má společenství zpravidla jen dva měsíce od rozhodnutí o úpadku.</p></header>
  <?php $betaKey = 'insolvence'; require __DIR__ . '/_beta.php'; ?>
  <?php if (!$premium): ?>
  <div class="card"><p>Hlídání insolvencí je součástí edice Standard a Premium. <a href="<?= url('/admin/predplatne') ?>#edice">Předplatné</a></p></div>
  <?php else: ?>
  <p class="hint">Firmy, družstva a obce se hlídají podle IČO. Fyzické osoby rejstřík dohledává podle jména a data narození – ty doplníte níže v části <a href="#osoby">Fyzické osoby</a>.
    <?= ($last[0] ?? '') !== '' ? 'Poslední kontrola ' . e($last[0]) . ': ' . e($last[2] ?? '') . '.' : '' ?></p>
  <?php if (!$units): ?>
  <div class="card"><p>Mezi vlastníky jednotek web nenašel žádnou firmu. Pozná ji podle označení v poli „Vlastník dle evidence“ (s.r.o., a.s., družstvo…) nebo podle IČO uvedeného u jednotky.</p></div>
  <?php else: ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <table class="table">
      <thead><tr><th>Jednotka</th><th>Vlastník</th><th>IČO</th><th>Insolvenční rejstřík</th></tr></thead>
      <tbody><?php foreach ($units as $uid => $c): $res = $c['ico'] !== '' ? ($results[$c['ico']] ?? null) : null; ?>
        <tr<?= $res && $res['found'] ? ' class="is-unmatched"' : '' ?>>
          <td data-label="Jednotka"><strong><?= e(unit_name($c['unit'])) ?></strong></td>
          <td data-label="Vlastník"><?= e(mb_strimwidth($c['name'], 0, 80, '…')) ?></td>
          <td data-label="IČO"><input type="text" name="ico[<?= (int) $uid ?>]" value="<?= e($c['ico']) ?>" inputmode="numeric" maxlength="8" size="9" aria-label="IČO vlastníka jednotky <?= e(unit_name($c['unit'])) ?>">
            <?= $c['source'] === 'text' ? '<br><small class="muted">z údajů u jednotky</small>' : '' ?></td>
          <td data-label="Rejstřík"><?php if ($c['ico'] === ''): ?><span class="muted">doplňte IČO</span>
            <?php elseif (!$res): ?><span class="muted">zatím neověřeno</span>
            <?php elseif (!$res['found']): ?><span class="status status-done">bez řízení</span> <small class="muted"><?= e($res['checked']) ?></small>
            <?php else: foreach ($res['found'] as $f): ?><strong><?= e($f['sz']) ?></strong><?= $f['state'] !== '' ? ' · ' . e($f['state']) : '' ?><?= $f['url'] !== '' ? ' · <a href="' . e($f['url']) . '" target="_blank" rel="noopener noreferrer">detail</a>' : '' ?><br><?php endforeach ?><small class="muted"><?= e($res['checked']) ?></small><?php endif ?></td>
        </tr>
      <?php endforeach ?></tbody>
    </table>
    <p class="hint">IČO najdete v obchodním rejstříku nebo v ARES podle názvu firmy. Prázdné pole = IČO se bere z údajů u jednotky, pokud tam je.</p>
    <div class="actions"><button class="btn" type="submit" name="action" value="save">Uložit IČO</button><button class="btn btn-ghost" type="submit" name="action" value="run">Zkontrolovat teď</button></div>
  </form>
  <?php endif ?>
  <?php if (function_exists('rej_persons')) { require __DIR__ . '/../ext/rejstriky/isir_persons.php'; } ?>
  <?php endif ?>
</div>
