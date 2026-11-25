<?php
/* Výběr existujících dokumentů: nahoře zaškrtnuté a nejnovější, starší se ukážou hledáním nebo tlačítkem „Zobrazit všechny“.
   $pickId, $pickLabel, $documents, $pickSelected = id zaškrtnutých */
$pickRecent = 10;
// „Nejnovější“ podle data dokumentu (datum nahrání nic neříká – po převodu webu mají všechny stejné); bez data na konec.
usort($documents, fn($a, $b) => [(string) $a['doc_date'] === '' ? 1 : 0, (string) $b['doc_date']] <=> [(string) $b['doc_date'] === '' ? 1 : 0, (string) $a['doc_date']]);
$pickSel = array_flip($pickSelected);
$pickRows = array_merge(array_filter($documents, fn($d) => isset($pickSel[(int) $d['id']])), array_filter($documents, fn($d) => !isset($pickSel[(int) $d['id']])));
$pickMore = max(0, count($documents) - count(array_filter($documents, fn($d) => isset($pickSel[(int) $d['id']]))) - $pickRecent);
$i = 0;
?>
<label><?= e($pickLabel) ?> <input type="search" data-filter="#<?= e($pickId) ?>" placeholder="Hledat ve všech dokumentech (<?= count($documents) ?>)…"></label>
<div class="pick" id="<?= e($pickId) ?>">
  <?php foreach ($pickRows as $d): $sel = isset($pickSel[(int) $d['id']]); $more = !$sel && $i++ >= $pickRecent; ?>
    <label class="check"<?= $more ? ' data-more hidden' : '' ?>><input type="checkbox" name="documents[]" value="<?= $d['id'] ?>"<?= $sel ? ' checked' : '' ?>>
      <span><?= e($d['title']) ?> <small><?= CATEGORIES[$d['category']] ?? '' ?><?= $d['doc_date'] ? ' · ' . cz_date($d['doc_date']) : '' ?></small></span></label>
  <?php endforeach ?>
  <?php if (!$documents): ?><p class="hint">Zatím tu nejsou žádné dokumenty.</p><?php endif ?>
</div>
<?php if ($pickMore): ?><p class="pick-more hint">Ukázáno je <?= $pickRecent ?> nejnovějších<?= $pickSelected ? ' a vybrané' : '' ?>. Starší najdete hledáním, nebo <button type="button" class="link-btn" data-pick-all="#<?= e($pickId) ?>">zobrazte všechny (<?= count($documents) ?>)</button>.</p><?php endif ?>
