<?php /* Modul H: rámeček na stránce termínu. $ev, $h (hybrid_remote), $error (proč se nejde připojit), $online */ ?>
<section class="card hybrid-box" id="online">
  <h2><?= $h['form'] === 'online' ? 'Shromáždění se koná online' : 'Shromáždění se koná na místě i online' ?></h2>
  <p>Vlastníci se mohou zúčastnit a hlasovat prostřednictvím videohovoru (<?= e(hybrid_provider($h['video_url'])) ?>). Připojení na webu Vás zapíše do prezence; o návrzích pak hlasujete tady na webu.</p>
  <?php if ($online): ?>
    <p><strong>Jste připojen(a) online.</strong></p>
    <p class="actions"><a class="btn" href="<?= url('/terminy/' . $ev['id'] . '/online') ?>">Pokračovat na shromáždění</a></p>
  <?php elseif ($error): ?>
    <p class="muted"><?= e($error) ?></p>
  <?php else: ?>
    <p class="actions"><a class="btn btn-big" href="<?= url('/terminy/' . $ev['id'] . '/online') ?>">Připojit se online</a></p>
  <?php endif ?>
</section>
