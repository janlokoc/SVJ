<?php /* AI asistent: $history [[q, a, sources, at]], $error, $q */
$examples = array_slice(assistant_examples(), 0, 4); ?>
<div class="wrap page narrow assistant">
  <header class="page-head"><h1>AI asistent</h1>
    <p class="lead">Zeptejte se běžnou řečí na cokoli o domě. Asistent odpoví podle stanov, domovního řádu, dokumentů a oznámení na webu a uvede, odkud odpověď má.</p></header>

  <?php if ($history): ?>
  <ol class="chat-log">
    <?php foreach ($history as $i => $h): ?>
      <li class="chat-q"<?= $i === count($history) - 1 ? ' id="posledni"' : '' ?>><span class="sr-only">Vaše otázka: </span><?= nl2br(e($h['q'])) ?></li>
      <li class="chat-a"><span class="sr-only">Odpověď asistenta: </span><?= assistant_entry_html($h) ?></li>
    <?php endforeach ?>
  </ol>
  <?php endif ?>

  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
  <form method="post" action="<?= url('/asistent') ?>" class="card form chat-form">
    <?= csrf_field() ?>
    <label for="asistent-q"><?= $history ? 'Další otázka' : 'Vaše otázka' ?></label>
    <textarea id="asistent-q" name="q" rows="3" maxlength="1000" required placeholder="Např. Kdy se platí zálohy do fondu oprav?"<?= $history ? '' : ' autofocus' ?>><?= e($q) ?></textarea>
    <div class="actions"><button class="btn" type="submit">Zeptat se</button>
      <?php if ($history): ?><button class="btn btn-ghost" type="submit" name="do" value="nova" formnovalidate>Nový rozhovor</button><?php endif ?></div>
    <?php if (!$history): ?><p class="hint">Například: <?php foreach ($examples as $i => $ex): ?><button class="link-btn chat-example" type="button" data-fill="#asistent-q"><?= e($ex) ?></button><?= $i < count($examples) - 1 ? ' · ' : '' ?><?php endforeach ?></p><?php endif ?>
  </form>
  <p class="hint">Odpovědi připravuje umělá inteligence (Claude od společnosti Anthropic) z podkladů tohoto webu a může se splést – u důležitých věcí si zdroj otevřete nebo se zeptejte výboru. Otázka a vybrané úryvky podkladů se ke zpracování odesílají této službě; nepište do otázky osobní údaje. Rozhovor se na webu neukládá.</p>
</div>
