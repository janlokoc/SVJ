<?php /* Výběr šablony textu: vyplní pole formuláře. $tplList = šablony, $tplTitle / $tplBody = selektory polí. */ if (!empty($tplList)): ?>
<label class="tpl-pick">Šablona firmy <select data-template-fill data-title-target="<?= e($tplTitle) ?>" data-body-target="<?= e($tplBody) ?>"><option value="">— vybrat —</option>
  <?php foreach ($tplList as $tp): ?><option value="<?= (int) $tp['id'] ?>" data-title="<?= e($tp['title']) ?>" data-body="<?= e($tp['body']) ?>"><?= e($tp['name']) ?></option><?php endforeach ?></select></label>
<?php endif ?>
