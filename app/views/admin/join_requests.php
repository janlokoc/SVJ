<?php /* Správce: odkaz pro přiřazení k jednotce a schvalování žádostí. $rows, $link, $qr */
$pending = array_values(array_filter($rows, fn($r) => $r['status'] === 'pending'));
$done = array_values(array_filter($rows, fn($r) => $r['status'] !== 'pending')); ?>
<div class="wrap page">
  <p><a href="<?= url('/admin/uzivatele') ?>">← Uživatelé</a></p>
  <header class="page-head"><h1>Žádosti o přiřazení k jednotce</h1>
    <p class="lead">Obyvatelé se k jednotce přihlásí sami přes odkaz. Účet vznikne, až žádost schválíte – předtím ověřte, že e-mail opravdu patří vlastníkovi nebo nájemníkovi té jednotky.</p></header>

  <section class="card join-link" id="odkaz">
    <h2>Odkaz pro obyvatele</h2>
    <?php if ($link): ?>
      <div class="join-link-body">
        <div>
          <p>Pošlete ho e-mailem, vložte do aktuality nebo vytiskněte QR kód na nástěnku ve vchodu.</p>
          <p><input type="text" class="join-url" value="<?= e($link) ?>" readonly data-select-all aria-label="Odkaz pro přiřazení k jednotce"></p>
          <div class="actions">
            <button class="btn btn-small" type="button" data-copy="<?= e($link) ?>">Zkopírovat odkaz</button>
            <a class="btn btn-small btn-ghost" href="<?= url('/admin/zpravy/nova') ?>">Napsat e-mail obyvatelům</a>
            <form method="post" action="<?= url('/admin/uzivatele/zadosti/odkaz') ?>" class="inline-form" data-confirm="Vytvořit nový odkaz? Ten dosavadní přestane fungovat."><?= csrf_field() ?><button class="btn btn-small btn-ghost" name="do" value="new" type="submit">Vytvořit nový odkaz</button></form>
            <form method="post" action="<?= url('/admin/uzivatele/zadosti/odkaz') ?>" class="inline-form"><?= csrf_field() ?><button class="btn btn-small btn-ghost" name="do" value="off" type="submit">Vypnout odkaz</button></form>
          </div>
        </div>
        <div class="pay-qr join-qr"><?= $qr ?><small>QR kód na nástěnku</small></div>
      </div>
      <p class="hint">Kdo odkaz má, může poslat žádost – nic ale neuvidí, dokud ji neschválíte. Když se odkaz dostane, kam nemá, vytvořte nový.</p>
    <?php else: ?>
      <p>Odkaz je vypnutý. Po zapnutí dostanete adresu a QR kód, které můžete rozeslat obyvatelům.</p>
      <form method="post" action="<?= url('/admin/uzivatele/zadosti/odkaz') ?>"><?= csrf_field() ?><button class="btn" name="do" value="on" type="submit">Zapnout odkaz pro přiřazení</button></form>
    <?php endif ?>
  </section>

  <h2>Čeká na schválení <?= $pending ? '<span class="tag">' . count($pending) . '</span>' : '' ?></h2>
  <?php if (!$pending): ?><p class="empty">Žádná žádost nečeká.</p><?php endif ?>
  <div class="join-list">
  <?php foreach ($pending as $r): ?>
    <article class="card join-req" id="z<?= (int) $r['id'] ?>">
      <header><h3><?= e($r['name']) ?></h3><span class="tag"><?= e(KINDS[$r['kind']] ?? $r['kind']) ?></span></header>
      <dl class="pay-data">
        <dt>E-mail</dt><dd><?= e($r['email']) ?><?= $r['account'] ? ' <small class="muted">· na webu už má účet' . ((int) $r['account']['active'] ? '' : ' (vypnutý)') . '</small>' : '' ?></dd>
        <?php if ($r['phone'] !== ''): ?><dt>Telefon</dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $r['phone'])) ?>"><?= e($r['phone']) ?></a></dd><?php endif ?>
        <dt>Jednotka</dt><dd><strong><?= $r['number'] !== null ? e(unit_prefix() . $r['entrance'] . '/' . $r['number']) : '<span class="muted">jednotka zanikla</span>' ?></strong></dd>
        <?php if ($r['note'] !== ''): ?><dt>Poznámka</dt><dd><?= e($r['note']) ?></dd><?php endif ?>
        <dt>Odesláno</dt><dd><?= cz_datetime($r['created_at']) ?></dd>
      </dl>
      <div class="join-check">
        <p class="h-small">K ověření</p>
        <ul>
          <li>Vlastník podle evidence: <?= ($r['owner_note'] ?? '') !== '' ? '<strong>' . e($r['owner_note']) . '</strong>' : '<span class="muted">neuvedeno (doplníte importem z katastru)</span>' ?></li>
          <?php $same = count(array_filter($pending, fn($x) => $x['unit_id'] === $r['unit_id'])); if ($same > 1): ?><li><strong>Na stejnou jednotku čeká žádostí: <?= $same ?>.</strong> Ověřte obě – jedna může být omyl nebo pokus o zneužití.</li><?php endif ?>
          <li>Už přiřazení k jednotce: <?= $r['current'] ? e(implode(', ', array_map(fn($c) => $c['name'] . ' (' . mb_strtolower(KINDS[$c['kind']] ?? '') . ')', $r['current']))) : '<span class="muted">nikdo</span>' ?></li>
        </ul>
        <p class="hint">Jméno nestačí – e-mail mohl vyplnit kdokoli. Ověřte ho u člověka osobně, telefonem nebo přes známý kontakt (např. e-mail z evidence vlastníků).</p>
      </div>
      <form method="post" action="<?= url('/admin/uzivatele/zadosti/' . (int) $r['id']) ?>" class="form join-approve"><?= csrf_field() ?>
        <label class="check"><input type="checkbox" name="verified" required> <span>Ověřil(a) jsem, že e-mail patří této osobě a ta k jednotce patří.</span></label>
        <div class="actions"><button class="btn" name="do" value="approve" type="submit"><?= $r['account'] ? 'Schválit a přidat jednotku k účtu' : 'Schválit a založit účet' ?></button></div>
      </form>
      <details class="join-reject"><summary>Zamítnout</summary>
        <form method="post" action="<?= url('/admin/uzivatele/zadosti/' . (int) $r['id']) ?>" class="form" data-confirm="Zamítnout žádost? Žadatel dostane e-mail."><?= csrf_field() ?>
          <label>Důvod pro žadatele <small>nepovinné, pošle se mu e-mailem</small> <input type="text" name="reason" maxlength="300" placeholder="např. jednotku vlastní jiná osoba"></label>
          <button class="btn btn-ghost" name="do" value="reject" type="submit">Zamítnout žádost</button></form>
      </details>
    </article>
  <?php endforeach ?>
  </div>

  <?php if ($done): ?>
  <h2>Vyřízené</h2>
  <table class="table">
    <thead><tr><th>Kdo</th><th>Jednotka</th><th>Výsledek</th><th>Vyřídil</th></tr></thead>
    <tbody><?php foreach ($done as $r): ?><tr>
      <td data-label="Kdo"><?= e($r['name']) ?><br><small class="muted"><?= e($r['email']) ?></small></td>
      <td data-label="Jednotka"><?= $r['number'] !== null ? e(unit_prefix() . $r['entrance'] . '/' . $r['number']) : '—' ?></td>
      <td data-label="Výsledek"><span class="status status-<?= $r['status'] === 'approved' ? 'done' : 'new' ?>"><?= e(JOIN_STATUS[$r['status']]) ?></span><?= $r['decision_note'] !== '' ? '<br><small class="muted">' . e($r['decision_note']) . '</small>' : '' ?></td>
      <td data-label="Vyřídil"><?= e($r['decided_name'] ?? '') ?><br><small class="muted"><?= cz_datetime($r['decided_at']) ?></small></td>
    </tr><?php endforeach ?></tbody>
  </table>
  <p class="hint">Vyřízené žádosti se po 30 dnech samy mažou.</p>
  <?php endif ?>
</div>
