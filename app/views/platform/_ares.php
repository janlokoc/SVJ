<?php /* Vyhledání v ARES; nalezené údaje vyplní pole formuláře označená data-ares-field. $aresUrl = adresa dotazu, $aresFirm = hledat firmy (ne SVJ). */ $aresFirm ??= false; ?>
<div class="ares" data-ares="<?= e($aresUrl . ($aresFirm ? '?firma=1' : '')) ?>"<?= $aresFirm ? ' data-ares-firm' : '' ?>>
  <label for="ares-q"><?= $aresFirm ? 'Najít firmu v ARES' : 'Najít společenství v ARES' ?> <small class="muted">(nepovinné)</small></label>
  <div class="ares-row">
    <input type="search" id="ares-q" data-ares-q maxlength="120" placeholder="<?= $aresFirm ? 'IČO, nebo část názvu firmy' : 'IČO, nebo ulice z názvu – např. 12345678 nebo Zahradní' ?>" autocomplete="off" enterkeyhint="search">
    <button class="btn btn-small" type="button" data-ares-go>Načíst z ARES</button>
  </div>
  <p class="hint" data-ares-status role="status" aria-live="polite">Název, sídlo<?= $aresFirm ? ', IČO a DIČ' : ' a IČO' ?> doplníme z veřejného rejstříku ARES. Pak je jen zkontrolujte.</p>
  <ul class="ares-results" data-ares-results hidden></ul>
</div>
