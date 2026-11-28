<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Obchod v konzoli: cesta od kontaktu k platbě (jen cizí domy do cíle), zakládající domy (zamčená cena Standardu,
 * převod zdarma, omezený počet míst) a sekce „Kdo za tím stojí“ s doporučeními jen se souhlasem.
 */
final class SalesTest extends TestCase
{
    private array $slugs = [];
    private array $old = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['founding_on', 'founding_slots', 'price_standard', 'price_migration', 'about_story', 'about_name', 'sales_goal', 'sales_deadline'] as $k) {
            $this->old[$k] = pset($k);
        }
        pq('DELETE FROM leads');
        pq('DELETE FROM testimonials');
    }

    protected function tearDown(): void
    {
        foreach ($this->slugs as $s) {
            pq('DELETE FROM tenants WHERE slug = ?', [$s]);
            pq('DELETE FROM migrations WHERE tenant = ?', [$s]);
            pq('DELETE FROM payments WHERE tenant = ?', [$s]);
        }
        foreach ($this->old as $k => $v) {
            pset_save($k, $v);
        }
        pq('DELETE FROM leads');
        pq('DELETE FROM testimonials');
        about_photo_delete();
        parent::tearDown();
    }

    private function tenant(): array
    {
        $slug = 'ob' . bin2hex(random_bytes(4));
        $this->slugs[] = $slug;
        tenant_create(['slug' => $slug, 'name' => 'SVJ ' . $slug, 'title' => 'Test', 'billing' => 1, 'owner_email' => $slug . '@example.cz']);
        return tenant_row($slug);
    }

    public function testFunnelCountsOnlyStrangersTowardGoal(): void
    {
        $houses = [
            ['known' => 0, 'launched' => true, 'paid' => true],
            ['known' => 0, 'launched' => true, 'paid' => false],
            ['known' => 0, 'launched' => false, 'paid' => false],
            ['known' => 1, 'launched' => true, 'paid' => true],
        ];
        $leads = [['stage' => 'kontakt', 'demo_done' => 0], ['stage' => 'ukazka', 'demo_done' => 1], ['stage' => 'nezajem', 'demo_done' => 1], ['stage' => 'nezajem', 'demo_done' => 0]];
        pset_save('sales_goal', '5');
        pset_save('sales_deadline', '2027-05-31');
        $f = sales_funnel($houses, $leads);
        $this->assertSame(['kontakt' => 4, 'ukazka' => 2, 'registrace' => 3, 'oznameno' => 2, 'zaplaceno' => 1], $f['cizi']);
        $this->assertSame(1, $f['znami']['zaplaceno'], 'známý dům se do cíle nepočítá');
        $this->assertSame(5, $f['goal']);
        $this->assertSame('2027-05-31', $f['deadline']);
    }

    public function testPaidMeansRealServicePayment(): void
    {
        $t = $this->tenant();
        $this->assertFalse(sales_tenant_paid($t['slug']));
        pq("INSERT INTO payments (tenant, amount, months, created_at, kind, status) VALUES (?, 0, 12, ?, 'service', 'paid')", [$t['slug'], date('Y-m-d H:i:s')]);
        pq("INSERT INTO payments (tenant, amount, months, created_at, kind, status) VALUES (?, 1500, 0, ?, 'migration', 'paid')", [$t['slug'], date('Y-m-d H:i:s')]);
        pq("INSERT INTO payments (tenant, amount, months, created_at, kind, status) VALUES (?, 1990, 12, ?, 'service', 'pending')", [$t['slug'], date('Y-m-d H:i:s')]);
        $this->assertFalse(sales_tenant_paid($t['slug']), 'dárek za 0 Kč, převod ani nezaplacená výzva nejsou platba za edici');
        pq("INSERT INTO payments (tenant, amount, months, created_at, kind, status) VALUES (?, 1990, 12, ?, 'service', 'paid')", [$t['slug'], date('Y-m-d H:i:s')]);
        $this->assertTrue(sales_tenant_paid($t['slug']));
        $row = array_values(array_filter(sales_houses(), fn($h) => $h['slug'] === $t['slug']))[0];
        $this->assertTrue($row['paid']);
        $this->assertSame(0, $row['known']);
    }

    public function testFoundingLocksStandardPriceAndFreesMigration(): void
    {
        pset_save('price_standard', '1990');
        pset_save('price_migration', '1500');
        pset_save('founding_slots', '10');
        $t = $this->tenant();
        $this->assertTrue(founding_set($t['slug'], true));
        $t = tenant_row($t['slug']);
        $this->assertSame(date('Y-m-d'), $t['founding']);
        pset_save('price_standard', '2490');
        $this->assertSame(1990, edition_price('standard', $t), 'cena zamčená ze dne označení');
        $this->assertSame(2490, edition_price('standard', $this->tenant()), 'ostatní platí nový ceník');
        $this->assertSame(2490, edition_price('standard'), 'veřejný ceník se nemění');
        $this->assertSame(0, migration_price($t));
        $m = migration_order($t, ['url' => 'https://stary.example.cz', 'scope' => 'dokumenty', 'access' => 'public', 'note' => '', 'contact_name' => 'Výbor', 'contact_email' => 'vybor@example.cz', 'contact_phone' => '']);
        $this->assertSame('paid', $m['status'], 'převod zdarma bez čekání na platbu');
        $p = pq('SELECT * FROM payments WHERE id = ?', [$m['payment_id']])->fetch();
        $this->assertSame(0, (int) $p['amount']);
        $this->assertSame('paid', $p['status']);
        $this->assertFalse(sales_tenant_paid($t['slug']), 'převod zdarma není platba za edici');
        $this->assertTrue(founding_set($t['slug'], false));
        $t = tenant_row($t['slug']);
        $this->assertSame(2490, edition_price('standard', $t));
        $this->assertSame(1500, migration_price($t));
    }

    public function testFoundingSlotsAreLimited(): void
    {
        pset_save('founding_on', '1');
        $used = founding_used();
        pset_save('founding_slots', (string) ($used + 1));
        $this->assertTrue(founding_open());
        $this->assertSame(1, founding_left());
        $this->assertTrue(founding_set($this->tenant()['slug'], true));
        $this->assertFalse(founding_open(), 'místa obsazená – nabídka zmizí');
        $this->assertFalse(founding_set($this->tenant()['slug'], true), 'další místo není');
        pset_save('founding_on', '0');
        pset_save('founding_slots', '100');
        $this->assertFalse(founding_open(), 'vypnutá nabídka');
    }

    public function testLeadValidation(): void
    {
        $this->assertArrayHasKey('house', sales_lead_save(['house' => ' ']));
        $this->assertArrayHasKey('tenant', sales_lead_save(['house' => 'Lipová 12', 'tenant' => 'neexistuje-xyz']));
        $this->assertSame([], sales_lead_save(['house' => 'Lipová 12', 'source' => 'nesmysl', 'stage' => 'registrace']));
        $l = pq('SELECT * FROM leads')->fetch();
        $this->assertSame('jine', $l['source']);
        $this->assertSame(1, (int) $l['demo_done'], 'kdo založil web, ten prošel ukázkou');
        $this->assertSame([], sales_lead_save(['house' => 'Lipová 12', 'stage' => 'nezajem'], (int) $l['id']));
        $this->assertSame('nezajem', pq('SELECT stage FROM leads')->fetchColumn());
    }

    public function testTestimonialPublishedOnlyWithConsent(): void
    {
        $this->assertArrayHasKey('consent', testimonial_save(['text' => 'Super.', 'who' => 'Petr', 'visible' => 1]));
        $this->assertSame([], testimonial_save(['text' => 'Bez souhlasu.', 'who' => 'Jana']));
        $this->assertSame([], testimonial_save(['text' => 'Per rollam za 5 dní.', 'who' => 'Petr, předseda', 'house' => 'SVJ Lipová', 'consent' => '2026-11-20', 'visible' => 1]));
        $pub = testimonials_public();
        $this->assertCount(1, $pub);
        $this->assertSame('Per rollam za 5 dní.', $pub[0]['text']);
    }

    public function testAboutSectionOnLanding(): void
    {
        pset_save('about_story', '');
        $this->assertFalse(about_has_content());
        $html = $this->view();
        $this->assertStringNotContainsString('Kdo za tím stojí', $html, 'prázdná sekce se nezobrazí');
        pset_save('about_story', "Jsem ve výboru našeho domu.\n\nWeb jsem postavil pro nás <a>.");
        pset_save('about_name', 'Jan');
        testimonial_save(['text' => 'Pomohl nám.', 'who' => 'Eva', 'consent' => '2026-11-20', 'visible' => 1]);
        $html = $this->view();
        $this->assertStringContainsString('id="kdo-za-tim-stoji"', $html);
        $this->assertStringContainsString('<p>Jsem ve výboru našeho domu.</p>', $html);
        $this->assertStringContainsString('pro nás &lt;a&gt;.', $html, 'text je escapovaný');
        $this->assertStringContainsString('„Pomohl nám.“', $html);
        $this->assertStringNotContainsString('about-photo', $html, 'bez fotky žádný obrázek');
    }

    public function testFoundingPromoOnLanding(): void
    {
        pset_save('founding_on', '1');
        pset_save('founding_slots', (string) (founding_used() + 3));
        pset_save('price_standard', '1990');
        $html = $this->view();
        $this->assertStringContainsString('Zakládající domy: zbývá 3 místa z', $html);
        $this->assertStringContainsString('1 990 Kč ročně', $html);
        pset_save('founding_on', '0');
        $this->assertStringNotContainsString('Zakládající domy', $this->view());
    }

    public function testPhotoUploadAcceptsImagesOnly(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($tmp, '<?php echo 1;');
        $this->assertStringContainsString('JPEG, PNG nebo WebP', about_photo_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 20], true));
        $img = imagecreatetruecolor(4, 4);
        imagepng($img, $tmp);
        $this->assertSame('', about_photo_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)], true));
        $this->assertStringEndsWith('/about/photo.png', about_photo_file());
        $this->assertFileExists(about_photo_dir() . '/.htaccess');
        $this->assertSame('Fotku se nepodařilo nahrát.', about_photo_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 20]), 'z formuláře jen skutečně nahraný soubor');
        @unlink($tmp);
    }

    public function testWiring(): void
    {
        $root = dirname(__DIR__, 2);
        $c = (string) file_get_contents($root . '/app/platform_console.php');
        $this->assertStringContainsString("if (\$PATH === '/obchod') {", $c);
        $this->assertStringContainsString("['/obchod', 'Obchod'", (string) file_get_contents($root . '/app/views/platform/layout.php'));
        $this->assertContains('kdo-za-tim-stoji', PLATFORM_PATHS);
        $this->assertStringContainsString("\$PATH === '/kdo-za-tim-stoji/foto'", (string) file_get_contents($root . '/app/platform_site.php'));
        $this->assertStringContainsString("require __DIR__ . '/_founding.php'", (string) file_get_contents($root . '/app/views/platform/pricing.php'));
    }

    private function view(): string
    {
        $open = true;
        $demo = null;
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/platform/landing.php';
        return str_replace("\u{a0}", ' ', (string) ob_get_clean());
    }
}
