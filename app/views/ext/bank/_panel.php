<?php /* Odkaz na propojení s dalšími bankami v části „Párování plateb s bankou“ (/admin/platby). */
if (function_exists('psd2_available') && psd2_available()):
    psd2_ready();
    $psd2Linked = (int) (one("SELECT COUNT(*) AS n FROM psd2_accounts a JOIN psd2_links l ON l.id = a.link_id WHERE l.status = 'LN'")['n'] ?? 0); ?>
<p class="psd2-panel"><strong>Účet u jiné banky?</strong> <?= $psd2Linked ? 'Propojené účty: ' . $psd2Linked . '. ' : '' ?><a href="<?= url('/admin/platby/banky') ?>"><?= $psd2Linked ? 'Spravovat propojení' : 'Propojit účet u ČS, KB, ČSOB a dalších bank' ?></a><?= beta_is('bank-psd2') ? ' <span class="tag tag-beta">Beta</span>' : '' ?></p>
<?php endif ?>
