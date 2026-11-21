<?php /** @var array $docs */ ?>
<ul class="doc-list">
<?php foreach ($docs as $d): $ext = strtoupper(pathinfo($d['original_name'], PATHINFO_EXTENSION) ?: 'PDF'); ?>
  <li>
    <a class="doc" href="<?= url('/soubor/' . $d['id']) ?>">
      <span class="doc-ext"><?= e($ext) ?></span>
      <span class="doc-main"><span class="doc-title"><?= e($d['title']) ?></span>
        <span class="doc-meta"><?= e(implode(' · ', array_filter([cz_date($d['doc_date']), file_size((int) $d['size'])]))) ?><?php if ($d['unit_id']): ?> <span class="tag tag-pin"><?= icon('lock') ?> jen Vaše jednotka</span><?php elseif ($d['visibility'] === 'residents'): ?> <span class="tag tag-lock"><?= icon('lock') ?> jen pro přihlášené</span><?php endif ?></span>
      </span>
      <span class="doc-go"><?= icon('download') ?></span>
    </a>
  </li>
<?php endforeach ?>
</ul>
