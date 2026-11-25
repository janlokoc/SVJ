<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/terminy') ?>">← Termíny</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Název <input type="text" name="title" value="<?= e($ev['title']) ?>" required></label>
    <div class="form-row">
      <label>Datum <input type="date" name="event_date" value="<?= e($ev['event_date']) ?>" required></label>
      <label>Čas (nepovinné) <input type="time" name="event_time" value="<?= e($ev['event_time']) ?>"></label>
    </div>
    <label>Místo <input type="text" name="place" value="<?= e($ev['place']) ?>" placeholder="Např. sušárna vchodu B"></label>
    <label>Poznámka <input type="text" name="note" value="<?= e($ev['note']) ?>"></label>
    <label>Program <textarea name="program" rows="6" placeholder="- Zahájení a volba předsedajícího&#10;- Schválení účetní závěrky&#10;- …"><?= e($ev['program']) ?></textarea></label>
    <?php $aiTarget = 'textarea[name=program]'; $aiTasks = ['program', 'polish']; $aiContext = ''; $aiFields = ['title' => 'Název', 'event_date' => 'Datum', 'event_time' => 'Čas', 'place' => 'Místo', 'note' => 'Poznámka']; require __DIR__ . '/_ai.php'; unset($aiFields); ?>
    <p class="hint">Každý bod na samostatný řádek začínající pomlčkou a mezerou; řádek bez pomlčky je jen poznámka k předchozímu bodu (např. „Účast nutná“). Zobrazí se přihlášeným obyvatelům na stránce termínu. Na shromáždění web v Prezenci na místě z bodů, o kterých se rozhoduje, nabídne návrhy k hlasování a jejich výsledky doplní do zápisu; informativní body (zahájení, diskuze, ukončení) nechá bez hlasování.</p>
    <fieldset>
      <legend>Podklady</legend>
      <label>Vyberte z dokumentů <input type="search" data-filter="#event-docs" placeholder="Hledat dokument…"></label>
      <div class="pick" id="event-docs">
        <?php foreach ($documents as $d): ?>
          <label class="check"><input type="checkbox" name="documents[]" value="<?= $d['id'] ?>"<?= in_array((int) $d['id'], $evDocs, true) ? ' checked' : '' ?>>
            <span><?= e($d['title']) ?> <small><?= CATEGORIES[$d['category']] ?? '' ?><?= $d['doc_date'] ? ' · ' . cz_date($d['doc_date']) : '' ?></small></span></label>
        <?php endforeach ?>
      </div>
    </fieldset>
    <label class="check"><input type="checkbox" name="rsvp"<?= $ev['rsvp'] ? ' checked' : '' ?>> <span>Sbírat účast vlastníků <small>Vlastníci na webu potvrdí, zda přijdou, a výbor uvidí odhad usnášeníschopnosti.</small></span></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit termín</button><a class="btn btn-ghost" href="<?= url('/admin/terminy') ?>">Zrušit</a></div>
  </form>
</div>
