<?php /* Plovoucí AI asistent v rohu stránky (jako chat na e-shopech). */ $history = $_SESSION['asistent'] ?? []; ?>
<div class="asst" data-asst data-url="<?= e(url('/asistent')) ?>" data-csrf="<?= e(csrf_token()) ?>">
  <button class="asst-fab" type="button" aria-expanded="false" aria-controls="asst-panel" data-asst-toggle>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/></svg><span>Zeptejte se asistenta</span></button>
  <section class="asst-panel" id="asst-panel" aria-label="AI asistent" hidden>
    <header class="asst-head"><strong>AI asistent</strong><small>odpovídá podle dokumentů domu</small>
      <button class="asst-x" type="button" aria-label="Zavřít" data-asst-toggle>×</button></header>
    <ol class="asst-log" aria-live="polite">
      <li class="chat-a asst-hello">Dobrý den, <?= e(cz_vocative(explode(' ', trim(user()['name']))[0])) ?>. Zeptejte se na cokoli o domě – odpovím podle stanov, domovního řádu, dokumentů a oznámení na webu a ukážu, odkud odpověď mám.</li>
      <?php foreach ($history as $h): ?><li class="chat-q"><?= nl2br(e($h['q'])) ?></li><li class="chat-a"><?= assistant_entry_html($h) ?></li><?php endforeach ?>
    </ol>
    <div class="asst-examples"<?= $history ? ' hidden' : '' ?>><p>Na co se můžete zeptat:</p>
      <?php foreach (assistant_examples() as $ex): ?><button type="button" class="asst-chip" data-asst-ask><?= e($ex) ?></button><?php endforeach ?></div>
    <form class="asst-form" data-asst-form>
      <label class="sr-only" for="asst-q">Vaše otázka</label>
      <textarea id="asst-q" name="q" rows="2" maxlength="1000" placeholder="Napište otázku…" required></textarea>
      <button class="btn btn-small" type="submit">Odeslat</button>
    </form>
    <footer class="asst-foot"><a href="<?= url('/asistent') ?>">Na celou stránku</a> · <button type="button" class="link-btn" data-asst-new>Nový rozhovor</button> · <small>Odpovídá AI, může se splést.</small></footer>
  </section>
</div>
