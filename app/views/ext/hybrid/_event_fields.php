<?php /* Modul H: forma shromáždění ve formuláři termínu. $h = hybrid_event(), $jitsi = návrh nové místnosti. */ ?>
<fieldset class="hybrid-fields" data-hybrid-fields>
  <legend>Forma shromáždění</legend>
  <?php foreach (HYBRID_FORMS as $k => $label): ?>
    <label class="check"><input type="radio" name="hybrid_form" value="<?= $k ?>"<?= $h['form'] === $k ? ' checked' : '' ?>> <span><?= e($label) ?></span></label>
  <?php endforeach ?>
  <div class="hybrid-remote" data-hybrid-remote>
    <label>Odkaz na videohovor
      <input type="url" name="hybrid_video_url" value="<?= e($h['video_url']) ?>" maxlength="500" placeholder="https://meet.jit.si/… nebo odkaz z Teams, Zoomu, Google Meet" inputmode="url"></label>
    <p class="actions"><button class="btn btn-small btn-ghost" type="button" data-jitsi-room="<?= e($jitsi) ?>">Vytvořit místnost Jitsi</button>
      <span class="hint">Jitsi Meet je zdarma a bez registrace. Místnost dostane náhodný název; odkaz uvidí jen vlastníci, kteří se v den shromáždění připojí na webu.</span></p>
    <label class="check"><input type="checkbox" name="hybrid_statutes" value="1"<?= (int) $h['statutes_ok'] === 1 ? ' checked' : '' ?>>
      <span>Stanovy umožňují účast a hlasování technickými prostředky <small>Povinné. Bez takového ustanovení ve stanovách (§ 1206 odst. 2 občanského zákoníku) se online účast a hlasování nepočítají – shromáždění musí být prezenční.</small></span></label>
    <details class="hint"><summary>Vzorové ustanovení stanov</summary>
      <p><?= e(HYBRID_STATUTES_CLAUSE) ?></p>
      <p>Změnu stanov schvaluje shromáždění (obvykle kvalifikovanou většinou podle stanov) a zapisuje se do veřejného rejstříku. Znění si nechte zkontrolovat – web ho jen nabízí jako vzor.</p></details>
    <p class="hint">Na shromáždění se vlastníci připojí na stránce termínu tlačítkem „Připojit se online“ – web je zapíše do prezence a otevře videohovor. O návrzích hlasují na webu, když jim hlasování pustíte v Prezenci na místě. <?= function_exists('help_link') ? help_link('shromazdeni-online') : '' ?></p>
  </div>
</fieldset>
