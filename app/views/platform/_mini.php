<?php /* Zmenšený náhled webu v daném vzhledu: $look, $miniName */ ?>
<div class="mini" data-look="<?= e($look) ?>" data-style="<?= e($style ?? 'klasik') ?>" aria-hidden="true">
  <div class="mini-bar"><svg viewBox="0 0 44 32"><rect x="1" y="9" width="9" height="22" rx="1.5"/><rect x="12" y="3" width="9" height="28" rx="1.5"/><rect x="23" y="9" width="9" height="22" rx="1.5"/><rect x="34" y="3" width="9" height="28" rx="1.5"/></svg><b><?= e($miniName ?? 'Zahradní 12') ?></b><i></i><i></i><i></i></div>
  <div class="mini-body">
    <small>Společenství vlastníků jednotek</small>
    <strong><?= e($miniName ?? 'Zahradní 12') ?></strong>
    <span class="mini-btn">Aktuální oznámení</span>
    <div class="mini-cards"><span></span><span></span><span class="mini-accent"></span></div>
  </div>
</div>
