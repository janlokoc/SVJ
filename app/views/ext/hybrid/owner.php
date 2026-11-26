<?php /* Modul H: shromáždění online pro vlastníka. $ev, $h, $error, $online, $units, $modes, $since, $motions, $myVotes, $codeStep, $sig */
$base = '/terminy/' . $ev['id'] . '/online'; ?>
<div class="wrap page narrow hybrid-owner"<?= $online ? ' data-hybrid-poll="' . e(url($base . '/stav')) . '" data-hybrid-sig="' . e($sig) . '"' : '' ?>>
  <p class="crumbs"><a href="<?= url('/terminy/' . $ev['id']) ?>">← <?= e($ev['title']) ?></a></p>
  <p class="eyebrow"><?= cz_date($ev['event_date'], true) ?><?= $ev['event_time'] ? ' v ' . e($ev['event_time']) : '' ?> · <?= e(HYBRID_FORMS[$h['form']]) ?></p>
  <h1>Shromáždění online</h1>

  <?php if ($online): ?>
    <div class="callout hybrid-status">
      <p><strong>Jste přítomen(a) online</strong> od <?= e(substr($since, 11, 5)) ?> za <?= count(array_filter($modes, fn($m) => $m === 'online')) === 1 ? 'jednotku' : 'jednotky' ?>
        <?= e(implode(', ', array_map(fn($un) => unit_name($un), array_filter($units, fn($un) => ($modes[(int) $un['id']] ?? '') === 'online')))) ?>.
        Vaše podíly se počítají do usnášeníschopnosti.</p>
      <p class="actions"><a class="btn" href="<?= e($h['video_url']) ?>" target="_blank" rel="noopener noreferrer">Otevřít videohovor</a>
        <span class="hint"><?= e(hybrid_provider($h['video_url'])) ?> se otevře v nové kartě. Tuto stránku nechte otevřenou – objeví se na ní návrhy k hlasování.</span></p>
    </div>

    <h2>Hlasování</h2>
    <?php if (!$motions): ?>
      <p class="muted" aria-live="polite">Právě se o ničem nehlasuje. Jakmile předsedající pustí návrh k hlasování, objeví se tady sám (stránka se obnovuje).</p>
    <?php endif ?>
    <?php foreach ($motions as $m): $mine = $myVotes[(int) $m['id']] ?? null; ?>
      <article class="card hybrid-motion" id="m<?= $m['id'] ?>">
        <h3><?= e($m['title']) ?></h3>
        <p class="muted">Potřeba: <?= e((MAJORITY[$m['majority']] ?? MAJORITY['half'])[0]) ?></p>
        <form method="post" action="<?= url($base) ?>" class="actions">
          <?= csrf_field() ?><input type="hidden" name="do" value="vote"><input type="hidden" name="motion_id" value="<?= $m['id'] ?>">
          <?php foreach (MOTION_CHOICES as $k => $label): ?>
            <button class="btn<?= $mine === $k ? '' : ' btn-ghost' ?>" type="submit" name="choice" value="<?= $k ?>"<?= $mine === $k ? ' aria-pressed="true"' : '' ?>><?= $label ?></button>
          <?php endforeach ?>
        </form>
        <p class="hint"><?= $mine ? 'Váš hlas: <strong>' . e(MOTION_CHOICES[$mine]) . '</strong>. Do uzavření hlasování ho můžete změnit.' : 'Zatím jste nehlasoval(a). Kdo z online přítomných nehlasuje, počítá se jako „zdržel se“.' ?></p>
      </article>
    <?php endforeach ?>

    <form method="post" action="<?= url($base) ?>" class="inline-form" data-confirm="Opravdu odcházíte? Přestanete být přítomen(a) a o dalších návrzích nebudete hlasovat."><?= csrf_field() ?>
      <button class="btn btn-ghost" name="do" value="leave" type="submit">Odcházím</button></form>
    <p class="hint">Když odejdete, Vaše podíly se přestanou počítat do přítomných. Hlasy u už uzavřených návrhů zůstávají. Vrátit se můžete stejným tlačítkem na stránce termínu.</p>

  <?php elseif ($error): ?>
    <div class="flash flash-info"><?= e($error) ?></div>

  <?php elseif ($codeStep): ?>
    <form method="post" action="<?= url($base) ?>" class="card form" data-hybrid-video="<?= e($h['video_url']) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="code">
      <p>Na Váš e-mail <strong><?= e(user()['email']) ?></strong> jsme poslali šestimístný kód. Platí 10 minut.</p>
      <label>Kód z e-mailu <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required></label>
      <button class="btn btn-big" type="submit">Ověřit a připojit se</button>
    </form>
    <form method="post" action="<?= url($base) ?>" class="inline-form"><?= csrf_field() ?><button class="link-btn" name="do" value="join" type="submit">Poslat nový kód</button></form>

  <?php else: ?>
    <form method="post" action="<?= url($base) ?>" class="card form"<?= hybrid_code_required() ? '' : ' data-hybrid-video="' . e($h['video_url']) . '"' ?>>
      <?= csrf_field() ?><input type="hidden" name="do" value="join">
      <p>Připojením se zapíšete do prezence jako přítomný vlastník za
        <?= e(implode(', ', array_map(fn($un) => unit_name($un), array_filter($units, fn($un) => in_array($modes[(int) $un['id']] ?? '', ['', 'online'], true))))) ?>.
        Web zaznamená čas připojení a IP adresu; způsob Vaší účasti se uvede v zápisu.</p>
      <?php if (hybrid_code_required()): ?><p class="hint">Pro ověření Vám pošleme kód na e-mail.</p><?php endif ?>
      <button class="btn btn-big" type="submit">Připojit se online</button>
      <p class="hint">Videohovor (<?= e(hybrid_provider($h['video_url'])) ?>) se otevře v nové kartě. Mikrofon a kameru povolíte v prohlížeči.</p>
    </form>
  <?php endif ?>
</div>
