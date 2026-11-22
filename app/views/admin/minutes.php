<?php $t = strtotime($ev['event_date']); $present = $att['counts']['yes'] + $att['counts']['proxy']; ?>
<div class="wrap page narrow print-doc minutes">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= url('/admin/terminy') ?>">← Termíny</a> <button class="btn btn-small" type="button" data-print>Vytisknout nebo uložit jako PDF</button></p>
  <p class="hint no-print">Zápis je sestavený z účasti, programu a hlasování přiřazených k tomuto termínu. Text v rámečcích můžete před tiskem přímo na stránce doplnit; úpravy se neukládají, proto zápis rovnou vytiskněte nebo uložte jako PDF a nahrajte do Dokumentů.</p>
  <h1>Zápis ze shromáždění</h1>
  <p><strong><?= e(site('full')) ?></strong><?= setting('ico') !== '' ? ', IČO ' . e(setting('ico')) : '' ?><br><?= e(setting('address')) ?></p>
  <dl class="pay-data">
    <dt>Jednání</dt><dd><?= e($ev['title']) ?></dd>
    <dt>Datum a čas</dt><dd><?= cz_date($ev['event_date'], true) ?><?= $ev['event_time'] !== '' ? ' v ' . e($ev['event_time']) : '' ?></dd>
    <?php if ($ev['place'] !== ''): ?><dt>Místo</dt><dd><?= e($ev['place']) ?></dd><?php endif ?>
    <dt>Předsedající</dt><dd contenteditable="true" class="fill">&nbsp;</dd>
    <dt>Zapisovatel</dt><dd contenteditable="true" class="fill">&nbsp;</dd>
  </dl>
  <h2>1. Účast a usnášeníschopnost</h2>
  <p>Přítomni jsou vlastníci <?= $present ?> z <?= (int) $att['units'] ?> jednotek (osobně <?= (int) $att['counts']['yes'] ?>, v zastoupení <?= (int) $att['counts']['proxy'] ?>), kteří mají <strong><?= percent_cz($att['percent']) ?></strong> <?= $att['byShare'] ? 'všech hlasů podle spoluvlastnických podílů' : 'všech hlasů (jeden hlas na jednotku)' ?>.
    Shromáždění <strong><?= $att['quorate'] ? 'je' : 'není' ?> usnášeníschopné</strong> (potřeba je více než <?= (int) $att['threshold'] ?> %). Prezenční listina je přílohou zápisu.</p>
  <?php if ($ev['program'] !== ''): ?><h2>2. Program</h2><div class="prose"><?= format_text($ev['program']) ?></div><?php endif ?>
  <h2><?= $ev['program'] !== '' ? '3' : '2' ?>. Průběh jednání</h2>
<?php
    $aiContext = 'Jednání: ' . $ev['title'] . ', ' . cz_date($ev['event_date'], true) . ($ev['event_time'] !== '' ? ' v ' . $ev['event_time'] : '') . ($ev['place'] !== '' ? ', ' . $ev['place'] : '')
        . "\nÚčast: " . percent_cz($att['percent']) . ' hlasů, shromáždění ' . ($att['quorate'] ? 'je' : 'není') . ' usnášeníschopné.'
        . ($ev['program'] !== '' ? "\nProgram:\n" . $ev['program'] : '');
    foreach ($polls as $p) {
        foreach ($p['res']['items'] as $it) {
            $aiContext .= "\nHlasování – " . $it['question'] . ': ' . ($it['passed'] ? 'přijato' : 'nepřijato');
        }
    }
  ?>
  <?php if (ai_available()): ?>
  <form class="card form no-print minutes-ai">
    <?= csrf_field() ?>
    <label>Poznámky nebo přepis ze schůze <textarea name="notes" rows="7" placeholder="Stačí heslovitě: kdo zahájil, co kdo řekl k jednotlivým bodům, co se dohodlo, kdo co zařídí…"></textarea></label>
    <label class="transcript-upload">Nebo nahrajte přepis ze schůze (.txt, .docx, .vtt, .srt)
      <input type="file" accept=".txt,.md,.vtt,.srt,.docx" data-transcript-url="<?= url('/admin/prepis') ?>" data-transcript-target="textarea[name=notes]">
      <span class="hint" data-transcript-status>Přepis vytvoří například Teams, Google Meet, Zoom nebo diktafon v telefonu. Text se načte do pole výše a soubor se nikam neukládá.</span></label>
    <?php $aiTarget = 'textarea[name=notes]'; $aiTasks = ['minutes']; $aiOutput = '#minutes-body'; require __DIR__ . '/_ai.php'; ?>
    <p class="hint">Pomocník z poznámek napíše část „Průběh jednání“ níže. Výsledek si přečtěte a opravte – za zápis odpovídá zapisovatel a ověřovatelé. Poznámky se neukládají.</p>
  </form>
  <?php endif ?>
  <div contenteditable="true" class="fill fill-block" id="minutes-body">Sem doplňte průběh jednání, přednesené zprávy a diskuzi.</div>
  <?php $n = $ev['program'] !== '' ? 4 : 3; foreach ($polls as $p): ?>
    <h2><?= $n++ ?>. Hlasování: <?= e($p['poll']['title']) ?></h2>
    <?php foreach ($p['res']['items'] as $i => $it): ?>
      <p><strong><?= $i + 1 ?>) <?= e($it['question']) ?></strong><br>
        Pro <?= percent_cz($it['percent']['yes']) ?> (<?= (int) $it['count']['yes'] ?> jednotek), proti <?= percent_cz($it['percent']['no']) ?> (<?= (int) $it['count']['no'] ?>), zdrželo se <?= percent_cz($it['percent']['abstain']) ?> (<?= (int) $it['count']['abstain'] ?>).
        Usnesení <strong><?= $it['passed'] ? 'bylo přijato' : 'nebylo přijato' ?></strong> (potřeba je více než <?= (int) $p['res']['threshold'] ?> % všech hlasů).</p>
    <?php endforeach ?>
    <p class="minutes-hash">Otisk odevzdaných hlasů (SHA-256): <code><?= e($p['hash']) ?></code></p>
  <?php endforeach ?>
  <?php if (!$polls): ?><div class="callout no-print">K tomuto termínu není přiřazené žádné hlasování. Výsledky hlasování se do zápisu doplní samy, když u hlasování v administraci vyberete tento termín.</div><?php endif ?>
  <?php if ($docs): ?><h2><?= $n++ ?>. Podklady</h2><ul><?php foreach ($docs as $d): ?><li><?= e($d['title']) ?></li><?php endforeach ?></ul><?php endif ?>
  <h2><?= $n ?>. Závěr</h2>
  <div contenteditable="true" class="fill fill-block">Předsedající ukončil shromáždění v …… hodin.</div>
  <p>V <?= e(explode(',', setting('address'))[1] ?? '…………') ?> dne <?= cz_date(date('Y-m-d'), true) ?></p>
  <div class="sign-row"><div>...............................................<br>předsedající</div><div>...............................................<br>zapisovatel</div></div>
</div>
