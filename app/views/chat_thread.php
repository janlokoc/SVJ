<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/zpravy') ?>">← Všechny zprávy</a></p>
  <header class="page-head chat-head"><h1><?= e($other['name']) ?></h1>
    <p class="lead"><?= position_label($other) !== '' ? '<strong>' . e(position_label($other)) . '</strong>' : '' ?><?= position_label($other) !== '' && !empty($other['units']) ? ' · ' : '' ?><?= e($other['units'] ?? '') ?></p></header>

  <ol class="chat" id="chat" data-chat-url="<?= url('/zpravy/' . $other['id'] . '/nove') ?>" data-chat-last="<?= $messages ? (int) end($messages)['id'] : 0 ?>" aria-live="polite">
    <?php if (!$messages): ?><li class="chat-empty">Zatím jste si nenapsali. První zprávu pošlete níže.</li><?php endif ?>
    <?php foreach ($messages as $m): ?>
    <li class="chat-msg<?= $m['mine'] ? ' is-mine' : '' ?>" data-id="<?= $m['id'] ?>"><div class="chat-bubble"><?= $m['html'] ?></div><small><?= e($m['time']) ?><?= $m['mine'] && $m['read'] ? ' · přečteno' : '' ?></small></li>
    <?php endforeach ?>
  </ol>
  <span id="konec"></span>

  <?php if ($canWrite): ?>
  <form method="post" action="<?= url('/zpravy/' . $other['id']) ?>" class="card form chat-form">
    <?= csrf_field() ?>
    <label>Zpráva pro: <?= e($other['name']) ?> <textarea name="body" rows="3" maxlength="<?= CHAT_MAX_LENGTH ?>" required placeholder="Napište zprávu…"></textarea></label>
    <?php $aiShow = user() && ai_resident_available(); $aiUrl = url('/ai-text'); $aiTarget = 'textarea[name=body]'; $aiTasks = ['polish', 'shorten']; $aiContext = ''; require __DIR__ . '/_ai_mini.php'; ?>
    <div class="actions"><button class="btn" type="submit">Odeslat</button></div>
  </form>
  <?php else: ?>
  <p class="note">Tento člověk si zprávy od sousedů vypnul nebo jeho účet už není aktivní. Starší zprávy zůstávají ke čtení.</p>
  <?php endif ?>
</div>
