<?php
$c = $funnel['cizi'];
$k = $funnel['znami'];
$goal = $funnel['goal'];
$paid = $c['zaplaceno'];
$steps = ['kontakt' => ['Ozvali se', 'ručně zapsané kontakty'], 'ukazka' => ['Ukázka', 'proběhla ukázka webu'], 'registrace' => ['Založili web', 'registrace bez ukázek'],
    'oznameno' => ['Oznámili web', 'poslali sousedům první e-mail'], 'zaplaceno' => ['Zaplatili', 'připsaná platba za edici']];
$lf = $leadForm + ['house' => '', 'contact' => '', 'source' => 'skupina', 'stage' => 'kontakt', 'tenant' => '', 'note' => ''];
$tf = $tForm + ['text' => '', 'who' => '', 'house' => '', 'consent' => '', 'visible' => '', 'sort' => 0];
$houseOptions = function (string $sel) use ($houses): string {
    $o = '<option value="">—</option>';
    foreach ($houses as $h) {
        $o .= '<option value="' . e($h['slug']) . '"' . ($h['slug'] === $sel ? ' selected' : '') . '>' . e($h['name']) . '</option>';
    }
    return $o;
};
$opts = fn(array $list, string $sel) => implode('', array_map(fn($v, $l) => '<option value="' . e($v) . '"' . ($v === $sel ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($list), $list));
?>
<div class="wrap page">
  <header class="page-head"><h1>Obchod</h1>
    <p class="lead">Cesta od prvního kontaktu k platbě. Do cíle se počítají jen <strong>cizí</strong> domy – ty, které Vás předtím neznaly. Ověřují, že se služba prodá i bez osobního vztahu.</p></header>
  <p class="chips"><a href="#cil">Cíl</a> <a href="#kontakty">Kontakty</a> <a href="#domy">Domy</a> <a href="#kdo">Kdo za tím stojí</a> <a href="#doporuceni">Doporučení</a></p>

  <section id="cil">
    <div class="sales-goal">
      <h2>Cíl: <?= $goal ?> cizích platících domů do <?= cz_date($funnel['deadline']) ?></h2>
      <p><strong class="stat-num"><?= $paid ?> z <?= $goal ?></strong> · <?= $funnel['days'] >= 0 ? 'zbývá ' . $funnel['days'] . ' dní' : 'termín uplynul před ' . -$funnel['days'] . ' dny' ?><?= $paid >= $goal ? ' · <strong>splněno</strong>' : '' ?></p>
      <progress value="<?= min($paid, $goal) ?>" max="<?= $goal ?>" aria-label="Splněno <?= $paid ?> z <?= $goal ?>"></progress>
    </div>
    <h3>Cizí domy</h3>
    <div class="sales-funnel"><?php foreach ($steps as $key => [$label, $hint]): ?><div class="sales-step"><span class="stat-num"><?= (int) $c[$key] ?></span><?= e($label) ?><br><small><?= e($hint) ?></small></div><?php endforeach ?></div>
    <p class="hint">Známé domy (pro srovnání): založeno <?= $k['registrace'] ?>, oznámeno <?= $k['oznameno'] ?>, zaplaceno <?= $k['zaplaceno'] ?>. Ukázkové weby se nepočítají. Zda je dům známý, nastavíte níže v části Domy.</p>
    <details class="card"><summary>Změnit cíl a zakládající domy</summary>
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
        <div class="form-row"><label>Cíl – cizích platících domů <input type="number" name="sales_goal" min="1" value="<?= $goal ?>" required></label>
          <label>Do kdy <input type="date" name="sales_deadline" value="<?= e($funnel['deadline']) ?>" required></label></div>
        <label class="check"><input type="checkbox" name="founding_on" value="1"<?= pset('founding_on') === '1' ? ' checked' : '' ?>> Nabízet „zakládající domy“ na úvodní stránce a v ceníku</label>
        <label>Počet zakládajících míst <input type="number" name="founding_slots" min="0" value="<?= founding_slots() ?>"></label>
        <p class="hint">Zakládající dům má převod starého webu zdarma a cenu Standardu ze dne, kdy ho tak označíte, natrvalo (přirážka za jednotky nad paušál se počítá dál). Obsazeno <?= founding_used() ?> z <?= founding_slots() ?>. Když jsou místa obsazená, nabídka z webu sama zmizí.</p>
        <button class="btn" type="submit">Uložit</button></form>
    </details>
  </section>

  <section id="kontakty">
    <h2>Kontakty</h2>
    <p class="muted">Kdo se ozval ze skupin, z fóra nebo na doporučení. Zapisujte jen to, co potřebujete k domluvě – jméno a e-mail nebo telefon kontaktu, nic víc.</p>
    <?php if ($leads): ?>
    <table class="table"><thead><tr><th>Dům</th><th>Odkud</th><th>Stav</th><th>Web</th><th>Naposledy</th><th></th></tr></thead><tbody>
      <?php foreach ($leads as $l): ?><tr>
        <td data-label="Dům"><strong><?= e($l['house']) ?></strong><?= $l['contact'] !== '' ? '<br><small class="muted">' . e($l['contact']) . '</small>' : '' ?><?= $l['note'] !== '' ? '<br><small>' . e($l['note']) . '</small>' : '' ?></td>
        <td data-label="Odkud"><?= e(SALES_SOURCES[$l['source']] ?? $l['source']) ?></td>
        <td data-label="Stav"><span class="tag"><?= e(SALES_STAGES[$l['stage']] ?? $l['stage']) ?></span></td>
        <td data-label="Web"><?= $l['tenant'] !== '' ? e((tenant_row($l['tenant'])['name'] ?? $l['tenant'])) : '<span class="muted">—</span>' ?></td>
        <td data-label="Naposledy"><?= cz_date(substr((string) $l['updated_at'], 0, 10)) ?></td>
        <td class="t-actions"><details><summary class="btn btn-small btn-ghost">Upravit</summary>
          <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="lead_save"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
            <label>Dům <input type="text" name="house" value="<?= e($l['house']) ?>" required></label>
            <label>Kontakt <input type="text" name="contact" value="<?= e($l['contact']) ?>"></label>
            <div class="form-row"><label>Odkud <select name="source"><?= $opts(SALES_SOURCES, $l['source']) ?></select></label>
              <label>Stav <select name="stage"><?= $opts(SALES_STAGES, $l['stage']) ?></select></label></div>
            <label class="check"><input type="checkbox" name="demo_done" value="1"<?= $l['demo_done'] ? ' checked' : '' ?>> Ukázka proběhla</label>
            <label>Založený web <select name="tenant"><?= $houseOptions($l['tenant']) ?></select></label>
            <label>Poznámka <textarea name="note" rows="2"><?= e($l['note']) ?></textarea></label>
            <button class="btn btn-small" type="submit">Uložit</button></form>
          <form method="post" onsubmit="return confirm('Smazat kontakt?')"><?= csrf_field() ?><input type="hidden" name="action" value="lead_delete"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><button class="btn btn-small btn-ghost" type="submit">Smazat</button></form>
        </details></td></tr><?php endforeach ?>
    </tbody></table>
    <?php else: ?><p class="empty">Zatím žádný kontakt.</p><?php endif ?>
    <form method="post" class="card form"><h3>Zapsat kontakt</h3><?= csrf_field() ?><input type="hidden" name="action" value="lead_add">
      <div class="form-row"><label>Dům <input type="text" name="house" value="<?= e($lf['house']) ?>" placeholder="např. Lipová 12, Brno" required></label>
        <label>Kontakt <input type="text" name="contact" value="<?= e($lf['contact']) ?>" placeholder="např. Jana Nováková, jana@example.cz"></label></div>
      <div class="form-row"><label>Odkud <select name="source"><?= $opts(SALES_SOURCES, $lf['source']) ?></select></label>
        <label>Stav <select name="stage"><?= $opts(SALES_STAGES, $lf['stage']) ?></select></label></div>
      <label>Poznámka <textarea name="note" rows="2"><?= e($lf['note']) ?></textarea></label>
      <button class="btn" type="submit">Zapsat</button></form>
  </section>

  <section id="domy">
    <h2>Domy</h2>
    <p class="muted">Všechny založené weby kromě ukázek. „Známý“ označte u domů, které Vás znají osobně – do cíle se nepočítají.</p>
    <?php if ($houses): ?>
    <table class="table"><thead><tr><th>Dům</th><th>Založeno</th><th>Edice</th><th>Oznámeno</th><th>Zaplaceno</th><th>Známý</th><th>Zakládající</th></tr></thead><tbody>
      <?php foreach ($houses as $h): ?><tr<?= $h['active'] ? '' : ' class="is-off"' ?>>
        <td data-label="Dům"><a href="<?= url('/svj/' . $h['slug']) ?>"><strong><?= e($h['name']) ?></strong></a></td>
        <td data-label="Založeno"><?= cz_date(substr($h['created_at'], 0, 10)) ?></td>
        <td data-label="Edice"><?= e(EDITIONS[$h['edition']] ?? $h['edition']) ?></td>
        <td data-label="Oznámeno"><?= $h['launched'] ? 'ano' : '<span class="muted">ne</span>' ?></td>
        <td data-label="Zaplaceno"><?= $h['paid'] ? '<strong>ano</strong>' : '<span class="muted">ne</span>' ?></td>
        <td data-label="Známý"><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="known"><input type="hidden" name="slug" value="<?= e($h['slug']) ?>">
          <input type="hidden" name="known" value="<?= $h['known'] ? '' : '1' ?>"><button class="btn btn-small<?= $h['known'] ? '' : ' btn-ghost' ?>" type="submit" aria-pressed="<?= $h['known'] ? 'true' : 'false' ?>"><?= $h['known'] ? 'Známý' : 'Cizí' ?></button></form></td>
        <td data-label="Zakládající"><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="founding"><input type="hidden" name="slug" value="<?= e($h['slug']) ?>">
          <input type="hidden" name="founding" value="<?= $h['founding'] !== '' ? '' : '1' ?>"><button class="btn btn-small<?= $h['founding'] !== '' ? '' : ' btn-ghost' ?>" type="submit"><?= $h['founding'] !== '' ? 'Ano, od ' . cz_date($h['founding']) : 'Označit' ?></button></form></td>
      </tr><?php endforeach ?>
    </tbody></table>
    <?php else: ?><p class="empty">Zatím žádný web.</p><?php endif ?>
  </section>

  <section id="kdo">
    <h2>Kdo za tím stojí</h2>
    <p class="muted">Sekce na úvodní stránce služby. Zobrazí se, jakmile vyplníte příběh nebo zveřejníte aspoň jedno doporučení.<?= about_has_content() ? ' <a href="' . e(platform_url('/')) . '#kdo-za-tim-stoji">Zobrazit na webu</a>' : '' ?></p>
    <form method="post" class="card form" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="about">
      <div class="form-row"><label>Jméno (stačí křestní) <input type="text" name="about_name" value="<?= e(pset('about_name')) ?>" maxlength="80"></label>
        <label>Kdo jste <input type="text" name="about_role" value="<?= e(pset('about_role')) ?>" maxlength="120" placeholder="např. zakladatel, člen výboru SVJ"></label></div>
      <label>Příběh <textarea name="about_story" rows="5" maxlength="1500" placeholder="Proč jste službu založil a pro koho je. Odstavce oddělte prázdným řádkem."><?= e(pset('about_story')) ?></textarea></label>
      <label>Fotka (JPEG, PNG nebo WebP do 3 MB) <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
      <?php if (about_photo_file() !== ''): ?><p><img class="about-photo" src="<?= e(platform_url('/kdo-za-tim-stoji/foto')) ?>?v=<?= e(pset('about_photo_v')) ?>" alt="" width="96" height="96" style="width:96px;height:96px"></p>
        <label class="check"><input type="checkbox" name="photo_delete" value="1"> Smazat fotku</label><?php endif ?>
      <button class="btn" type="submit">Uložit</button></form>
  </section>

  <section id="doporuceni">
    <h2>Doporučení</h2>
    <p class="muted">Na webu se zobrazí jen doporučení se zaškrtnutým „Zveřejnit“ a s datem písemného souhlasu autora. Souhlas (e-mail) si uschovejte.</p>
    <?php foreach ($testimonials as $r): ?>
    <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="action" value="testimonial_save"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <label>Text <textarea name="text" rows="3" maxlength="600" required><?= e($r['text']) ?></textarea></label>
      <div class="form-row"><label>Kdo <input type="text" name="who" value="<?= e($r['who']) ?>" required></label><label>Dům <input type="text" name="house" value="<?= e($r['house']) ?>"></label></div>
      <div class="form-row"><label>Souhlas se zveřejněním ze dne <input type="date" name="consent" value="<?= e($r['consent']) ?>"></label><label>Pořadí <input type="number" name="sort" value="<?= (int) $r['sort'] ?>"></label></div>
      <label class="check"><input type="checkbox" name="visible" value="1"<?= $r['visible'] ? ' checked' : '' ?>> Zveřejnit</label>
      <div class="actions"><button class="btn btn-small" type="submit">Uložit</button>
        <button class="btn btn-small btn-ghost" type="submit" name="action" value="testimonial_delete" formnovalidate onclick="return confirm('Smazat doporučení?')">Smazat</button></div>
    </form>
    <?php endforeach ?>
    <form method="post" class="card form"><h3>Přidat doporučení</h3><?= csrf_field() ?><input type="hidden" name="action" value="testimonial_add">
      <label>Text <textarea name="text" rows="3" maxlength="600" required><?= e($tf['text']) ?></textarea></label>
      <div class="form-row"><label>Kdo <input type="text" name="who" value="<?= e($tf['who']) ?>" placeholder="např. Petr, předseda výboru" required></label><label>Dům <input type="text" name="house" value="<?= e($tf['house']) ?>" placeholder="např. SVJ Lipová, 24 bytů, Brno"></label></div>
      <div class="form-row"><label>Souhlas se zveřejněním ze dne <input type="date" name="consent" value="<?= e($tf['consent']) ?>"></label><label>Pořadí <input type="number" name="sort" value="<?= (int) $tf['sort'] ?>"></label></div>
      <label class="check"><input type="checkbox" name="visible" value="1"<?= $tf['visible'] ? ' checked' : '' ?>> Zveřejnit</label>
      <button class="btn" type="submit">Přidat</button></form>
  </section>
</div>
