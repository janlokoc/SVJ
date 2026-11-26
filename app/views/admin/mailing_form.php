<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/zpravy') ?>">← Zprávy e-mailem</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if ($notice): ?><div class="flash" role="status"><?= e($notice) ?></div><?php endif ?>
  <?php if (!filter_var(setting('mail_from'), FILTER_VALIDATE_EMAIL)): ?>
    <div class="flash flash-info">Zatím není nastavená adresa odesílatele. Vyplňte ji v <a href="<?= url('/admin/nastaveni') ?>">Nastavení</a>, jinak zprávy nepůjdou odeslat.</div>
  <?php endif ?>
  <form method="post" action="<?= url('/admin/zpravy/nova') ?>" class="card form">
    <?= csrf_field() ?><input type="hidden" name="nonce" value="<?= e($nonce) ?>">
    <label>Komu
      <select name="audience">
        <?php foreach (AUDIENCES as $k => $label): ?><option value="<?= $k ?>"<?= $m['audience'] === $k ? ' selected' : '' ?>><?= $label ?> (příjemců: <?= $counts[$k] ?>)</option><?php endforeach ?>
      </select>
    </label>
    <p class="hint">Zpráva jde jen lidem s aktivním účtem na webu.<?= $optedOut ? ' Vypnuté zasílání má ' . $optedOut . ' z nich – ti zprávu nedostanou.' : '' ?> Každý dostane vlastní e-mail, adresy ostatních nevidí.</p>
    <?php if (function_exists('partner_house_templates')) { $tplList = partner_house_templates('mailing'); $tplTitle = '[name=subject]'; $tplBody = 'textarea[name=body]'; require __DIR__ . '/../partner/_tpl_pick.php'; } ?>
    <label>Předmět <input type="text" name="subject" value="<?= e($m['subject']) ?>" maxlength="150" required></label>
    <label>Text zprávy <textarea name="body" rows="12" required><?= e($m['body']) ?></textarea></label>
    <?php $aiTarget = 'textarea[name=body]'; $aiTasks = !empty($aiInvite) ? ['invite', 'polish', 'shorten'] : ['draft', 'polish', 'shorten']; $aiContext = $aiInvite ?? ''; require __DIR__ . '/_ai.php'; ?>
    <p class="hint">E-mail se posílá bez příloh – na dokumenty odkažte adresou na webu. Pod text se automaticky doplní, kdo zprávu posílá a jak si zasílání vypnout.<?= setting_on('mail_tracking') ? ' Odkazy na tento web se u každého příjemce evidují, takže uvidíte, kdo se proklikl.' : '' ?></p>
    <div class="actions">
      <button class="btn btn-big" type="submit" name="action" value="send" data-confirm-audience>Odeslat zprávu</button>
      <button class="btn btn-ghost" type="submit" name="action" value="test">Poslat zkoušku</button>
    </div>
    <label class="test-to">Zkoušku poslat na <input type="email" name="test_to" value="<?= e(post('test_to') ?: (user()['email'] ?? '')) ?>" autocomplete="email"></label>
    <p class="hint">Odeslanou zprávu už nelze vzít zpět. Nejdřív si pošlete zkoušku.</p>
  </form>
</div>
