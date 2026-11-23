<?php /* Veřejný formulář: přiřazení k jednotce přes odkaz od výboru. $units, $in, $errors, $done, $token */ ?>
<div class="wrap page auth">
  <div class="card auth-card join-card">
    <h1>Přiřazení k jednotce</h1>
    <?php if ($done): ?>
      <div class="flash" role="status"><strong>Žádost je odeslaná.</strong> Výbor ověří, že k jednotce patříte, a pak Vám přijde e-mail s odkazem na nastavení hesla.</div>
      <p class="hint">Obvykle to trvá několik dní. Potvrzení žádosti jsme poslali na Váš e-mail – pokud nepřišlo, podívejte se do spamu.</p>
      <p class="hint"><a href="<?= url('/') ?>">← Na úvodní stránku webu</a></p>
    <?php elseif (!$units): ?>
      <p>Výbor zatím na webu nemá seznam jednotek. Zkuste to prosím později nebo se obraťte přímo na výbor.</p>
    <?php else: ?>
      <p>Vyplňte, ke které jednotce v domě patříte. <strong>Výbor žádost ověří a teprve potom Vám účet zpřístupní</strong> – web tak chrání informace domu před cizími lidmi.</p>
      <?php if ($errors): ?><div class="flash flash-error" role="alert"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif ?>
      <form method="post" action="<?= url('/pripojit/' . $token) ?>" class="form">
        <?= csrf_field() ?>
        <span class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></span>
        <label>Jméno a příjmení <input type="text" name="name" value="<?= e($in['name']) ?>" autocomplete="name" required maxlength="120"></label>
        <label>E-mail <small>budete se jím přihlašovat</small> <input type="email" name="email" value="<?= e($in['email']) ?>" autocomplete="email" required maxlength="190"></label>
        <label>Telefon <small>nepovinné – výbor Vám může zavolat kvůli ověření</small> <input type="tel" name="phone" value="<?= e($in['phone']) ?>" autocomplete="tel" maxlength="40"></label>
        <label>Jednotka <select name="unit_id" required><option value="">– vyberte –</option>
          <?php foreach ($units as $un): ?><option value="<?= (int) $un['id'] ?>"<?= (int) $in['unit_id'] === (int) $un['id'] ? ' selected' : '' ?>><?= e(unit_name($un)) ?><?= $un['floor'] !== '' ? ' · ' . e($un['floor']) . '. patro' : '' ?></option><?php endforeach ?>
        </select></label>
        <fieldset class="checks join-kind"><legend>Jsem</legend>
          <?php foreach (KINDS as $k => $label): ?><label class="check"><input type="radio" name="kind" value="<?= $k ?>"<?= $in['kind'] === $k ? ' checked' : '' ?>> <?= e($label) ?></label><?php endforeach ?>
        </fieldset>
        <label>Poznámka pro výbor <small>nepovinné – např. „jsem spoluvlastník s manželem“ nebo „nájemník od 1. 3.“</small> <textarea name="note" rows="3" maxlength="500"><?= e($in['note']) ?></textarea></label>
        <label class="check"><input type="checkbox" name="agree" required> <span>Souhlasím, aby výbor tyto údaje použil k ověření a k založení účtu na webu. <a href="<?= url('/ochrana-udaju') ?>">Ochrana osobních údajů</a></span></label>
        <button class="btn btn-big" type="submit">Odeslat žádost výboru</button>
      </form>
      <p class="hint">Už máte účet? <a href="<?= url('/prihlaseni') ?>">Přihlaste se</a>. Pokud chcete přidat další jednotku ke stávajícímu účtu, vyplňte stejný e-mail – výbor ji po ověření přidá.</p>
    <?php endif ?>
  </div>
</div>
