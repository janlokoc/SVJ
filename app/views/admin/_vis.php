<label>Kdo to uvidí
  <select name="visibility">
    <?php foreach (VISIBILITY as $k => $label): ?><option value="<?= $k ?>"<?= $current === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?>
  </select>
</label>
