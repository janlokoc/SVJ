<?php
/* Modul B: volba ověření hlasu ve formuláři hlasování. $poll z admin/poll_form.php. */
$rollamCur = isset($_POST['verify']) && isset(ROLLAM_VERIFY[$_POST['verify']]) ? (string) $_POST['verify'] : ($poll['id'] ? rollam_method((int) $poll['id']) : 'email');
$rollamAvail = rollam_methods_available();
if (!isset($rollamAvail[$rollamCur])) {
    $rollamAvail[$rollamCur] = ROLLAM_VERIFY[$rollamCur] . ' – teď nedostupné';
}
?>
<label>Ověření hlasu při hlasování přes web
  <select name="verify"><?php foreach ($rollamAvail as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $rollamCur ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<p class="hint">S ověřením se hlas uloží, až ho vlastník potvrdí jednorázovým kódem (platí 10 minut) nebo přes Bank iD. Každý hlas se zapisuje do protokolu s časem, adresou a způsobem ověření.
  <?= function_exists('help_link') ? help_link('hlasovani-per-rollam', 'Hlasování mimo zasedání – co požaduje zákon') : '' ?></p>
<?php if (isset($rollamAvail['bankid']) && function_exists('bankid_poll_warning') && ($rollamBw = bankid_poll_warning())): ?><p class="hint<?= $rollamCur === 'bankid' ? ' callout' : '' ?>"><strong><?= $rollamCur === 'bankid' ? 'Pozor:' : 'Bank iD:' ?></strong> <?= e($rollamBw) ?> <?= credit_topup_link() ?></p><?php endif ?>
<?php foreach (rollam_warnings($poll) as $rollamW): ?><p class="hint"><strong>Pozor:</strong> <?= e($rollamW) ?></p><?php endforeach ?>
