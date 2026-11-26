<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/hlasovani') ?>">← Hlasování</a></p>
  <h1><?= e($title) ?></h1>
  <?php if (!empty($sharesBlock)): require __DIR__ . '/_shares_needed.php'; else: ?>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Název <input type="text" name="title" value="<?= e($poll['title']) ?>" maxlength="200" required placeholder="Např. Hlasování o výměně stoupaček"></label>
    <label>Úvodní text a odůvodnění <textarea name="body" rows="5"><?= e((string) $poll['body']) ?></textarea></label>
    <?php $aiTarget = 'textarea[name=body]'; $aiTasks = ['rationale', 'polish']; $aiContext = ''; $aiFields = ['title' => 'Název hlasování', 'items' => 'Návrhy usnesení', 'opens_at' => 'Hlasuje se od', 'closes_at' => 'Hlasuje se do (včetně)']; require __DIR__ . '/_ai.php'; unset($aiFields); ?>
    <label>Body, o kterých se hlasuje (každý na samostatný řádek)
      <textarea name="items" rows="5"<?= $hasVotes ? ' disabled' : ' required' ?> placeholder="Shromáždění schvaluje výměnu stoupaček dle nabídky…"><?= e($itemsText) ?></textarea></label>
    <?php if (!$hasVotes): $aiTarget = 'textarea[name=items]'; $aiTasks = ['resolution']; $aiContext = ''; $aiFields = ['title' => 'Název hlasování', 'body' => 'Odůvodnění']; require __DIR__ . '/_ai.php'; unset($aiFields); endif ?>
    <p class="hint"><?= $hasVotes ? 'Body už nejde měnit, protože byly odevzdány hlasy.' : 'Formulujte je jako návrh usnesení; vlastníci u každého zvolí pro, proti nebo zdržuji se.' ?></p>
    <div class="form-row">
      <label>Hlasuje se od <input type="date" name="opens_at" value="<?= e($poll['opens_at']) ?>" required></label>
      <label>do (včetně) <input type="date" name="closes_at" value="<?= e($poll['closes_at']) ?>" required></label>
      <label>Přijato při více než (% všech hlasů) <input type="number" name="threshold" min="1" max="99" value="<?= (int) $poll['threshold'] ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="online"<?= $poll['online'] ? ' checked' : '' ?>> <span>Vlastníci hlasují sami přes web <small>Když volbu vypnete, hlasy zapisuje jen výbor – hodí se pro hlasování na shromáždění nebo na papírových lístcích.</small></span></label>
    <label class="check"><input type="checkbox" name="published"<?= $poll['published'] ? ' checked' : '' ?>> Po ukončení zveřejnit výsledky přihlášeným obyvatelům</label>
    <label>Patří ke shromáždění (nepovinné)
      <select name="event_id"><option value="">—</option><?php foreach ($events as $ev): ?><option value="<?= $ev['id'] ?>"<?= (int) $poll['event_id'] === (int) $ev['id'] ? ' selected' : '' ?>><?= e($ev['title']) ?> <?= cz_date($ev['event_date']) ?></option><?php endforeach ?></select></label>
    <p class="hint">Zda je hlasování přes web platné rozhodnutí mimo zasedání, určují stanovy a zákon (lhůta nejméně 15 dní, podklady, oznámení výsledku). Web hlasy sbírá a počítá; za splnění formalit odpovídá výbor.</p>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit hlasování</button><a class="btn btn-ghost" href="<?= url('/admin/hlasovani') ?>">Zrušit</a></div>
  </form>
  <?php endif ?>
</div>
