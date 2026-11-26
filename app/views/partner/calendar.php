<?php $months = []; foreach ($items as $it) { $months[substr($it['date'], 0, 7) < date('Y-m') ? 'late' : substr($it['date'], 0, 7)][] = $it; } ?>
<div class="wrap page">
  <header class="page-head"><h1>Kalendář domů</h1>
    <p class="lead">Termíny, revize, termíny hlášení a konce hlasování ze všech domů na jednom místě – na 120 dní dopředu a vše, co je po termínu.</p></header>
  <form method="get" class="filter-row">
    <label>Dům <select name="dum" data-autosubmit><option value="">Všechny domy</option><?php foreach ($houses as $h): ?><option value="<?= e($h['slug']) ?>"<?= $house === $h['slug'] ? ' selected' : '' ?>><?= e($h['name']) ?></option><?php endforeach ?></select></label>
    <label>Druh <select name="druh" data-autosubmit><option value="">Vše</option><?php foreach (PARTNER_CAL_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></label>
    <button class="btn btn-small btn-ghost" type="submit">Zobrazit</button>
  </form>
  <?php if (!$items): ?><p class="empty">V tomto výběru nejsou žádné termíny.</p><?php endif ?>
  <?php foreach ($months as $m => $list): ?>
    <h2 class="cal-month"><?= $m === 'late' ? 'Po termínu' : e(cz_month_short($m)) ?></h2>
    <ul class="cal-list">
      <?php foreach ($list as $it): ?><li class="cal-item cal-<?= $it['kind'] ?><?= $it['late'] ? ' is-late' : '' ?>">
        <span class="cal-date"><?= cz_date($it['date']) ?><?= $it['time'] !== '' ? '<br><small>' . e($it['time']) . '</small>' : '' ?></span>
        <span class="cal-what"><span class="tag"><?= e(PARTNER_CAL_KINDS[$it['kind']]) ?></span><?= $it['late'] ? ' <span class="tag tag-late">po termínu</span>' : '' ?> <strong><?= e($it['title']) ?></strong><br><small class="muted"><?= e($it['house']) ?></small></span>
        <a class="btn btn-small btn-ghost" href="<?= url('/domy/' . $it['slug'] . '/vstoupit' . ($it['k'] !== '' ? '?' . (str_starts_with($it['k'], 'zavada=') ? $it['k'] : 'k=' . $it['k']) : '')) ?>">Otevřít</a></li>
      <?php endforeach ?>
    </ul>
  <?php endforeach ?>
  <section class="card" id="odber">
    <h2>Kalendář v telefonu nebo v Outlooku</h2>
    <?php if ($icsUrl !== ''): ?>
      <p>Tuto adresu přidejte do svého kalendáře jako <strong>odběr z adresy URL</strong> (Google Kalendář: Další kalendáře → Z adresy URL; Outlook: Přidat kalendář → Z internetu). Termíny se pak samy aktualizují a den předem přijde připomínka.</p>
      <p><code class="totp-key"><?= e($icsUrl) ?></code></p>
      <p class="hint">Odkaz je tajný – kdo ho zná, vidí termíny Vašich domů. Když unikne, vytvořte nový; starý přestane fungovat.</p>
    <?php else: ?><p>Termíny všech domů si můžete nechat zobrazovat přímo ve svém kalendáři (Google, Outlook, telefon), s připomínkou den předem.</p><?php endif ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="token"><button class="btn<?= $icsUrl !== '' ? ' btn-ghost btn-small' : '' ?>" type="submit"><?= $icsUrl !== '' ? 'Vytvořit nový odkaz' : 'Získat odkaz pro kalendář' ?></button></form>
  </section>
</div>
