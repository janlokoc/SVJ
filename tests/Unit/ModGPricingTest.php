<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul G: cena podle velikosti domu, cena s DPH, kontrola nastavení (app/ext/pricing_lib.php, edition_price, billing_order, partner_order). */
final class ModGPricingTest extends TestCase
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

    /** Nové společenství s daným počtem jednotek. */
    private function tenantWithUnits(int $units, array $extra = []): array
    {
        $slug = 'mg-' . bin2hex(random_bytes(3));
        tenant_create($extra + ['slug' => $slug, 'name' => 'SVJ ' . $slug, 'title' => 'Test', 'billing' => 1, 'owner_email' => $slug . '@example.cz']);
        $pdo = tenant_pdo($slug);
        $pdo->exec('DELETE FROM units');
        $st = $pdo->prepare('INSERT INTO units (entrance, number) VALUES (?, ?)');
        for ($i = 1; $i <= $units; $i++) {
            $st->execute(['A', (string) $i]);
        }
        $t = tenant_row($slug);
        tenant_unit_count($t, true);
        return $t;
    }

    public function testDefaults(): void
    {
        $this->assertSame('50', PLATFORM_DEFAULTS['price_flat_units']);
        $this->assertSame('20', PLATFORM_DEFAULTS['price_unit_standard']);
        $this->assertSame('40', PLATFORM_DEFAULTS['price_unit_premium']);
        $this->assertSame('0', PLATFORM_DEFAULTS['free_max_units'], 'edice Zdarma bez omezení velikosti je výchozí (dosavadní chování)');
        $this->assertSame('990', PLATFORM_DEFAULTS['price_vote']);
        $this->assertSame('0', PLATFORM_DEFAULTS['compare_on'], 'srovnání je vypnuté, dokud ho provozovatel nezkontroluje');
    }

    public function testPriceIsFlatUpToLimitThenPerUnit(): void
    {
        $std = pset_int('price_standard', 0, 1000000);
        $prem = pset_int('price_premium', 0, 1000000);
        $this->assertSame($std, edition_price('standard'), 'bez velikosti jen základ');
        $this->assertSame($std, edition_price('standard', 24));
        $this->assertSame($std, edition_price('standard', 50), 'paušál platí včetně hranice');
        $this->assertSame($std + 20, edition_price('standard', 51));
        $this->assertSame($std + 30 * 20, edition_price('standard', 80));
        $this->assertSame($prem + 30 * 40, edition_price('premium', 80));
        $this->assertSame(0, edition_price('free', 500), 'edice Zdarma nic nestojí ani u velkého domu');
        $this->assertSame(0, edition_surcharge('free', 500));
        $this->assertSame(0, edition_surcharge('standard', 0));
    }

    public function testPriceFollowsSettings(): void
    {
        $this->setPset('price_flat_units', '30');
        $this->setPset('price_unit_standard', '15');
        $this->setPset('price_unit_premium', '0');
        $std = pset_int('price_standard', 0, 1000000);
        $this->assertSame($std + 10 * 15, edition_price('standard', 40));
        $this->assertSame(pset_int('price_premium', 0, 1000000), edition_price('premium', 400), 'nulová přirážka = paušál bez ohledu na velikost');
        $this->assertSame('', price_size_note('premium'));
        $this->assertStringContainsString('do 30 jednotek paušál', price_size_note('standard'));
        $this->assertStringContainsString("15\u{a0}Kč", price_size_note('standard'));
    }

    public function testPriceFromTenantUnits(): void
    {
        $small = $this->tenantWithUnits(12);
        $big = $this->tenantWithUnits(62);
        $this->assertSame(12, tenant_unit_count($small));
        $this->assertSame(62, tenant_unit_count($big));
        $this->assertSame(edition_price('standard'), edition_price('standard', $small));
        $this->assertSame(edition_price('standard') + 12 * 20, edition_price('standard', $big));
        $this->assertSame(0, tenant_unit_count(['slug' => '']));
        $this->assertSame(0, tenant_unit_count(['slug' => 'neexistuje-' . bin2hex(random_bytes(2))]));
    }

    public function testBillingOrderUsesSizePrice(): void
    {
        $t = $this->tenantWithUnits(60);
        $p = billing_order($t, 'premium');
        $this->assertSame(edition_price('premium') + 10 * 40, (int) $p['amount']);
        $this->assertSame('service', $p['kind']);
    }

    public function testPaidPeriodIsNotRepricedAfterSettingsChange(): void
    {
        $t = $this->tenantWithUnits(70);
        $p = billing_order($t, 'standard');
        $amount = (int) $p['amount'];
        billing_confirm($p, 'test');
        $this->setPset('price_unit_standard', '100');
        $paid = pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch();
        $this->assertSame('paid', $paid['status']);
        $this->assertSame($amount, (int) $paid['amount'], 'zaplacená platba se nepřepočítává');
        $this->assertSame(edition_price('standard') + 20 * 100, edition_price('standard', tenant_row($t['slug'])), 'nová cena platí až pro další výzvu');
    }

    public function testUpgradeCreditUsesPaidAmount(): void
    {
        $t = $this->tenantWithUnits(70);
        $p = billing_order($t, 'standard');
        pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ? WHERE id = ?", [date('Y-m-d H:i:s'), date('Y-m-d', strtotime('-182 day')), date('Y-m-d', strtotime('+182 day')), $p['id']]);
        pq("UPDATE tenants SET paid_until = ?, plan = 'standard' WHERE slug = ?", [date('Y-m-d', strtotime('+182 day')), $t['slug']]);
        $t = tenant_row($t['slug']);
        $credit = billing_credit($t, 'premium');
        $this->assertGreaterThan(0, $credit);
        $this->assertLessThanOrEqual((int) $p['amount'], $credit);
        $up = billing_order($t, 'premium');
        $this->assertSame(edition_price('premium', $t) - $credit, (int) $up['amount'], 'přechod: cena podle velikosti mínus nevyčerpaná část');
    }

    public function testPartnerOrderUsesSizePrice(): void
    {
        pq("INSERT INTO partners (name, email, created_at) VALUES ('Správa MG', 'mg@example.cz', ?)", [date('Y-m-d H:i:s')]);
        $partner = partner_row((int) platform_db()->lastInsertId());
        $big = $this->tenantWithUnits(55);
        $small = $this->tenantWithUnits(10);
        foreach ([$big, $small] as $h) {
            pq("UPDATE tenants SET partner_id = ?, partner_status = 'active', partner_billing = 1 WHERE slug = ?", [$partner['id'], $h['slug']]);
        }
        $p = partner_order($partner, [$big['slug'] => 'standard', $small['slug'] => 'standard']);
        $this->assertIsArray($p);
        $lines = array_column(partner_lines($p), 'price', 'slug');
        $this->assertSame(edition_price('standard') + 5 * 20, $lines[$big['slug']]);
        $this->assertSame(edition_price('standard'), $lines[$small['slug']]);
        $this->assertSame(array_sum($lines) - (int) $p['discount'], (int) $p['amount']);
    }

    public function testVatHtml(): void
    {
        $this->setPset('vat_payer', '0');
        $this->assertStringContainsString('nejsme plátci DPH', price_vat_html(1990));
        $this->assertSame(1990.0, price_gross(1990));
        $this->assertSame('', price_vat_html(0));
        $this->setPset('vat_payer', '1');
        $this->setPset('vat_rate', '21');
        $this->assertSame('', price_vat_html(1990), 'public prices are shown without VAT only');
        $this->assertSame(2407.9, price_gross(1990));
    }

    public function testSettingsValidation(): void
    {
        $ok = ['price_flat_units' => '50', 'price_unit_standard' => '20', 'price_unit_premium' => '40', 'free_max_units' => '0', 'price_vote' => '990', 'twa_package' => '', 'twa_sha256' => ''];
        $this->assertSame([], pricing_settings_errors($ok));
        $this->assertCount(1, pricing_settings_errors(['price_unit_standard' => 'dvacet'] + $ok));
        $this->assertCount(1, pricing_settings_errors(['free_max_units' => '-5'] + $ok));
        $this->assertCount(1, pricing_settings_errors(['price_vote' => '200000'] + $ok));
        $this->assertCount(1, pricing_settings_errors(['twa_package' => 'bez-tecky'] + $ok));
        $this->assertCount(1, pricing_settings_errors(['twa_sha256' => 'AB:CD'] + $ok));
        $this->assertSame([], pricing_settings_errors(['twa_package' => 'cz.webprosvj.app', 'twa_sha256' => str_repeat('ab', 32)] + $ok));
    }

    public function testConsoleSettingsFormHasAllModuleFields(): void
    {
        // Formulář Nastavení služby ukládá všechny klíče PLATFORM_DEFAULTS – chybějící pole by hodnotu vymazalo.
        $html = file_get_contents(dirname(__DIR__, 2) . '/app/views/ext/pricing/settings.php');
        foreach (['price_flat_units', 'price_unit_standard', 'price_unit_premium', 'free_max_units', 'price_vote', 'compare_on', 'twa_package', 'twa_sha256'] as $k) {
            $this->assertStringContainsString('name="' . $k . '"', $html, $k);
            $this->assertArrayHasKey($k, PLATFORM_DEFAULTS);
        }
        $this->assertStringContainsString('ext/pricing/settings.php', file_get_contents(dirname(__DIR__, 2) . '/app/views/platform/settings.php'));
    }
}
