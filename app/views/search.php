<?php /* Výsledky hledání: $q, $groups, $terms */ $total = array_sum(array_map(fn($g) => $g[2], $groups)); ?>
<div class="wrap page narrow search-page">
  <header class="page-head"><h1>Hledání</h1></header>
  <form method="get" action="<?= url('/hledat') ?>" class="search-form" role="search">
    <label class="sr-only" for="search-q">Co hledáte</label>
    <input type="search" id="search-q" name="q" value="<?= e($q) ?>" placeholder="Např. stanovy, odečet vody, výtah…" autocomplete="off"<?= $q === '' ? ' autofocus' : '' ?>>
    <button class="btn" type="submit"><?= icon('search') ?> Hledat</button>
  </form>
  <?php if ($q !== '' && !$terms): ?>
    <p class="muted">Napište alespoň dvě písmena.</p>
  <?php elseif ($q !== ''): ?>
    <p class="search-count"><?= $total ? 'Nalezeno: <strong>' . $total . '</strong>' : 'Nic jsme nenašli.' ?><?= !user() ? ' Přihlášení obyvatelé najdou víc – i v termínech, diskuzi a dokumentech pro obyvatele.' : '' ?></p>
    <?php if ($groups): ?><nav class="search-jump" aria-label="Skupiny výsledků"><?php foreach ($groups as $k => [$label, , $n]): ?><a href="#v-<?= $k ?>"><?= e($label) ?> <span class="count"><?= $n ?></span></a><?php endforeach ?></nav><?php endif ?>
    <?php foreach ($groups as $k => [$label, $rows, $n]): ?>
      <section class="search-group" id="v-<?= $k ?>">
        <h2><?= e($label) ?> <small class="muted"><?= $n ?></small></h2>
        <ul class="search-results">
          <?php foreach ($rows as [$title, $href, $snippet, $meta]): ?>
            <li><a href="<?= url($href) ?>"><strong><?= search_snippet($title, $terms, 200) ?></strong></a><?php if ($meta !== ''): ?> <small class="muted"><?= e($meta) ?></small><?php endif ?>
              <?php if ($snippet !== ''): ?><p><?= $snippet ?></p><?php endif ?></li>
          <?php endforeach ?>
        </ul>
        <?php if ($n > count($rows)): ?><p class="hint">Zobrazeno prvních <?= count($rows) ?> z <?= $n ?> – zkuste dotaz upřesnit.</p><?php endif ?>
      </section>
    <?php endforeach ?>
  <?php endif ?>
  <p class="hint">Hledá se v nadpisech i textu, u dokumentů i uvnitř souborů (PDF, Word, Excel, OpenOffice). Háčky a čárky psát nemusíte.</p>
</div>
