<?php /* Modul G: nabídka jednorázového hlasování ve formuláři nového hlasování, když web vyčerpal limit edice Zdarma. */
$voteT = plan_managed() ? plan_tenant() : null;
if ($voteT && plan_limit_error('polls') !== null && vote_offerable($voteT)): $voteP = vote_payment($voteT); ?>
  <div class="card card-soft vote-offer" id="jednorazove-hlasovani">
    <h2>Jednorázové hlasování</h2>
    <?php if ($voteP): ?>
      <p>Jednorázové hlasování máte objednané<?= $voteP['status'] === 'claimed' ? ' a platbu jste nahlásili jako odeslanou' : '' ?>. Hlasování navíc zapneme po připsání platby.</p>
      <div class="actions"><a class="btn" href="<?= url('/admin/hlasovani/jednorazove') ?>">Platební údaje</a></div>
    <?php else: ?>
      <p>Edice Zdarma má limit <?= plan_limit_of('free', 'polls') ?> hlasování za rok. Bez předplatného si můžete objednat <strong>jedno hlasování navíc za <?= money_czk(vote_price()) ?></strong> <small class="muted">(bez DPH)</small>. Vypsat ho můžete do <?= VOTE_PASS_DAYS ?> dní od připsání platby.</p>
      <div class="actions">
        <form method="post" action="<?= url('/admin/hlasovani/jednorazove') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="order"><button class="btn" type="submit">Objednat jednorázové hlasování</button></form>
        <a class="btn btn-ghost" href="<?= url('/admin/predplatne') ?>">Nebo předplatné Standard</a>
      </div>
    <?php endif ?>
  </div>
<?php endif ?>
