<?php /* AI pomocník u textového pole: $aiTarget = selektor pole, $aiTasks = které úkoly nabídnout, $aiContext = volitelný kontext (např. text hlášení) */
if (ai_available()): ?>
<div class="ai-box" data-ai-target="<?= e($aiTarget) ?>" data-ai-url="<?= url('/admin/ai') ?>" data-ai-context="<?= e($aiContext ?? '') ?>">
  <span class="ai-label">AI pomocník</span>
  <?php foreach ($aiTasks as $task): ?><button class="btn btn-small btn-ghost" type="button" data-ai-task="<?= e($task) ?>"><?= e(AI_TASKS[$task][0]) ?></button><?php endforeach ?>
  <button class="btn btn-small btn-ghost" type="button" data-ai-undo hidden>Vrátit původní text</button>
  <span class="ai-status" role="status"></span>
  <p class="hint">Text z pole se odešle ke zpracování službě Claude (Anthropic) a výsledek pole přepíše. Vždy ho před zveřejněním přečtěte; nevkládejte osobní údaje, které v textu být nemusí.</p>
</div>
<?php endif ?>
