<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/hlasovani') ?>">← Hlasování</a></p>
  <p class="post-meta"><span class="status status-poll-<?= $state ?>"><?= POLL_STATES[$state] ?></span> <?= cz_date($poll['opens_at']) ?> – <?= cz_date($poll['closes_at']) ?><?= $poll['online'] ? '' : ' · hlasuje se na shromáždění' ?></p>
  <h1><?= e($poll['title']) ?></h1>
  <div class="prose"><?= format_text((string) $poll['body']) ?></div>
  <?php require __DIR__ . '/_errors.php'; ?>

  <?php if ($poll['online'] && $state === 'open'): ?>
    <?php if (!$mine): ?><p class="note">Hlasuje vlastník za svou jednotku. K Vašemu účtu není přiřazena jednotka, nebo nejste vedeni jako vlastník – obraťte se na výbor.</p><?php endif ?>
    <?php foreach ($mine as $un): $voted = $myVotes[$un['id']] ?? null; ?>
      <form method="post" action="<?= url('/hlasovani/' . $poll['id']) ?>" class="card form poll-form" <?= $voted ? '' : 'data-confirm="Odeslat hlas za jednotku ' . e(unit_name($un)) . '? Po odeslání už ho na webu nezměníte."' ?>>
        <?= csrf_field() ?><input type="hidden" name="unit_id" value="<?= $un['id'] ?>">
        <h2>Jednotka <?= e(unit_name($un)) ?></h2>
        <?php if ($voted): ?>
          <p><span class="status status-done">Odhlasováno</span> <?= cz_datetime(reset($voted)['voted_at']) ?><?= reset($voted)['voter'] ? ' · ' . e(reset($voted)['voter']) : '' ?></p>
          <ol class="poll-items"><?php foreach ($items as $it): ?><li><?= e($it['question']) ?> — <strong><?= CHOICES[$voted[$it['id']]['choice'] ?? ''] ?? 'bez hlasu' ?></strong></li><?php endforeach ?></ol>
        <?php else: ?>
          <ol class="poll-items">
          <?php foreach ($items as $it): ?>
            <li><fieldset><legend><?= e($it['question']) ?></legend>
              <?php foreach (CHOICES as $k => $label): ?><label class="check"><input type="radio" name="choice[<?= $it['id'] ?>]" value="<?= $k ?>" required> <?= $label ?></label><?php endforeach ?>
            </fieldset></li>
          <?php endforeach ?>
          </ol>
          <?php if (function_exists('rollam_method')) { require __DIR__ . '/ext/rollam/_poll_note.php'; } ?>
          <button class="btn btn-big" type="submit">Odeslat hlas</button>
          <p class="hint">Za jednotku se hlasuje jednou; hlas platí za všechny její spoluvlastníky. Opravu po odeslání může provést jen výbor.</p>
        <?php endif ?>
      </form>
    <?php endforeach ?>
  <?php elseif ($mine && isset($myVotes)): ?>
    <?php foreach ($mine as $un): if (empty($myVotes[$un['id']])) continue; ?>
      <div class="card"><h2>Váš hlas – jednotka <?= e(unit_name($un)) ?></h2>
        <ol class="poll-items"><?php foreach ($items as $it): ?><li><?= e($it['question']) ?> — <strong><?= CHOICES[$myVotes[$un['id']][$it['id']]['choice'] ?? ''] ?? 'bez hlasu' ?></strong></li><?php endforeach ?></ol></div>
    <?php endforeach ?>
  <?php endif ?>

  <?php if ($showResults): ?>
    <h2><?= $state === 'closed' ? 'Výsledky' : 'Průběžné výsledky (vidí jen výbor)' ?></h2>
    <?php require __DIR__ . '/_poll_results.php'; ?>
  <?php elseif ($state === 'open'): ?>
    <p class="note">Zatím hlasovalo jednotek: <?= $results['voted'] ?> z <?= count($results['units']) ?>. Výsledky budou zveřejněny po ukončení hlasování.</p>
  <?php endif ?>
  <?php if (is_admin()): ?><p><a class="btn btn-small btn-ghost" href="<?= url('/admin/hlasovani/' . $poll['id'] . '/vysledky') ?>">Hlasy po jednotkách (výbor)</a></p><?php endif ?>
</div>
