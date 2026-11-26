<?php /* Modul B: jak se hlas ověří (stránka hlasování vlastníka). */ $rollamM = rollam_method((int) $poll['id']); if ($rollamM !== 'none'): ?>
<p class="hint"><?= ['email' => 'Po odeslání Vám přijde e-mailem kód. Hlas se uloží, až ho zadáte (kód platí 10 minut).',
  'sms' => 'Po odeslání Vám přijde SMS s kódem. Hlas se uloží, až ho zadáte (kód platí 10 minut).',
  'bankid' => 'Po odeslání se přihlásíte přes svou banku (Bank iD). Hlas se uloží, až banka potvrdí Vaši totožnost.'][$rollamM] ?></p>
<?php endif ?>
