<?php
declare(strict_types=1);

/*
 * Modul G – srovnání s konkurencí (veřejná stránka /srovnani, zapíná provozovatel v Nastavení služby: compare_on).
 *
 * Srovnávací reklama (§ 2980 zákona č. 89/2012 Sb., občanský zákoník) je přípustná, jen když srovnává objektivně
 * podstatné, relevantní, ověřitelné a typické vlastnosti služeb, které uspokojují tutéž potřebu, a nezlehčuje konkurenta.
 * Proto: jen údaje z webu dodavatele, u každého zdroj a datum ověření; co nešlo ověřit, je výslovně „neověřeno“.
 * Podklad: rešerše „Konkurence webů pro SVJ“ (listopad 2026). Údaje NIKDO nezkoušel v ostrém provozu – jde o to, co
 * dodavatel uvádí na svém webu. Před zveřejněním a pak aspoň jednou za čtvrtletí zkontrolujte ceny na uvedených adresách
 * a změňte datum ověření. Vztah k DPH: 'incl' = ceny s DPH, 'excl' = bez DPH, 'final' = neplátce (cena konečná),
 * 'unknown' = na webu neuvedeno.
 */

const COMPARE_UNITS = 24;   // modelový dům (průměrné SVJ má kolem 24–25 bytů)

/**
 * Cena za modelový dům s 24 jednotkami za rok. Klíč => [název služby (tarif), dodavatel, výpočet, cena za rok podle webu,
 * vztah k DPH, poznámka, zdroj, ověřeno (RRRR-MM)].
 */
const COMPARE_PRICES = [
    'sousede' => ['Sousedé.cz – Plná verze', '', '240 Kč za jednotku a rok (min. 4 440 Kč)', 5760, 'incl', 'Exekuce a insolvence za příplatek 75 Kč za jednotku.', 'https://www.sousede.cz/cenik', '2026-11'],
    'evlastnici' => ['eVlastníci – kompletní služby', 'Externity Group', '290 Kč za jednotku a rok (min. 3 990 Kč)', 6960, 'incl', '', 'https://aplikace.evlastnici.cz/', '2026-11'],
    'domee-domovnik' => ['Domee – Domovník', 'Deveeo s.r.o.', '14 Kč za jednotku a měsíc', 4032, 'final', 'Bez shromáždění. Garáže a sklepy se neúčtují.', 'https://domee.app/cenik', '2026-11'],
    'domee-partak' => ['Domee – Parťák', 'Deveeo s.r.o.', '19 Kč za jednotku a měsíc', 5472, 'final', 'Se shromážděním.', 'https://domee.app/cenik', '2026-11'],
    'vespol' => ['Vespol – Start', '', 'paušál do 50 jednotek', 2490, 'final', '', 'https://vespol.cz/', '2026-11'],
    'webdomu' => ['webDOMU – SVJ', 'webDOMU s.r.o.', 'paušál', 2400, 'unknown', 'V ceně 800 SMS ročně. Web uvádí „ceny konečné“.', 'https://www.webdomu.cz/s/cenik', '2026-11'],
    'websvj' => ['WebSVJ – Standard', '', 'paušál', 2400, 'unknown', 'Levnější verze s reklamou 1 600 Kč.', 'https://www.websvj.cz/', '2026-11'],
    'radce' => ['Rádce pro SVJ – Standard', 'BMCO s.r.o.', 'paušál', 1600, 'unknown', 'Levnější verze s reklamou 1 200 Kč.', 'https://www.radceprosvj.cz/webove-stranky-svj/', '2026-11'],
    'spoluvlastnici' => ['Spoluvlastníci', 'Spoluvlastníci s.r.o.', '1 500 Kč + 50 Kč za jednotku a rok', 2700, 'unknown', 'Příklady cen na webu dodavatele se navzájem liší.', 'https://www.spoluvlastnici.cz/', '2026-11'],
    'kliker' => ['Kliker Domy – Standard', 'Mitro Group Solutions s.r.o.', '799 Kč měsíčně', 9588, 'unknown', 'Tarif Mini (399 Kč měsíčně) jen do 20 jednotek.', 'https://kliker.cz/svj-bd', '2026-11'],
    'perrollam' => ['Per rollam (jen hlasování)', 'Railsformers s.r.o.', '10 Kč za jednotku a měsíc při roční platbě', 2880, 'incl', 'Jen hlasování; elektronický podpis se platí zvlášť.', 'https://per-rollam.com/', '2026-11'],
    'svjmanazer' => ['SVJ Manažer Online (jen hlasování)', '', 'paušál', 1000, 'unknown', 'Jen hlasování a kvórum.', 'https://svjmanazer.twomedia.cz/', '2026-11'],
];

/** Sloupce tabulky funkcí: klíč => [název, zdroj, ověřeno]. */
const COMPARE_VENDORS = [
    'sousede' => ['Sousedé.cz', 'https://www.sousede.cz/', '2026-11'],
    'evlastnici' => ['eVlastníci', 'https://aplikace.evlastnici.cz/', '2026-11'],
    'domee' => ['Domee', 'https://domee.app/svj-aplikace/', '2026-11'],
    'vespol' => ['Vespol', 'https://vespol.cz/', '2026-11'],
    'webdomu' => ['webDOMU', 'https://www.webdomu.cz/', '2026-11'],
    'websvj' => ['WebSVJ / Rádce pro SVJ', 'https://www.websvj.cz/', '2026-11'],
    'spoluvlastnici' => ['Spoluvlastníci', 'https://www.spoluvlastnici.cz/', '2026-11'],
    'kliker' => ['Kliker', 'https://kliker.cz/svj-bd', '2026-11'],
    'ifm24' => ['IFM24', 'https://www.ifm24.cz/', '2026-11'],
];

/**
 * Funkce: název => [náš stav, [dodavatel => stav]]. Stav: 'A' = ano, 'C' = částečně (za dvojtečkou poznámka, i u 'A'), 'N' = na webu dodavatele neuvedeno,
 * '?' = neověřeno (jen z nespolehlivého zdroje). Pořadí dodavatelů jako v COMPARE_VENDORS.
 */
const COMPARE_FEATURES = [
    'Veřejný web domu' => ['A', ['?', 'N', 'N', 'C:veřejná nástěnka s QR kódem', 'A', 'A', 'C:vlastní doména', 'N', 'N']],
    'Hlasování per rollam podle podílů' => ['A', ['A', 'A', 'A', 'A', 'A', 'C:ankety podle podílů', 'C:ankety', 'A', 'A']],
    'Shromáždění: prezence, kvórum, plné moci' => ['A', ['?', 'N', 'C:v tarifu Parťák', 'N', '?', 'N', 'N', 'A', 'N']],
    'Ověření hlasu (SMS, Bank iD, podpis)' => ['N', ['N', 'C:SMS kód', 'C:e-mailový kód', 'N', 'N', 'N', 'N', 'N', 'N']],
    'Zápis ze shromáždění' => ['A', ['N', 'N', 'A', 'C:AI zápis ohlášen', 'N', 'C:zápisy na nástěnce', 'N', '?', 'N']],
    'Import vlastníků z katastru' => ['C:vložením výpisu', ['A', 'A', 'A', 'N', 'A', 'A', 'A', 'N', 'C:za 100 Kč']],
    'Hlídání katastru, insolvencí, exekucí' => ['C:insolvence, ve zkušebním provozu', ['C:doplněk', 'A', 'C:v přípravě', 'N', 'C:doplněk', 'C:doplněk', 'A', 'C:insolvence', 'N']],
    'SMS zprávy' => ['N', ['N', 'C:jen přihlášení', 'N', 'N', 'A', 'N', 'N', 'N', 'N']],
    'Mobilní aplikace' => ['C:web na ploše telefonu s upozorněními', ['A', 'A', 'A', 'C:Android', 'N', 'N', 'N', 'N', 'N']],
    'Předpisy plateb, párování a upomínky' => ['A:edice Premium', ['C:přes program DOMUS', 'N', 'N', 'C:tarif Plus', 'C:import z banky', 'N', 'N', 'A', 'A']],
    'Dálkové odečty a měsíční informace o spotřebě' => ['A:od edice Standard', ['N', 'N', 'N', 'C:odečty s exportem', 'A', 'N', 'A', 'A', 'A']],
    'Revize s připomínkami' => ['A', ['C:', 'N', 'A', 'A', 'A', 'N', 'N', 'A', 'A']],
    'Rezervace společných prostor' => ['A:edice Premium', ['N', 'N', 'N', 'A', 'N', 'N', 'N', 'N', 'N']],
    'Hlášení závad s řešitelem' => ['A', ['A', 'N', 'A', 'A', 'A', 'C:', 'A', 'A', 'A']],
    'AI pomocník a asistent' => ['A:od edice Standard, asistent v Premium', ['N', 'N', 'N', 'C:ohlášeno', 'N', 'N', 'N', 'N', 'N']],
    'Portál pro správce více domů' => ['A', ['C:na dotaz', 'C:', 'A', 'C:tarif Profi', 'N', 'N', 'N', 'A', 'A']],
    'Export do účetních programů' => ['N', ['C:přes DOMUS', 'N', 'N', 'C:odečty', 'N', 'C:Excel', 'N', 'A', 'A']],
];
const COMPARE_STATES = ['A' => 'ano', 'C' => 'částečně', 'N' => 'neuvedeno', '?' => 'neověřeno'];

function compare_enabled(): bool
{
    return pset('compare_on') === '1';
}
/** Stav buňky: ['A'|'C'|'N'|'?', poznámka]. */
function compare_cell(string $v): array
{
    $code = $v[0] ?? 'N';
    return [isset(COMPARE_STATES[$code]) ? $code : 'N', ($v[1] ?? '') === ':' ? trim(substr($v, 2)) : ''];
}
/** Naše řádky funkcí: AI jen tehdy, když ho provozovatel skutečně zapnul. */
function compare_features(): array
{
    $out = COMPARE_FEATURES;
    if (!(function_exists('ai_configured') && ai_configured())) {
        $out['AI pomocník a asistent'][0] = 'N';
    }
    return $out;
}
/** Cena bez DPH a s DPH (null = neznámá). Ceny s DPH přepočítává sazbou 21 %. */
function compare_net_gross(int $price, string $vat): array
{
    return match ($vat) {
        'incl' => [(int) round($price / 1.21), $price],
        'excl' => [$price, (int) round($price * 1.21)],
        'final' => [$price, $price],
        default => [null, null],
    };
}
/** Řádky cenové tabulky seřazené podle ceny; naše edice se počítají z ceníku pro COMPARE_UNITS jednotek. */
function compare_price_rows(): array
{
    $rows = [];
    foreach (['standard', 'premium'] as $ed) {
        $net = edition_price($ed, COMPARE_UNITS);
        $rows[] = ['ours' => true, 'name' => pset('service_name') . ' – ' . EDITIONS[$ed], 'calc' => price_flat_units() >= COMPARE_UNITS ? 'paušál za dům' : 'paušál + přirážka za jednotku',
            'price' => $net, 'vat' => vat_payer() ? 'excl' : 'final', 'net' => $net, 'gross' => (int) round(price_gross($net)), 'note' => '', 'source' => '/cenik', 'verified' => date('Y-m')];
    }
    foreach (COMPARE_PRICES as $key => [$name, $vendor, $calc, $price, $vat, $note, $src, $verified]) {
        [$net, $gross] = compare_net_gross($price, $vat);
        $rows[] = ['ours' => false, 'key' => $key, 'name' => $name, 'vendor' => $vendor, 'calc' => $calc, 'price' => $price, 'vat' => $vat, 'net' => $net, 'gross' => $gross, 'note' => $note, 'source' => $src, 'verified' => $verified];
    }
    usort($rows, fn($a, $b) => ($a['gross'] ?? $a['price']) <=> ($b['gross'] ?? $b['price']));
    return $rows;
}
/** Nejstarší datum ověření (pro upozornění na stránce a v konzoli). */
function compare_oldest_check(): string
{
    return min(array_merge(array_column(COMPARE_PRICES, 7), array_column(COMPARE_VENDORS, 2)));
}
function compare_month_label(string $ym): string
{
    return preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) ? (int) $m[2] . '/' . $m[1] : $ym;
}
