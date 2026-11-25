<?php /* Vyhledání společenství v ARES; nalezené údaje vyplní pole formuláře označená data-ares-field. $aresUrl = adresa dotazu. */ ?>
<div class="ares" data-ares="<?= e($aresUrl) ?>">
  <label for="ares-q">Najít společenství v ARES <small class="muted">(nepovinné)</small></label>
  <div class="ares-row">
    <input type="search" id="ares-q" data-ares-q maxlength="120" placeholder="IČO, nebo ulice z názvu – např. 01395858 nebo Zahradní" autocomplete="off" enterkeyhint="search">
    <button class="btn btn-small" type="button" data-ares-go>Načíst z ARES</button>
  </div>
  <p class="hint" data-ares-status role="status" aria-live="polite">Název, sídlo a IČO doplníme z veřejného rejstříku ARES. Pak je jen zkontrolujte.</p>
  <ul class="ares-results" data-ares-results hidden></ul>
</div>
