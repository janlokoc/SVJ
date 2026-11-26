<?php /* AI pomocník mimo administraci domu (obyvatel, partnerský portál): $aiUrl, $aiTarget, $aiTasks, volitelně $aiContext. Zobrazí se jen když ho volající povolí ($aiShow). */
if (!empty($aiShow)): ?>
<div class="ai-box ai-mini" data-ai-target="<?= e($aiTarget) ?>" data-ai-url="<?= e($aiUrl) ?>" data-ai-context="<?= e($aiContext ?? '') ?>">
  <span class="ai-label">AI pomocník</span>
  <?php foreach ($aiTasks as $task): ?><button class="btn btn-small btn-ghost" type="button" data-ai-task="<?= e($task) ?>"><?= e(AI_TASKS[$task][0]) ?></button><?php endforeach ?>
  <button class="btn btn-small btn-ghost" type="button" data-ai-undo hidden>Vrátit původní text</button>
  <span class="ai-status" role="status"></span>
  <p class="hint">Text z pole se odešle ke zpracování službě Claude (Anthropic) a výsledek pole přepíše. Před odesláním ho prosím přečtěte.</p>
</div>
<?php endif ?>
