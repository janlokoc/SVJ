<div class="wrap page file-view">
  <div class="file-bar">
    <a class="btn btn-ghost" href="<?= url('/dokumenty') ?>" data-back>← Zpět</a>
    <h1><?= e($d['title']) ?></h1>
    <?php if (empty($external)): ?><a class="btn" href="<?= url('/soubor/' . $d['id']) ?>?stahnout=1" download="<?= e($name) ?>">Stáhnout</a><?php endif ?>
  </div>
  <?php if (!empty($external)): ?>
    <div class="card card-soft"><p>Tento dokument je uložený mimo web společenství (<?= e((string) parse_url($external, PHP_URL_HOST)) ?>). Otevře se v samostatném okně, které zavřete tlačítkem Hotovo.</p>
      <div class="actions"><a class="btn btn-big" href="<?= e($external) ?>" target="_blank" rel="noopener noreferrer">Otevřít dokument</a></div></div>
  <?php elseif ($ext === 'pdf'): ?>
    <iframe class="file-frame" src="<?= url('/soubor/' . $d['id']) ?>" title="<?= e($d['title']) ?>"></iframe>
    <p class="hint">Nezobrazuje se dokument celý? Některá zařízení ukážou v náhledu jen první stránku – použijte tlačítko Stáhnout.</p>
  <?php else: ?>
    <img class="file-image" src="<?= url('/soubor/' . $d['id']) ?>" alt="<?= e($d['title']) ?>">
  <?php endif ?>
</div>
