<?php [$chainOk, $chainBad, $chainCount] = $chain; ?>
<div class="wrap page">
  <header class="page-head"><h1>Záznamy</h1>
    <p class="lead">Kdo se kdy přihlásil a co kdo v administraci změnil. Záznamy nejdou upravit ani smazat. Za posledních 24 hodin: <strong><?= $failed24 ?></strong> neúspěšných pokusů o přihlášení.</p></header>
  <?php if ($chainOk): ?>
    <div class="flash">Kontrola neporušenosti: v pořádku (ověřeno záznamů: <?= $chainCount ?>). Každý záznam nese otisk navazující na předchozí, takže dodatečné smazání nebo přepsání by se zde projevilo.</div>
  <?php else: ?>
    <div class="flash flash-error">Kontrola neporušenosti selhala u záznamu č. <?= $chainBad ?>. Některý záznam byl mimo web změněn nebo smazán (zásah přímo do databáze). Zjistěte, kdo má přístup k databázi na hostingu.</div>
  <?php endif ?>
  <form class="doc-filter" method="get" action="<?= url('/admin/zaznamy') ?>">
    <?php if ($only): ?><input type="hidden" name="jen-problemy" value="1"><?php endif ?>
    <label class="sr-only" for="audit-who">Kdo</label>
    <select name="kdo" id="audit-who"><option value="">Všichni</option><?php foreach ($people as $person): ?><option value="<?= e($person) ?>"<?= $who === $person ? ' selected' : '' ?>><?= e($person) ?></option><?php endforeach ?></select>
    <button class="btn" type="submit">Zobrazit</button>
  </form>
  <nav class="chips" aria-label="Filtr">
    <a href="<?= url('/admin/zaznamy' . ($who !== '' ? '?kdo=' . rawurlencode($who) : '')) ?>"<?= $only ? '' : ' aria-current="true"' ?>>Vše</a>
    <a href="<?= url('/admin/zaznamy?jen-problemy' . ($who !== '' ? '&kdo=' . rawurlencode($who) : '')) ?>"<?= $only ? ' aria-current="true"' : '' ?>>Jen podezřelé a citlivé</a>
  </nav>
  <?php if (!$rows): ?><p class="empty">Žádné záznamy.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Kdy</th><th>Kdo</th><th>Událost</th><th>Podrobnost</th><th>IP adresa</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $bad = str_contains($r['action'], 'failed'); ?>
      <tr>
        <td data-label="Kdy"><?= cz_datetime($r['at']) ?></td>
        <td data-label="Kdo"><?= e($r['user_name']) ?></td>
        <td data-label="Událost"><?= $bad ? '<span class="status status-new">' : '' ?><?= e(AUDIT_LABELS[$r['action']] ?? $r['action']) ?><?= $bad ? '</span>' : '' ?></td>
        <td data-label="Podrobnost"><?= e($r['detail']) ?></td>
        <td data-label="IP adresa"><?= e($r['ip']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php if (count($rows) === 500): ?><p class="hint">Zobrazeno posledních 500 záznamů; starší najdete filtrem podle osoby.</p><?php endif ?>
  <?php endif ?>
</div>
