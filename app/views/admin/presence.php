<?php /* Prezence na místě: $ev, $p (presence()), $errors */
$s = presence_summary($p); $base = '/admin/terminy/' . $ev['id'] . '/prezence-na-miste'; ?>
<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/admin/terminy/' . $ev['id'] . '/ucast') ?>">← Přihlášky na termín</a></p>
  <header class="page-head"><h1>Prezence na místě</h1>
    <p class="lead"><?= e($ev['title']) ?> · <?= cz_date($ev['event_date'], true) ?><?= $ev['event_time'] ? ' v ' . e($ev['event_time']) : '' ?>. U každé jednotky klikněte, zda je vlastník přítomen osobně, nebo ho zastupuje zmocněnec – podíly se sčítají hned.</p></header>
  <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach ?>
  <?php if (!$p['units']): ?>
    <p class="empty">Nejdřív založte jednotky v sekci <a href="<?= url('/admin/jednotky') ?>">Jednotky</a>.</p>
  <?php else: ?>
  <div class="quorum presence-bar<?= $p['quorate'] ? ' is-ok' : '' ?>" data-presence-summary aria-live="polite">
    <p class="eyebrow">Přítomno (osobně nebo v zastoupení)</p>
    <p class="quorum-num"><span data-p="percent"><?= $s['percent'] ?></span> <small><?= $p['byShare'] ? 'všech hlasů podle podílů' : 'jednotek' ?></small></p>
    <div class="meter" role="img" aria-label="Přítomno, potřeba více než <?= $p['threshold'] ?> %"><span class="meter-fill" data-width="<?= $s['width'] ?>"></span><span class="meter-mark" data-left="<?= $p['threshold'] ?>"></span></div>
    <p><strong data-p="verdict"><?= e($s['verdict']) ?></strong> Hranice: více než <?= $p['threshold'] ?> %.</p>
    <p class="quorum-counts">Osobně <strong data-p="self"><?= $s['self'] ?></strong> · zmocněnci <strong data-p="proxy"><?= $s['proxy'] ?></strong> · nepřítomno <strong data-p="absent"><?= $s['absent'] ?></strong> z <?= $p['units'] ?> jednotek<span data-p="missing-wrap"<?= $s['missingDocs'] ? '' : ' hidden' ?>> · <span class="warn">zmocněnci bez předložené plné moci: <strong data-p="missingDocs"><?= $s['missingDocs'] ?></strong></span></span></p>
  </div>
  <?php if (!$p['byShare']): ?><div class="flash flash-info">Některé jednotky nemají vyplněný spoluvlastnický podíl, proto se počítá každá jednotka jako jeden hlas. Podíly doplníte v sekci <a href="<?= url('/admin/jednotky') ?>">Jednotky</a>.</div><?php endif ?>

  <table class="table presence-table">
    <thead><tr><th>Jednotka</th><th>Vlastník</th><th>Podíl</th><th>Přítomnost</th><th>Plná moc předem</th></tr></thead>
    <tbody>
    <?php foreach ($p['rows'] as $r): $mode = $r['mode'] ?? ''; $proxy = $r['p_proxy'] ?: ($r['rsvp'] === 'proxy' ? $r['rsvp_proxy'] : ''); ?>
      <tr id="j<?= $r['id'] ?>" class="presence-row<?= $mode ? ' is-present' : '' ?>" data-mode="<?= e($mode) ?>">
        <td data-label="Jednotka"><strong><?= e(unit_name($r)) ?></strong></td>
        <td data-label="Vlastník"><?= e($r['owner_note']) ?><?php if ($r['rsvp']): ?><br><small class="muted">Přihláška: <?= e(mb_strtolower(RSVP[$r['rsvp']])) ?><?= $r['rsvp'] === 'proxy' && $r['rsvp_proxy'] ? ' (' . e($r['rsvp_proxy']) . ')' : '' ?></small><?php endif ?></td>
        <td data-label="Podíl"><?= e($r['share']) ?><?php if ($p['byShare']): ?><br><small class="muted"><?= e(str_replace('.', ',', (string) round($r['pct'], 2))) ?> %</small><?php endif ?></td>
        <td data-label="Přítomnost">
          <form method="post" action="<?= url($base) ?>" class="presence-form">
            <?= csrf_field() ?><input type="hidden" name="unit_id" value="<?= $r['id'] ?>">
            <fieldset class="seg"><legend class="sr-only">Přítomnost jednotky <?= e(unit_name($r)) ?></legend>
              <label><input type="radio" name="mode" value=""<?= $mode === '' ? ' checked' : '' ?>><span>Nepřítomen</span></label>
              <?php foreach (PRESENCE as $k => $label): ?><label><input type="radio" name="mode" value="<?= $k ?>"<?= $mode === $k ? ' checked' : '' ?>><span><?= $label ?></span></label><?php endforeach ?>
            </fieldset>
            <div class="presence-proxy"<?= $mode === 'proxy' ? '' : ' hidden' ?>>
              <label><span class="sr-only">Jméno zmocněnce</span><input type="text" name="proxy_name" value="<?= e($proxy) ?>" placeholder="jméno zmocněnce" maxlength="190"></label>
              <?php if (!$r['file_id']): ?><label class="check"><input type="checkbox" name="proxy_ok" value="1"<?= $r['proxy_ok'] ? ' checked' : '' ?>> plná moc předložena</label><?php endif ?>
            </div>
            <button class="btn btn-small btn-ghost presence-save" type="submit">Uložit</button>
            <span class="presence-state" aria-live="polite"></span>
          </form>
        </td>
        <td data-label="Plná moc předem">
          <?php if ($r['file_id']): ?>
            <a href="<?= url('/terminy/' . $ev['id'] . '/plna-moc/soubor?jednotka=' . $r['id']) ?>" target="_blank" rel="noopener">✓ nahrána předem</a>
          <?php elseif ($mode === 'proxy' && $r['proxy_ok']): ?>✓ předložena<?php else: ?><span class="muted">–</span>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <section class="motions" id="navrhy">
    <h2>Hlasování o návrzích</h2>
    <p class="hint">Přečtěte návrh, zeptejte se „Kdo je proti? Kdo se zdržel?“ a u těch jednotek to klikněte. Ostatní přítomní se počítají jako pro. Procenta jsou z hlasů přítomných podle podílů. Po skončení hlasování návrh uzavřete – výsledek se zafixuje a pozdější odchody ho nezmění.</p>
    <?php foreach ($motions as $i => $m): $r = $m['res']; $ms = motion_summary($r); $closed = $m['status'] === 'closed'; ?>
      <article class="card motion<?= $r['passed'] ? ' is-passed' : '' ?><?= $closed ? ' is-closed' : '' ?>" id="m<?= $m['id'] ?>" data-motion="<?= $m['id'] ?>">
        <header class="motion-head"><h3><?= $i + 1 ?>. <?= e($m['title']) ?></h3>
          <p class="muted">Potřeba: <?= e((MAJORITY[$m['majority']] ?? MAJORITY['half'])[0]) ?><?= $closed ? ' · uzavřeno ' . e(cz_datetime($m['closed_at'])) : '' ?></p></header>
        <div class="motion-bar" role="img" aria-label="Pro <?= $ms['yes'] ?>, proti <?= $ms['no'] ?>, zdrželo se <?= $ms['abstain'] ?>">
          <span class="mb-yes meter-fill" data-width="<?= $ms['wyes'] ?>"></span><span class="mb-no meter-fill" data-width="<?= $ms['wno'] ?>"></span><span class="mb-abstain meter-fill" data-width="<?= $ms['wabstain'] ?>"></span>
        </div>
        <p class="motion-counts">Pro <strong data-m="yes"><?= $ms['yes'] ?></strong> (<span data-m="cyes"><?= $ms['cyes'] ?></span>) · proti <strong data-m="no"><?= $ms['no'] ?></strong> (<span data-m="cno"><?= $ms['cno'] ?></span>) · zdrželo se <strong data-m="abstain"><?= $ms['abstain'] ?></strong> (<span data-m="cabstain"><?= $ms['cabstain'] ?></span>) <small class="muted">– procenta z přítomných, v závorce počet jednotek</small></p>
        <p class="motion-verdict"><strong data-m="verdict"><?= e($ms['verdict']) ?></strong></p>
        <?php if (!$closed): ?>
        <details class="motion-votes"<?= $i === count($motions) - 1 ? ' open' : '' ?>><summary>Kdo je proti a kdo se zdržel</summary>
          <ul class="vote-list">
          <?php foreach ($p['rows'] as $u): $c = $r['votes'][(int) $u['id']] ?? 'yes'; ?>
            <li data-unit="<?= $u['id'] ?>"<?= $u['mode'] ? '' : ' hidden' ?>>
              <form method="post" action="<?= url($base) ?>" class="vote-form"><?= csrf_field() ?><input type="hidden" name="do" value="vote"><input type="hidden" name="motion_id" value="<?= $m['id'] ?>"><input type="hidden" name="unit_id" value="<?= $u['id'] ?>">
                <span class="vote-unit"><strong><?= e(unit_name($u)) ?></strong> <small class="muted"><?= e($u['owner_note']) ?></small></span>
                <fieldset class="seg seg-small"><legend class="sr-only">Hlas jednotky <?= e(unit_name($u)) ?></legend>
                  <?php foreach (MOTION_CHOICES as $k => $label): ?><label><input type="radio" name="choice" value="<?= $k ?>"<?= $c === $k ? ' checked' : '' ?>><span><?= $label ?></span></label><?php endforeach ?>
                </fieldset>
                <button class="btn btn-small btn-ghost presence-save" type="submit">Uložit</button>
              </form></li>
          <?php endforeach ?>
          </ul>
          <p class="hint" data-none-present<?= $p['counts']['yes'] + $p['counts']['proxy'] ? ' hidden' : '' ?>>Zatím není nikdo přítomen – nejdřív vyplňte prezenci výše.</p>
        </details>
        <?php endif ?>
        <div class="actions">
          <form method="post" action="<?= url($base) ?>" class="inline-form"<?= $closed ? '' : ' data-confirm="Uzavřít hlasování? Výsledek se zafixuje."' ?>><?= csrf_field() ?><input type="hidden" name="motion_id" value="<?= $m['id'] ?>"><button class="btn btn-small<?= $closed ? ' btn-ghost' : '' ?>" name="do" value="<?= $closed ? 'motion-reopen' : 'motion-close' ?>" type="submit"><?= $closed ? 'Znovu otevřít' : 'Uzavřít hlasování' ?></button></form>
          <?php if (!$closed): ?><details class="motion-edit"><summary>upravit znění</summary>
            <form method="post" action="<?= url($base) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="motion-edit"><input type="hidden" name="motion_id" value="<?= $m['id'] ?>">
              <label>Znění návrhu <input type="text" name="title" value="<?= e($m['title']) ?>" maxlength="300" required></label>
              <label>Potřebná většina <select name="majority"><?php foreach (MAJORITY as $k => $mj): ?><option value="<?= $k ?>"<?= $m['majority'] === $k ? ' selected' : '' ?>><?= e($mj[0]) ?></option><?php endforeach ?></select></label>
              <button class="btn btn-small" type="submit">Uložit</button></form></details><?php endif ?>
          <form method="post" action="<?= url($base) ?>" class="inline-form" data-confirm="Smazat návrh včetně hlasů?"><?= csrf_field() ?><input type="hidden" name="motion_id" value="<?= $m['id'] ?>"><button class="link-btn" name="do" value="motion-delete" type="submit">smazat návrh</button></form>
        </div>
      </article>
    <?php endforeach ?>
    <?php $have = array_flip(array_column($motions, 'title')); $points = array_filter(program_points($ev['program']), fn($pt) => !isset($have[$pt[0]])); ?>
    <?php if ($points): ?>
    <form method="post" action="<?= url($base) ?>" class="card form motion-program"><?= csrf_field() ?><input type="hidden" name="do" value="motion-program">
      <h3>Návrhy z programu</h3>
      <p class="hint">Zaškrtnuté body programu se stanou návrhy k hlasování (nadpoloviční většina přítomných; u každého ji pak můžete změnit). Zahájení, diskuzi a ukončení web nepředvybral – hlasuje se jen o bodech, o kterých se rozhoduje.</p>
      <fieldset class="checks"><?php foreach ($points as $i => [$text, $vote]): ?><label class="check"><input type="checkbox" name="points[]" value="<?= $i ?>"<?= $vote ? ' checked' : '' ?>> <?= e($text) ?></label><?php endforeach ?></fieldset>
      <button class="btn" type="submit">Vytvořit návrhy</button>
    </form>
    <?php elseif ($ev['program'] === '' && !$motions): ?>
      <p class="hint">Tip: když k termínu napíšete <a href="<?= url('/admin/terminy/' . $ev['id']) ?>">program</a>, web z jeho bodů nabídne návrhy k hlasování.</p>
    <?php endif ?>
    <form method="post" action="<?= url($base) ?>" class="card form motion-new"><?= csrf_field() ?><input type="hidden" name="do" value="motion-add">
      <h3>Nový návrh k hlasování</h3>
      <label>Znění návrhu usnesení <input type="text" name="title" maxlength="300" required placeholder="např. Shromáždění schvaluje opravu střechy za nejvýše 850 000 Kč"></label>
      <label>Potřebná většina <select name="majority"><?php foreach (MAJORITY as $k => $mj): ?><option value="<?= $k ?>"><?= e(mb_strtoupper(mb_substr($mj[0], 0, 1)) . mb_substr($mj[0], 1)) ?></option><?php endforeach ?></select></label>
      <p class="hint">Běžně stačí nadpoloviční většina přítomných. Vyšší většinu (např. změna stanov, podílů nebo prodej společných částí) určují zákon a stanovy – ověřte si je.</p>
      <button class="btn" type="submit">Přidat návrh</button>
    </form>
  </section>

  <p class="actions">
    <a class="btn btn-ghost" href="<?= url('/admin/terminy/' . $ev['id'] . '/prezence') ?>">Prezenční listina k tisku</a>
    <a class="btn btn-ghost" href="<?= url('/admin/terminy/' . $ev['id'] . '/zapis') ?>">Zápis ze shromáždění</a>
  </p>
  <form method="post" action="<?= url($base) ?>" class="inline-form" data-confirm="Opravdu vynulovat celou prezenci? Nahrané plné moci zůstanou."><?= csrf_field() ?><button class="btn btn-small btn-ghost" name="do" value="reset" type="submit">Vynulovat prezenci</button></form>
  <p class="hint">Zápis ze shromáždění počítá usnášeníschopnost z této prezence, jakmile je v ní zapsaná alespoň jedna jednotka. Zmocněnec přinese podepsanou plnou moc vytištěnou – zaškrtněte „plná moc předložena“. Plné moci, které vlastníci nahráli předem k pozvánce, jsou u jednotky vidět. Zastoupení bez plné moci se do součtu počítá, web na něj jen upozorní.</p>
  <?php endif ?>
</div>
