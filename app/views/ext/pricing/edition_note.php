<?php /* Modul G: pod cenou edice v app/views/platform/_editions.php ($ek, $price, $edView, v administraci $t). */
if ($edView === 'landing'):
    if ($price): ?>
    <p class="edition-vat"><?= price_vat_html($price) ?><?= price_size_note($ek) !== '' ? '<br><small class="muted">' . e(ucfirst(price_size_note($ek))) . '</small>' : '' ?></p>
    <?php elseif ($ek === 'free' && free_max_units() > 0): ?>
    <p class="edition-vat"><small class="muted">Pro domy do <?= free_max_units() ?> jednotek</small></p>
    <?php endif;
elseif ($price && !empty($t) && ($edSur = edition_surcharge($ek, tenant_unit_count($t))) > 0): ?>
    <p class="edition-vat"><small class="muted">Včetně přirážky <?= money_czk($edSur) ?> za <?= tenant_unit_count($t) - price_flat_units() ?> jednotek nad <?= price_flat_units() ?>.</small></p>
<?php endif ?>
