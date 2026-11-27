<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url(listing_path($l)) ?>">← Zpět na inzerát</a></p>
  <header class="page-head"><h1>Nahlásit inzerát</h1>
    <p class="lead">Je inzerát podvodný, urážlivý nebo jinak nevhodný? Napište výboru společenství, co je špatně. Výbor ho může skrýt.</p></header>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <p class="hp" aria-hidden="true"><label>Web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
    <p><strong><?= e(listing_title($l)) ?></strong></p>
    <label>Co je na inzerátu špatně <textarea name="reason" rows="5" maxlength="2000" required><?= e($reason) ?></textarea></label>
    <p class="hint">Hlášení dostane výbor e-mailem. Vlastník inzerátu se nedozví, kdo ho poslal.</p>
    <button class="btn" type="submit">Odeslat hlášení</button>
  </form>
</div>
