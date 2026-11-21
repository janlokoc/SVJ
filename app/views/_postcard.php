<article class="post-card<?= $p['pinned'] ? ' is-pinned' : '' ?>">
  <p class="post-meta"><time datetime="<?= e($p['published_at']) ?>"><?= cz_date($p['published_at'], true) ?></time>
    <?php if ($p['pinned']): ?><span class="tag tag-pin"><?= icon('pin') ?> důležité</span><?php endif ?>
    <?php if ($p['visibility'] === 'residents'): ?><span class="tag tag-lock"><?= icon('lock') ?> jen pro přihlášené</span><?php endif ?>
  </p>
  <h3><a href="<?= url('/aktuality/' . $p['id']) ?>"><?= e($p['title']) ?></a></h3>
  <p><?= e(excerpt($p['body'])) ?></p>
</article>
