<div class="wrap page narrow">
  <?php $back = ($GLOBALS['BILLING']['mode'] ?? 'free') === 'free' ? url('/admin') : url('/admin/predplatne') . '#pripravujeme'; $notifyUrl = url('/admin/predplatne/zajem'); $askEmail = false; require __DIR__ . '/../platform/_feature.php'; ?>
</div>
