<div class="wrap page">
  <header class="page-head row"><h1>Zprávy e-mailem</h1><a class="btn" href="<?= url('/admin/zpravy/nova') ?>">+ Nová zpráva</a></header>
  <p class="lead">Důležité zprávy výboru rozeslané na e-maily obyvatel, kteří mají na webu účet. Aktualitu rozešlete také přímo ze seznamu aktualit odkazem „Poslat e-mailem“.</p>
  <?php if (!$mailings): ?><p class="empty">Zatím nebyla odeslána žádná zpráva.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Předmět</th><th>Komu</th><th>Odesláno</th><th>Kdy</th><th>Kdo</th></tr></thead>
    <tbody>
    <?php foreach ($mailings as $m): ?>
      <tr>
        <td data-label="Předmět"><details><summary><strong><?= e($m['subject']) ?></strong></summary><div class="prose mail-body"><?= format_text($m['body']) ?></div>
          <?php if ($m['failed_list']): ?><p class="hint">Nepodařilo se odeslat: <?= e(str_replace("\n", ', ', $m['failed_list'])) ?></p><?php endif ?></details></td>
        <td data-label="Komu"><?= $m['audience'] === 'owners' ? 'Vlastníci' : 'Všichni obyvatelé' ?></td>
        <td data-label="Odesláno"><?= (int) $m['sent_count'] ?><?= $m['failed_count'] ? ' <span class="tag tag-pin">' . (int) $m['failed_count'] . ' selhalo</span>' : '' ?></td>
        <td data-label="Kdy"><?= cz_datetime($m['sent_at']) ?></td>
        <td data-label="Kdo"><?= e($m['author_name'] ?? '') ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
