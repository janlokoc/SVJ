<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Společenství na platformě: adresy, zakládání, nastavení, nečinnost, ukázka a nastavení služby (app/platform_lib.php). */
final class PlatformTenantsTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $savedPsets = [];
    private array $savedServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        foreach ($this->savedPsets as $key => $old) {
            if ($old === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
                pset('__reset');
            } else {
                pset_save($key, $old);
            }
        }
        $this->savedPsets = [];
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    private function setPset(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->savedPsets)) {
            $v = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
            $this->savedPsets[$key] = $v === false ? null : (string) $v;
        }
        pset_save($key, $value);
    }

    private function newTenant(array $extra = []): array
    {
        $slug = 'tb-' . bin2hex(random_bytes(3));
        tenant_create($extra + ['slug' => $slug, 'name' => 'SVJ Test ' . $slug, 'title' => 'Test']);
        return tenant_row($slug);
    }

    // ---------- Adresy ----------

    public function testSlugProblemFormat(): void
    {
        foreach (['a', '', 'Ab', 'ab-', '-ab', 'a_b', 'žába', 'a b', 'ab.cd', str_repeat('a', 40)] as $bad) {
            $this->assertStringContainsString('2–39 znaků', (string) slug_problem($bad), "'$bad' má být neplatná");
        }
        $free = 'tb-' . bin2hex(random_bytes(3));
        $this->assertNull(slug_problem($free));
        $this->assertNull(slug_problem('a' . bin2hex(random_bytes(19))), '39 znaků je ještě v pořádku');
        $this->assertNull(slug_problem('9' . bin2hex(random_bytes(3))), 'číslice na začátku je v pořádku');
    }

    public function testSlugProblemReserved(): void
    {
        foreach (array_merge(RESERVED_SLUGS, PLATFORM_PATHS, ['demo', 'help', 'test', 'webmail', 'podpora']) as $slug) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $slug)) {
                continue;
            }
            $this->assertSame('Tato adresa je vyhrazená, zvolte prosím jinou.', slug_problem($slug), $slug);
        }
        $this->assertSame('Tato adresa je vyhrazená, zvolte prosím jinou.', slug_problem('cenik'));
        $this->assertSame('Tato adresa je vyhrazená, zvolte prosím jinou.', slug_problem('pro-spravce'));
    }

    public function testSlugProblemTaken(): void
    {
        $this->assertSame('Tato adresa už je obsazená, zvolte prosím jinou.', slug_problem('dum'));
        $t = $this->newTenant();
        $this->assertSame('Tato adresa už je obsazená, zvolte prosím jinou.', slug_problem($t['slug']));
    }

    public function testPlatformDomainAndUrl(): void
    {
        $this->assertSame('test.invalid', platform_main_domain());
        $this->assertSame('test.invalid', platform_domain());
        $this->assertTrue(platform_enabled());
        $this->assertFalse(platform_paths());
        $this->assertSame('http://test.invalid/', platform_url());
        $this->assertSame('http://test.invalid/cenik', platform_url('/cenik'));
        $_SERVER['HTTPS'] = 'on';
        $this->assertSame('https://test.invalid/x', platform_url('/x'));
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_HOST'] = 'dum.test.invalid:8443';
        $this->assertSame('https://test.invalid:8443/x', platform_url('/x'));
    }

    public function testTenantAddressAndUrl(): void
    {
        $this->assertSame('tabor.test.invalid', tenant_address('tabor'));
        $t = $this->newTenant();
        $this->assertSame('http://' . $t['slug'] . '.test.invalid/', tenant_url($t));
        $this->assertSame('http://' . $t['slug'] . '.test.invalid/admin', tenant_url($t, '/admin'));
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'dum.test.invalid:8080';
        $this->assertSame('https://' . $t['slug'] . '.test.invalid:8080/a', tenant_url($t, '/a'));
        $t['custom_domain'] = 'svj-zahradni.cz';
        $this->assertSame('https://svj-zahradni.cz:8080/a', tenant_url($t, '/a'));
    }

    public function testTenantByHost(): void
    {
        $this->assertSame('dum', tenant_by_host('dum.test.invalid')['slug'] ?? null);
        $this->assertNull(tenant_by_host('neexistuje-' . bin2hex(random_bytes(3)) . '.test.invalid'));
        $this->assertNull(tenant_by_host('a.b.test.invalid'), 'víceúrovňová subdoména není adresa společenství');
        $this->assertNull(tenant_by_host('x.test.invalid'), 'jednopísmenná adresa neexistuje');
        $domain = 'svj-' . bin2hex(random_bytes(3)) . '.cz';
        $t = $this->newTenant(['custom_domain' => $domain]);
        $this->assertSame($t['slug'], tenant_by_host($domain)['slug'] ?? null);
        $this->assertSame($t['slug'], tenant_by_host('www.' . $domain)['slug'] ?? null);
        $this->assertSame($t['slug'], tenant_by_host($t['slug'] . '.test.invalid')['slug'] ?? null);
        $this->assertNull(tenant_by_host('jina-' . $domain));
        $this->assertNull(tenant_by_host(''), 'prázdná vlastní doména nesmí odpovídat');
    }

    // ---------- Zakládání a nastavení ----------

    public function testTenantCreateDefaults(): void
    {
        $t = $this->newTenant(['owner_email' => 'vybor@example.cz']);
        $this->assertSame('SVJ Test ' . $t['slug'], $t['name']);
        $this->assertSame($t['name'], $t['full'], 'plný název se doplní z názvu');
        $this->assertSame('Společenství vlastníků jednotek', $t['tagline']);
        $this->assertSame('A', $t['entrances']);
        $this->assertSame('les', $t['look']);
        $this->assertSame('klasik', $t['style']);
        $this->assertSame('cs', $t['lang']);
        $this->assertSame(1, (int) $t['active']);
        $this->assertSame(0, (int) $t['billing']);
        $this->assertSame(0, (int) $t['demo']);
        $this->assertSame('', $t['paid_until']);
        $this->assertSame('vybor@example.cz', $t['owner_email']);
        $this->assertSame(date('Y-m-d'), substr($t['created_at'], 0, 10));
        $this->assertDirectoryExists(tenant_storage($t['slug']), 'databáze společenství se založí hned');
        $this->assertNull(tenant_row('neexistuje-' . bin2hex(random_bytes(3))));
    }

    public function testTenantCreateDuplicateFails(): void
    {
        $t = $this->newTenant();
        $this->expectException(\PDOException::class);
        tenant_create(['slug' => $t['slug'], 'name' => 'Duplicita', 'title' => 'D']);
    }

    public function testTenantSetAndSettings(): void
    {
        $t = $this->newTenant();
        $before = tenant_settings($t['slug']);
        $this->assertIsArray($before);
        tenant_set($t['slug'], 'ico', '12345678');
        $this->assertSame('12345678', tenant_settings($t['slug'])['ico']);
        tenant_set($t['slug'], 'ico', '87654321');
        $s = tenant_settings($t['slug']);
        $this->assertSame('87654321', $s['ico'], 'opakované nastavení přepíše hodnotu');
        $this->assertSame(1, (int) tenant_pdo($t['slug'])->query("SELECT COUNT(*) FROM settings WHERE `key` = 'ico'")->fetchColumn());
        tenant_set($t['slug'], 'tb_novy_klic', 'x');
        $this->assertSame('x', tenant_settings($t['slug'])['tb_novy_klic']);
        // Nastavení jednoho společenství neovlivní jiné.
        $this->assertArrayNotHasKey('tb_novy_klic', tenant_settings('dum'));
    }

    public function testTenantUsageKeys(): void
    {
        $t = $this->newTenant();
        $u = tenant_usage($t['slug']);
        $this->assertSame(array_keys(USAGE_FEATURES), array_keys($u));
        foreach ($u as $key => $v) {
            $this->assertIsBool($v, $key);
        }
        $this->assertFalse($u['jednotky']);
        $this->assertFalse($u['zavady']);
        $dum = tenant_usage('dum');
        $this->assertSame(array_keys(USAGE_FEATURES), array_keys($dum));
        $this->assertTrue($dum['jednotky'] || (int) val("SELECT COUNT(*) FROM units WHERE share <> ''") === 0);
    }

    // ---------- Nečinnost a mazání webů Zdarma ----------

    public function testTenantLastActive(): void
    {
        $t = $this->newTenant();
        $this->assertSame(date('Y-m-d'), tenant_last_active($t), 'bez přihlášení platí den založení');
        pq('UPDATE tenants SET created_at = ? WHERE slug = ?', ['2020-01-15 08:00:00', $t['slug']]);
        $t = tenant_row($t['slug']);
        $this->assertSame('2020-01-15', tenant_last_active($t));
        tenant_pdo($t['slug'])->prepare('INSERT INTO users (email, name, password_hash, role, last_login_at) VALUES (?,?,?,?,?)')
            ->execute(['a@example.cz', 'A', 'x', 'admin', '2024-05-06 10:11:12']);
        $this->assertSame('2024-05-06', tenant_last_active($t));
        $this->assertSame('2024-05-06 10:11:12', tenant_stats($t['slug'])['last_login']);
        // Přihlášení starší než založení webu nerozhoduje.
        pq('UPDATE tenants SET created_at = ? WHERE slug = ?', ['2025-02-02 08:00:00', $t['slug']]);
        $this->assertSame('2025-02-02', tenant_last_active(tenant_row($t['slug'])));
    }

    public function testTenantDeleteDate(): void
    {
        $this->setPset('delete_days', '365');
        $t = $this->newTenant(['billing' => 1]);
        pq('UPDATE tenants SET created_at = ? WHERE slug = ?', ['2025-03-01 12:00:00', $t['slug']]);
        $t = tenant_row($t['slug']);
        $this->assertSame('free', tenant_edition($t));
        $this->assertSame('2026-03-01', tenant_delete_date($t));

        $this->setPset('delete_days', '30');
        $this->assertSame('2025-03-31', tenant_delete_date($t));
        $this->setPset('delete_days', '0');
        $this->assertNull(tenant_delete_date($t), '0 = nemazat');
        $this->setPset('delete_days', '365');

        $this->assertNull(tenant_delete_date(['billing' => 0] + $t), 'web bez placení se nemaže');
        $this->assertNull(tenant_delete_date(['demo' => 1] + $t), 'ukázka se nemaže');
        $this->assertNull(tenant_delete_date(['plan' => 'standard', 'paid_until' => date('Y-m-d', strtotime('+10 day'))] + $t), 'placená edice se nemaže');
        $this->assertNull(tenant_delete_date(['trial_ends' => date('Y-m-d', strtotime('+3 day'))] + $t), 'zkušební doba (Standard) se nemaže');

        pq("INSERT INTO payments (tenant, amount, months, status, created_at) VALUES (?, 100, 12, 'claimed', ?)", [$t['slug'], date('Y-m-d H:i:s')]);
        $this->assertNull(tenant_delete_date($t), 'nahlášená platba mazání zastaví');
    }

    // ---------- Nastavení služby ----------

    public function testPsetDefaultsAndSave(): void
    {
        foreach (['invoice_due_days', 'service_name', 'grace_days', 'delete_days'] as $k) {
            $stored = pq('SELECT value FROM platform_settings WHERE key = ?', [$k])->fetchColumn();
            $this->assertSame($stored === false ? PLATFORM_DEFAULTS[$k] : (string) $stored, pset($k), $k);
        }
        $this->assertSame('Testovací služba', pset('service_name'), 'nastaveno v tests/setup.php');
        $this->assertSame('', pset('tb_neexistujici_klic'));
        $key = 'tb_klic_' . bin2hex(random_bytes(3));
        $this->savedPsets[$key] = null;
        pset_save($key, 'jedna');
        $this->assertSame('jedna', pset($key));
        pset_save($key, 'dva');
        $this->assertSame('dva', pset($key), 'uložení obnoví cache');
        $this->assertSame(1, (int) pq('SELECT COUNT(*) FROM platform_settings WHERE key = ?', [$key])->fetchColumn());
    }

    public function testPsetIntClamping(): void
    {
        $this->setPset('grace_days', '999');
        $this->assertSame(365, pset_int('grace_days', 0, 365));
        $this->setPset('grace_days', '3');
        $this->assertSame(5, pset_int('grace_days', 5, 365));
        $this->assertSame(3, pset_int('grace_days', 0, 365));
        $this->setPset('grace_days', 'abc');
        $this->assertSame(14, pset_int('grace_days', 0, 365), 'nečíselná hodnota = výchozí');
        $this->setPset('grace_days', '-5');
        $this->assertSame(14, pset_int('grace_days', 0, 365), 'záporné číslo není ctype_digit = výchozí');
        $this->setPset('grace_days', '');
        $this->assertSame(14, pset_int('grace_days', 0, 365));
        $this->setPset('grace_days', '0');
        $this->assertSame(0, pset_int('grace_days', 0, 365));
    }

    // ---------- Ukázkový web ----------

    public function testDemoCreateAndRefresh(): void
    {
        // Výchozí stav: ukázka neexistuje, nebo ji smažeme obnovením.
        $partnerDemo = 'tb-' . bin2hex(random_bytes(3));
        tenant_create(['slug' => $partnerDemo, 'name' => 'Dům ukázky partnera', 'title' => 'P', 'demo' => 2]);
        $this->assertNotNull(tenant_row($partnerDemo));
        $this->assertDirectoryExists(tenant_storage($partnerDemo));

        demo_refresh();
        $demo = demo_tenant();
        $this->assertNotNull($demo);
        $this->assertSame('demo', $demo['slug']);
        $this->assertSame(1, (int) $demo['demo']);
        $this->assertSame('modern', $demo['style']);
        $this->assertSame((string) DEMO_CONTENT_VERSION, pset('demo_content'));
        $this->assertNull(tenant_row($partnerDemo), 'obnova ukázky smaže i domy ukázky partnera (demo = 2)');
        $this->assertDirectoryDoesNotExist(tenant_storage($partnerDemo));

        // Opakovaná obnova nechá právě jednu ukázku.
        demo_refresh();
        $this->assertSame(1, (int) pq('SELECT COUNT(*) FROM tenants WHERE demo = 1')->fetchColumn());
        $this->assertSame('free', billing_state(demo_tenant())['mode']);

        // Neaktivní ukázka se jako ukázka nevrací.
        pq("UPDATE tenants SET active = 0 WHERE slug = 'demo'");
        $this->assertNull(demo_tenant());
        pq("UPDATE tenants SET active = 1 WHERE slug = 'demo'");
        $this->assertSame('demo', demo_tenant()['slug']);
    }
}
