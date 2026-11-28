<?php
/*
 * Nápověda: jen pro věci, které web sám nevysvětlí – co si správce přináší zvenku (výpis z katastru,
 * nastavení u odečtové firmy) nebo postupy o několika krocích (shromáždění). Každý návod má snímky
 * a postup ověřený automatickým během (tools/napoveda), datum ověření je u článku.
 */

/** Články: slug => [název, krátký popis, veřejný?, adresy administrace, u kterých se nabízí, (volitelně) funkce beta programu – článek je vidět, jen když je vidět aspoň jedna z nich]. */
const HELP = [
    'import-z-katastru' => ['Import jednotek z katastru', 'Jak z Nahlížení do katastru nemovitostí naráz založit jednotky s vlastníky a podíly.', false, ['/admin/jednotky']],
    'shromazdeni' => ['Shromáždění od pozvánky po zápis', 'Přihlášky, plné moci, prezence na místě, hlasování o návrzích a zápis.', false, ['/admin/terminy']],
    'upozorneni-do-telefonu' => ['Web na ploše telefonu a upozornění', 'Jak si web přidat na plochu jako aplikaci a zapnout upozornění na displej (iPhone i Android).', true, ['/muj-ucet']],
    'parovani-plateb' => ['Párování plateb s bankou', 'Jak nahrát výpis z banky (nebo zapojit Fio API), aby se zálohy jednotek aktualizovaly samy.', false, ['/admin/platby']],
    'odecty-api' => ['Dálkové odečty: příjem dat od odečtové firmy', 'Technický popis pro odečtovou firmu – formát dat, ukázky a odpovědi.', true, ['/admin/odecty']],
    'hlasovani-per-rollam' => ['Hlasování mimo zasedání (per rollam)', 'Co říká zákon, ověření hlasu, papírové lístky a protokol s kontrolou neporušenosti.', false, ['/admin/hlasovani']],
    'shromazdeni-online' => ['Shromáždění online a hybridně', 'Stanovy, zapnutí pro dům, připojení vlastníků přes videohovor, prezence a hlasování na dálku.', false, ['/admin/shromazdeni-online', '/admin/terminy'], ['shromazdeni-online']],
    'vyuctovani-sluzeb' => ['Vyúčtování služeb přes web', 'Doručení vyúčtování vlastníkům, potvrzení převzetí, námitky a zákonné lhůty.', false, ['/admin/vyuctovani']],
    'plan-oprav' => ['Plán oprav a fond oprav', 'Jak zadat stav fondu a plánované opravy a z výpočtu poznat, zda je třeba zvýšit příspěvky.', false, ['/admin/plan-oprav']],
    'pohoda-konto' => ['Konto bytu z POHODY', 'Jak z POHODY nahrát předpisy záloh, pohledávky a platby, aby je vlastníci viděli u svého bytu.', false, ['/admin/platby']],
    'ucetnictvi' => ['Propojení s účetnictvím (POHODA, Money, ABRA Flexi)', 'Export adresáře, předpisů a plateb do účetnictví a import z programu správy domů.', false, ['/admin/ucetnictvi']],
    'propojeni-banky' => ['Propojení s účty u dalších bank', 'Automatické stahování plateb z ČS, KB, ČSOB a dalších bank přes rozhraní PSD2.', false, ['/admin/platby'], ['bank-psd2']],
    'sms-zpravy' => ['SMS zprávy vlastníkům', 'Kredit, výběr příjemců a odeslání SMS při havárii nebo odstávce.', false, ['/admin/sms'], ['sms']],
    'katastr-a-rejstriky' => ['Katastr, insolvence a exekuce', 'Hlídání změn v katastru, insolvencí vlastníků a ověření v Centrální evidenci exekucí.', false, ['/admin/rejstriky'], ['katastr', 'insolvence', 'exekuce']],
    'data-a-smlouva' => ['Data domu a smlouva o zpracování', 'Export všech dat webu a smlouva o zpracování osobních údajů pro dokumentaci GDPR.', false, ['/admin/data-domu']],
    'prihlaseni-sso' => ['Přihlášení přes Google, Microsoft, Apple a Facebook', 'Přihlášení jedním kliknutím a propojení účtu na webu s účtem u Googlu, Microsoftu, Applu nebo Facebooku.', true, ['/muj-ucet'], ['sso']],
];
/** Je článek vidět? Články k funkcím beta programu jen tam, kde je funkce vidět. */
function help_visible(string $slug): bool
{
    $a = HELP[$slug] ?? null;
    if (!$a) {
        return false;
    }
    if (empty($a[4]) || !function_exists('beta_visible')) {
        return true;
    }
    foreach ($a[4] as $key) {
        if (beta_visible($key)) {
            return true;
        }
    }
    return false;
}

/** Odkaz „Jak na to?“ na článek nápovědy. */
function help_link(string $slug, string $label = 'Jak na to?'): string
{
    if (!help_visible($slug)) {
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
    if (!$a || ($public && !$a[2]) || !help_visible($slug)) {
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
