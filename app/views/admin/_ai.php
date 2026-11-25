<?php /* AI pomocník u textového pole: $aiTarget = selektor pole, $aiTasks = které úkoly nabídnout, $aiContext = volitelný kontext (např. text hlášení), $aiFields = [jméno pole => popisek] – hodnoty dalších polí formuláře se přidají ke kontextu */
if (ai_available() && function_exists('plan_has') && !plan_has('ai-pomocnik')): ?>
<p class="ai-box ai-upsell"><span class="ai-label">AI pomocník</span> Tento text Vám může napsat nebo uhladit AI – je součástí edice Standard.
  <a href="<?= url('/admin/predplatne') ?>#funkce"><?= plan_state('ai-pomocnik') === 'available' ? 'Vyzkoušet ' . PLAN_TRIAL_DAYS . ' dní zdarma' : 'Přejít na Standard' ?></a></p>
<?php elseif (ai_available()): ?>
<div class="ai-box" data-ai-target="<?= e($aiTarget) ?>" data-ai-url="<?= url('/admin/ai') ?>" data-ai-context="<?= e($aiContext ?? '') ?>"<?= !empty($aiFields) ? ' data-ai-fields="' . e(json_encode($aiFields, JSON_UNESCAPED_UNICODE)) . '"' : '' ?><?= !empty($aiOutput) ? ' data-ai-output="' . e($aiOutput) . '"' : '' ?>>
  <span class="ai-label">AI pomocník</span>
  <?php foreach ($aiTasks as $task): ?><button class="btn btn-small btn-ghost" type="button" data-ai-task="<?= e($task) ?>"><?= e(AI_TASKS[$task][0]) ?></button><?php endforeach ?>
  <button class="btn btn-small btn-ghost" type="button" data-ai-undo hidden>Vrátit původní text</button>
  <span class="ai-status" role="status"></span>
  <p class="hint">Text z pole se odešle ke zpracování službě Claude (Anthropic) a výsledek <?= !empty($aiOutput) ? 'se vloží do textu níže' : 'pole přepíše' ?>. Vždy ho před zveřejněním přečtěte; nevkládejte osobní údaje, které v textu být nemusí.</p>
</div>
<?php endif ?>
