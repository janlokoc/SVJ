<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul G: srovnání s konkurencí, kampaňová stránka, partnerský program, ceník s DPH, aplikace pro Android (assetlinks). */
final class ModGSiteTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $old) {
            if ($old === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
                pset('__reset');
            } else {
                pset_save($key, $old);
            }
        }
        $this->saved = [];
        parent::tearDown();
    }

    private function setPset(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->saved)) {
            $v = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
            $this->saved[$key] = $v === false ? null : (string) $v;
        }
        pset_save($key, $value);
    }

    /** Vykreslí šablonu bez rozvržení stránky. */
    private function view(string $name, array $vars = []): string
    {
        extract($vars);
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/' . $name . '.php';
        return (string) ob_get_clean();
    }

    // ---------- Srovnání ----------

    public function testCompareDataHasSourceAndVerificationDate(): void
    {
        foreach (COMPARE_PRICES as $key => $row) {
            $this->assertCount(8, $row, $key);
            [$name, , $calc, $price, $vat, , $src, $verified] = $row;
            $this->assertNotSame('', $name);
            $this->assertNotSame('', $calc);
            $this->assertGreaterThan(0, $price, $key);
            $this->assertContains($vat, ['incl', 'excl', 'final', 'unknown'], $key);
            $this->assertStringStartsWith('https://', $src, $key);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $verified, $key);
        }
        foreach (COMPARE_VENDORS as $key => [$name, $src, $verified]) {
            $this->assertStringStartsWith('https://', $src, $key);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $verified);
        }
        foreach (COMPARE_FEATURES as $feature => [$ours, $theirs]) {
            $this->assertCount(count(COMPARE_VENDORS), $theirs, $feature);
            foreach ([$ours, ...$theirs] as $cell) {
                $this->assertArrayHasKey(compare_cell($cell)[0], COMPARE_STATES, $feature . ': ' . $cell);
            }
        }
        $this->assertSame('2026-11', compare_oldest_check());
        $this->assertSame('11/2026', compare_month_label('2026-11'));
    }

    public function testCompareCellAndVat(): void
    {
        $this->assertSame(['C', 'doplněk'], compare_cell('C:doplněk'));
        $this->assertSame(['A', 'edice Premium'], compare_cell('A:edice Premium'));
        $this->assertSame(['?', ''], compare_cell('?'));
        $this->assertSame(['N', ''], compare_cell('X'));
        $this->assertSame([4760, 5760], compare_net_gross(5760, 'incl'));
        $this->assertSame([2490, 2490], compare_net_gross(2490, 'final'));
        $this->assertSame([1000, 1210], compare_net_gross(1000, 'excl'));
        $this->assertSame([null, null], compare_net_gross(2400, 'unknown'));
    }

    public function testComparePriceRowsIncludeOursSortedByPrice(): void
    {
        $rows = compare_price_rows();
        $ours = array_values(array_filter($rows, fn($r) => $r['ours']));
        $this->assertCount(2, $ours);
        $this->assertSame(edition_price('standard', COMPARE_UNITS), $ours[0]['net']);
        $keys = array_map(fn($r) => $r['gross'] ?? $r['price'], $rows);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    public function testCompareAiCellDependsOnConfiguration(): void
    {
        $f = compare_features();
        $this->assertSame(ai_configured() ? 'A' : 'N', compare_cell($f['AI pomocník a asistent'][0])[0]);
    }

    public function testComparePageSwitch(): void
    {
        $this->assertFalse(compare_enabled());
        $this->setPset('compare_on', '1');
        $this->assertTrue(compare_enabled());
        $html = $this->view('ext/pricing/compare');
        $this->assertStringContainsString('Sousedé.cz', $html);
        $this->assertStringContainsString('ověřeno 11/2026', $html);
        $this->assertStringContainsString('https://domee.app/cenik', $html);
        $this->assertStringContainsString('neověřeno', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    // ---------- Kampaň, partnerský program, ceník ----------

    public function testConsumptionPage(): void
    {
        $html = $this->view('ext/pricing/consumption');
        $this->assertStringContainsString('67/2013 Sb.', $html);
        $this->assertStringContainsString('406/2000 Sb.', $html);
        $this->assertStringContainsString('269/2015 Sb.', $html);
        $this->assertStringContainsString('mmr.gov.cz', $html, 'zdroj právního shrnutí');
        $this->assertStringContainsString('25 dnů', $html);
        $this->assertStringContainsString(money_czk(edition_price('standard')), $html);
    }

    public function testProgramPageShowsTiersAndLeaflet(): void
    {
        $this->setPset('partner_discounts', "5:10\n20:15");
        $html = $this->view('ext/pricing/program');
        $this->assertStringContainsString('class="leaflet"', $html);
        $this->assertStringContainsString('data-print', $html);
        $this->assertStringContainsString('až 15 %', $html);
        $this->assertStringContainsString(money_czk((int) round(edition_price('standard') * 0.9)), $html, 'cena po slevě 10 %');
    }

    public function testPricingNotesAndEditionVat(): void
    {
        $this->setPset('free_max_units', '12');
        $this->setPset('vat_payer', '1');
        $this->setPset('vat_rate', '21');
        $html = $this->view('ext/pricing/pricing_notes');
        $this->assertStringContainsString('do 50 jednotek paušál', $html);
        $this->assertStringContainsString('Edice Zdarma je pro domy do 12 jednotek', $html);
        $this->assertStringContainsString('Jednorázové hlasování', $html);
        $this->assertStringNotContainsString('/srovnani', $html, 'vypnuté srovnání se neodkazuje');

        $html = $this->view('platform/_editions', ['edView' => 'landing']);
        $this->assertStringNotContainsString('s DPH', $html, 'jen ceny bez DPH');
        $this->assertStringContainsString('Pro domy do 12 jednotek', $html);
    }

    public function testRoutesAndSeo(): void
    {
        $this->assertContains('srovnani', PLATFORM_PATHS);
        $this->assertContains('mesicni-informace-o-spotrebe', PLATFORM_PATHS);
        $this->assertArrayHasKey('/mesicni-informace-o-spotrebe', PLATFORM_SEO_PAGES);
        $this->assertArrayHasKey('/pro-spravce/program', PLATFORM_SEO_PAGES);
        $this->assertNotNull(slug_problem('srovnani'), 'adresa stránky služby nejde použít pro web domu');
    }

    // ---------- Aplikace pro Android ----------

    public function testFingerprintParsing(): void
    {
        $colon = implode(':', array_fill(0, 32, 'AB'));
        $this->assertSame([$colon], twa_fingerprints($colon));
        $this->assertSame([$colon], twa_fingerprints(strtolower(str_repeat('ab', 32))), 'bez dvojteček a malými písmeny');
        $other = implode(':', array_fill(0, 32, '0F'));
        $this->assertSame([$colon, $other], twa_fingerprints($colon . "\n" . $other . ', ' . $colon));
        $this->assertSame([], twa_fingerprints('AB:CD:EF'));
        $this->assertSame([], twa_fingerprints(''));
    }

    public function testAssetLinks(): void
    {
        $this->assertNull(twa_assetlinks(), 'bez nastavení adresa neexistuje');
        $this->setPset('twa_package', 'cz.webprosvj.app');
        $this->assertNull(twa_assetlinks(), 'chybí otisk');
        $this->setPset('twa_sha256', str_repeat('ab', 32));
        $links = twa_assetlinks();
        $this->assertSame(['delegate_permission/common.handle_all_urls'], $links[0]['relation']);
        $this->assertSame('android_app', $links[0]['target']['namespace']);
        $this->assertSame('cz.webprosvj.app', $links[0]['target']['package_name']);
        $this->assertSame([implode(':', array_fill(0, 32, 'AB'))], $links[0]['target']['sha256_cert_fingerprints']);
        $this->setPset('twa_package', 'spatne jmeno');
        $this->assertNull(twa_assetlinks());
        $this->assertTrue(twa_package_valid('com.example.app_1'));
        $this->assertFalse(twa_package_valid('1com.example'));
    }

    public function testPlatformManifestMeetsTwaBasics(): void
    {
        $m = platform_manifest();
        foreach (['id', 'name', 'short_name', 'start_url', 'display', 'icons', 'theme_color', 'background_color'] as $k) {
            $this->assertArrayHasKey($k, $m);
        }
        $this->assertSame('standalone', $m['display']);
        $this->assertSame(['192x192', '512x512'], array_column($m['icons'], 'sizes'));
        $this->assertFileExists(dirname(__DIR__, 2) . '/assets/icon-512.png');
    }

    public function testExtRoutesRegisterAssetLinks(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/ext/routes_pricing.php');
        $this->assertStringContainsString("'/\\.well-known/assetlinks\\.json'", $src);
        $this->assertMatchesRegularExpression('#^/\.well-known/assetlinks\.json$#', '/.well-known/assetlinks.json');
        $site = file_get_contents(dirname(__DIR__, 2) . '/app/ext/site_pricing.php');
        $this->assertStringContainsString("'/.well-known/assetlinks.json'", $site);
    }
}
