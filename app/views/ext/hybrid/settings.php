<?php /* Modul H: nastavení shromáždění online pro dům. $on, $code, $events */ ?>
<div class="wrap page narrow">
  <header class="page-head"><h1>Shromáždění online a hybridně</h1>
    <p class="lead">Vlastníci se mohou shromáždění zúčastnit a hlasovat na dálku přes videohovor – jen pokud to umožňují stanovy. <?= function_exists('help_link') ? help_link('shromazdeni-online') : '' ?></p></header>
  <?php $betaKey = HYBRID_BETA; require __DIR__ . '/../../admin/_beta.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="hybrid_on" value="1"<?= $on ? ' checked' : '' ?>>
      <span>Povolit shromáždění online a hybridní <small>U termínu pak zvolíte formu (prezenční / hybridní / online), vložíte odkaz na videohovor a potvrdíte, že to stanovy umožňují.</small></span></label>
    <label class="check"><input type="checkbox" name="hybrid_email_code" value="1"<?= $code ? ' checked' : '' ?>>
      <span>Při připojení ověřit vlastníka i kódem z e-mailu <small>Vedle přihlášení na web dostane vlastník šestimístný kód na e-mail. Hodí se, když stanovy vyžadují silnější ověření totožnosti.</small></span></label>
    <div class="actions"><button class="btn" type="submit">Uložit</button></div>
  </form>

  <h2>Jak to probíhá</h2>
  <ol>
    <li>U termínu shromáždění (<a href="<?= url('/admin/terminy') ?>">Termíny</a>) zvolte formu <em>hybridní</em> nebo <em>online</em>, vložte odkaz na videohovor (Jitsi Meet, Microsoft Teams, Zoom, Google Meet) nebo nechte vytvořit místnost Jitsi a potvrďte ustanovení stanov.</li>
    <li>V den konání se vlastník na stránce termínu připojí tlačítkem <em>Připojit se online</em>. Web ho zapíše do prezence (čas, IP adresa, ověření) a otevře videohovor.</li>
    <li>V <em>Prezenci na místě</em> vidíte přítomné na místě i online dohromady; podíly a usnášeníschopnost se obnovují samy.</li>
    <li>Návrh pustíte k hlasování online; vlastníci hlasují na webu a výsledek se Vám průběžně sčítá. Hlasování uzavřete jako obvykle.</li>
    <li>Zápis a prezenční listina uvedou formu shromáždění, kdo byl online a kolik hlasů přišlo online a na místě.</li>
  </ol>

  <h2>Vzorové ustanovení stanov</h2>
  <blockquote class="callout"><?= e(HYBRID_STATUTES_CLAUSE) ?></blockquote>
  <p class="hint">Bez ustanovení ve stanovách (§ 1206 odst. 2 občanského zákoníku) se účast a hlasování technickými prostředky nepočítají. Změnu stanov schvaluje shromáždění a zapisuje se do veřejného rejstříku – znění si nechte zkontrolovat, web ho jen nabízí jako vzor.</p>

  <?php if ($events): ?>
  <h2>Nejbližší termíny</h2>
  <ul class="thread-list">
    <?php foreach ($events as $ev): ?>
      <li><a href="<?= url('/admin/terminy/' . $ev['id']) ?>"><span class="thread-title"><?= e($ev['title']) ?></span>
        <span class="thread-meta"><?= cz_date($ev['event_date'], true) ?> · <?= e(HYBRID_FORMS[$ev['form']] ?? HYBRID_FORMS['prezencni']) ?></span></a></li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</div>
