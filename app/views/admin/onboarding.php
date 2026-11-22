<?php /* Průvodce pro nového správce: $o z onboarding_summary() */
$left = count($o['waiting']); $n = 0; ?>
<div class="wrap page narrow">
  <header class="page-head"><h1>Průvodce nastavením</h1>
    <p class="lead">Co udělat, než web ukážete sousedům. Kroky nemusíte dělat popořadě ani najednou – průvodce si pamatuje, kde jste skončili.</p></header>
  <div class="card onb-progress<?= $left ? '' : ' is-done' ?>">
    <p><strong><?= $left ? 'Hotovo ' . $o['done'] . ' z ' . $o['total'] . ' kroků' : 'Všechno je hotovo' ?></strong><?= $left ? ' · čeká ' . $left . ($o['important'] ? ', z toho důležitých ' . $o['important'] : '') : ' – web je připravený.' ?></p>
    <progress class="bar" max="<?= $o['total'] ?>" value="<?= $o['done'] ?>"><?= $o['done'] ?> z <?= $o['total'] ?></progress>
    <p class="onb-legend"><span class="light light-done"></span> hotovo <span class="light light-must"></span> čeká – důležité <span class="light light-wait"></span> čeká <span class="light light-skip"></span> přeskočeno</p>
  </div>
  <?php foreach (ONBOARDING_GROUPS as $gk => $gname): $rows = array_filter($o['steps'], fn($s) => $s['group'] === $gk); if (!$rows) { continue; } ?>
  <h2><?= e($gname) ?></h2>
  <ol class="onb-list" start="<?= $n + 1 ?>">
    <?php foreach ($rows as $s): $n++; $light = $s['state'] === 'wait' && $s['important'] ? 'must' : $s['state']; ?>
    <li class="onb-step is-<?= e($light) ?>" id="krok-<?= e($s['key']) ?>">
      <span class="light light-<?= e($light) ?>" aria-hidden="true"></span>
      <div class="onb-body">
        <h3><?= e($s['title']) ?> <span class="onb-state"><?= ['done' => 'hotovo', 'must' => 'čeká – důležité', 'wait' => 'čeká', 'skip' => 'přeskočeno'][$light] ?></span></h3>
        <p><?= e($s['why']) ?></p>
        <div class="onb-actions">
          <a class="btn btn-small<?= $s['state'] === 'wait' ? '' : ' btn-ghost' ?>" href="<?= url($s['href']) ?>"><?= e($s['state'] === 'wait' ? $s['label'] : 'Otevřít') ?></a>
          <?php if (!$s['auto']): ?><form method="post" action="<?= url('/admin/pruvodce/' . $s['key']) ?>" class="inline-form"><?= csrf_field() ?>
            <?php if ($s['state'] === 'wait'): ?><button class="btn btn-small btn-ghost" name="state" value="done" type="submit">Mám hotovo</button>
              <button class="btn btn-small btn-ghost" name="state" value="skip" type="submit">Nepotřebujeme</button>
            <?php else: ?><button class="btn btn-small btn-ghost" name="state" value="wait" type="submit">Vrátit mezi čekající</button><?php endif ?>
          </form><?php else: ?><small class="muted">Poznáno podle obsahu webu.</small><?php endif ?>
        </div>
      </div>
    </li>
    <?php endforeach ?>
  </ol>
  <?php endforeach ?>
  <form method="post" action="<?= url('/admin/pruvodce/skryt') ?>" class="onb-hide"><?= csrf_field() ?>
    <?php if ($o['hidden']): ?><input type="hidden" name="show" value="1"><button class="btn btn-small btn-ghost" type="submit">Znovu ukazovat průvodce na přehledu</button>
    <?php elseif ($left): ?><button class="btn btn-small btn-ghost" type="submit">Neukazovat průvodce na přehledu</button><?php endif ?>
  </form>
</div>
