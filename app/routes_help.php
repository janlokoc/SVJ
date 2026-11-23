<?php
/*
 * Nápověda: jen pro věci, které web sám nevysvětlí – co si správce přináší zvenku (výpis z katastru,
 * nastavení u odečtové firmy) nebo postupy o několika krocích (shromáždění). Každý návod má snímky
 * a postup ověřený automatickým během (tools/napoveda), datum ověření je u článku.
 */

/** Články: slug => [název, krátký popis, veřejný?, adresy administrace, u kterých se nabízí]. */
const HELP = [
    'import-z-katastru' => ['Import jednotek z katastru', 'Jak z Nahlížení do katastru nemovitostí naráz založit jednotky s vlastníky a podíly.', false, ['/admin/jednotky']],
    'shromazdeni' => ['Shromáždění od pozvánky po zápis', 'Přihlášky, plné moci, prezence na místě, hlasování o návrzích a zápis.', false, ['/admin/terminy']],
    'upozorneni-do-telefonu' => ['Web na ploše telefonu a upozornění', 'Jak si web přidat na plochu jako aplikaci a zapnout upozornění na displej (iPhone i Android).', true, ['/muj-ucet']],
    'odecty-api' => ['Dálkové odečty: příjem dat od odečtové firmy', 'Technický popis pro odečtovou firmu – formát dat, ukázky a odpovědi.', true, ['/admin/odecty']],
];

/** Odkaz „Jak na to?“ na článek nápovědy. */
function help_link(string $slug, string $label = 'Jak na to?'): string
{
    if (!isset(HELP[$slug])) {
        return '';
    }
    return '<a class="help-link" href="' . url('/admin/napoveda/' . $slug) . '">' . icon('doc') . ' ' . e($label) . '</a>';
}
/** Kdy a čím byl postup naposledy ověřen (zapisuje tools/napoveda/overit.js). */
function help_verified(string $slug): ?array
{
    static $all = null;
    if ($all === null) {
        $f = __DIR__ . '/../assets/help/overeno.json';
        $all = is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
    }
    return $all[$slug] ?? null;
}
/** Obrázek do návodu: snímek obrazovky s popiskem. */
function help_fig(string $file, string $caption): string
{
    $path = __DIR__ . '/../assets/help/' . $file;
    if (!preg_match('/^[a-z0-9-]+\.(png|jpg)$/', $file) || !is_file($path)) {
        return '';
    }
    [$w, $h] = getimagesize($path) ?: [0, 0];
    return '<figure class="help-fig"><img src="' . e(asset('help/' . $file)) . '" alt="' . e($caption) . '" width="' . (int) ($w / 2) . '" height="' . (int) ($h / 2) . '" loading="lazy"><figcaption>' . e($caption) . '</figcaption></figure>';
}

$helpRender = function (string $slug, bool $public) {
    $a = HELP[$slug] ?? null;
    if (!$a || ($public && !$a[2])) {
        not_found();
    }
    render('help/article', ['title' => $a[0], 'slug' => $slug, 'a' => $a, 'public' => $public, 'verified' => help_verified($slug)]);
};
$route('GET', '/admin/napoveda', function () {
    require_admin();
    render('help/index', ['title' => 'Nápověda']);
});
$route('GET', '/admin/napoveda/([a-z0-9-]+)', function (string|int $slug) use ($helpRender) {
    require_admin();
    $helpRender((string) $slug, false);
});
// Veřejné články (technický popis pro odečtovou firmu) jdou poslat odkazem i lidem bez účtu.
$route('GET', '/napoveda/([a-z0-9-]+)', function (string|int $slug) use ($helpRender) {
    $helpRender((string) $slug, true);
});
