<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Vyhledávače: robots.txt, sitemap.xml a hlavička stránek hlavního webu služby. */
final class SeoTest extends TestCase
{
    private string $oldVerification = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldVerification = pset('google_verification');
    }
    protected function tearDown(): void
    {
        pset_save('google_verification', $this->oldVerification);
    }

    public function testRobotsAllowsPublicPagesAndHidesPrivateOnes(): void
    {
        $r = platform_robots_txt();
        $this->assertStringStartsWith("User-agent: *\n", $r);
        foreach (['/sprava', '/partner', '/registrace/', '/hlaseni'] as $private) {
            $this->assertStringContainsString("Disallow: $private\n", $r);
        }
        $this->assertStringNotContainsString("Disallow: /\n", $r, 'produkce nesmí zakázat celý web');
        $this->assertStringContainsString('Sitemap: ' . platform_url('/sitemap.xml'), $r);
    }

    public function testSitemapListsPublicPagesAsValidXml(): void
    {
        $xml = simplexml_load_string(platform_sitemap_xml());
        $this->assertNotFalse($xml);
        $locs = [];
        foreach ($xml->url as $u) {
            $locs[] = (string) $u->loc;
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $u->lastmod);
        }
        foreach (['/', '/funkce', '/cenik', '/pro-spravce'] as $p) {
            $this->assertContains(platform_url($p), $locs);
        }
        $this->assertNotContains(platform_url('/sprava'), $locs);
        $this->assertCount(count(PLATFORM_SEO_PAGES), $locs);
    }

    public function testHeadForLandingHasCanonicalOgAndStructuredData(): void
    {
        $h = platform_seo_head('/', 'Web pro SVJ zdarma');
        $this->assertStringContainsString('<link rel="canonical" href="' . platform_url('/') . '">', $h);
        $this->assertStringContainsString('<meta property="og:title" content="Web pro SVJ zdarma">', $h);
        $this->assertStringContainsString('name="description" content="Web pro SVJ zdarma', $h);
        $this->assertStringNotContainsString('noindex', $h);
        $this->assertMatchesRegularExpression('~<script type="application/ld\+json">(.+)</script>~', $h);
        preg_match('~<script type="application/ld\+json">(.+)</script>~', $h, $m);
        $data = json_decode($m[1], true);
        $this->assertSame('SoftwareApplication', $data['@type']);
        $this->assertSame('0', $data['offers']['price']);
    }

    public function testHeadForOtherPagesUsesOwnDescriptionAndNoStructuredData(): void
    {
        $h = platform_seo_head('/cenik', 'Ceník');
        $this->assertStringContainsString(e(PLATFORM_SEO_PAGES['/cenik'][1]), $h);
        $this->assertStringContainsString('href="' . platform_url('/cenik') . '"', $h);
        $this->assertStringNotContainsString('ld+json', $h);
        // Stránka mimo seznam (např. registrace): výchozí popis, žádná kanonická adresa.
        $h = platform_seo_head('/registrace', 'Registrace');
        $this->assertStringNotContainsString('canonical', $h);
        $this->assertStringContainsString('name="description"', $h);
    }

    public function testGoogleVerificationOnlyForValidCode(): void
    {
        pset_save('google_verification', 'abcDEF123_-xyzXYZ7890abc');
        $this->assertStringContainsString('<meta name="google-site-verification" content="abcDEF123_-xyzXYZ7890abc">', platform_seo_head('/', 'x'));
        pset_save('google_verification', '"><script>alert(1)</script>');
        $this->assertStringNotContainsString('google-site-verification', platform_seo_head('/', 'x'));
        pset_save('google_verification', '');
        $this->assertStringNotContainsString('google-site-verification', platform_seo_head('/', 'x'));
    }
}
