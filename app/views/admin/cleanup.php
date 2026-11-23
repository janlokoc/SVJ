<?php /* Uvolnění místa: $months, $items, $big, $used, $limit */ ?>
<div class="wrap page narrow">
  <p><a href="<?= url('/admin/nastaveni') ?>#uloziste">← Nastavení webu</a></p>
  <header class="page-head"><h1>Uvolnit místo</h1>
    <p class="lead">Promažte staré záznamy, které už nepotřebujete. Smazání nejde vrátit – pokud si je chcete nechat, stáhněte si předtím zálohu webu.</p></header>
  <?php if ($used !== null): ?><p>Úložiště: <strong><?= number_format($used, 0, ',', "\u{a0}") ?> MB<?= $limit ? ' z ' . number_format($limit, 0, ',', "\u{a0}") . ' MB' : '' ?></strong>.</p><?php endif ?>
  <form method="get" action="<?= url('/admin/uvolnit-misto') ?>" class="cleanup-age"><label>Starší než <select name="mesice"><?php foreach ([6 => 'půl roku', 12 => '1 rok', 24 => '2 roky', 36 => '3 roky'] as $m => $label): ?><option value="<?= $m ?>"<?= $m === $months ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label> <button class="btn btn-small btn-ghost" type="submit">Zobrazit</button></form>
  <div class="cleanup-list">
  <?php foreach ($items as $key => [$name, $desc, $count, $bytes]): ?>
    <section class="card cleanup-item">
      <h2><?= e($name) ?></h2>
      <p class="muted"><?= e($desc) ?></p>
      <p><strong><?= $count ?></strong> <?= $count === 1 ? 'záznam' : ($count >= 2 && $count <= 4 ? 'záznamy' : 'záznamů') ?> starších než zvolené období<?= $bytes ? ' · zabírají ' . str_replace('.', ',', (string) round($bytes / 1048576, 1)) . ' MB' : ($key !== 'prilohy' ? ' · v úložišti téměř nezabírají místo, smazáním ale chráníte soukromí' : '') ?>.</p>
      <?php if ($count): ?>
      <form method="post" action="<?= url('/admin/uvolnit-misto') ?>" class="cleanup-form" data-confirm="Nenávratně smazat <?= e(mb_strtolower($name)) ?> starší než zvolené období (<?= $count ?>)?"><?= csrf_field() ?>
        <input type="hidden" name="co" value="<?= $key ?>"><input type="hidden" name="mesice" value="<?= $months ?>">
        <label class="check"><input type="checkbox" name="potvrdit" required> <span>Rozumím, že smazání nejde vrátit.</span></label>
        <button class="btn btn-ghost" type="submit">Smazat <?= $count ?></button>
      </form>
      <?php endif ?>
    </section>
  <?php endforeach ?>
  </div>
  <?php if ($big): ?>
  <h2>Největší dokumenty</h2>
  <p class="hint">Dokumenty se samy nemažou – zápisy a smlouvy bývá potřeba uchovat. Pokud některý nepotřebujete, smažete ho v Dokumentech.</p>
  <table class="table"><thead><tr><th>Dokument</th><th>Datum</th><th>Velikost</th></tr></thead><tbody>
    <?php foreach ($big as $d): ?><tr><td data-label="Dokument"><a href="<?= url('/admin/dokumenty/' . $d['id']) ?>"><?= e($d['title']) ?></a></td><td data-label="Datum"><?= cz_date($d['doc_date']) ?></td><td data-label="Velikost"><?= e(file_size((int) $d['size'])) ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php endif ?>
</div>
