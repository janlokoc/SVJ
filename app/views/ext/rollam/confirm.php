<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/hlasovani/' . $poll['id']) ?>">← <?= e($poll['title']) ?></a></p>
  <h1>Potvrzení hlasu</h1>
  <p class="lead">Jednotka <?= e(unit_name($unit)) ?>. Kód jsme poslali jako <?= e($target) ?>.</p>
  <?php require __DIR__ . '/../../_errors.php'; ?>
  <div class="card">
    <h2>Vaše volba</h2>
    <ol class="poll-items"><?php foreach ($items as $it): ?><li><?= e($it['question']) ?> — <strong><?= CHOICES[$choices[$it['id']] ?? ''] ?? 'bez hlasu' ?></strong></li><?php endforeach ?></ol>
  </div>
  <form method="post" action="<?= url('/hlasovani/potvrdit/' . $pending['id']) ?>" class="card form">
    <?= csrf_field() ?>
    <label>Kód (6 číslic) <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
    <button class="btn btn-big" type="submit">Potvrdit hlas</button>
    <p class="hint">Hlas se uloží až po zadání správného kódu. Kód platí 10 minut, zadat ho můžete nejvýše 5×.</p>
  </form>
  <form method="post" action="<?= url('/hlasovani/potvrdit/' . $pending['id'] . '/znovu') ?>">
    <?= csrf_field() ?><button class="btn btn-small btn-ghost" type="submit">Kód nepřišel – poslat nový</button>
  </form>
</div>
