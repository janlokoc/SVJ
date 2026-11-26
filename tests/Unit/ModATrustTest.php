<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;
use ZipArchive;

/** Modul A – plakát do vchodu, export dat, zpracovatelská smlouva, stránka Bezpečnost a vzhled obrazovek. */
final class ModATrustTest extends TestCase
{
    /** Vykreslí šablonu bez rozvržení stránky. */
    private function view(string $viewName, array $viewVars): string
    {
        $viewFile = dirname(__DIR__, 2) . '/app/views/ext/moda/' . $viewName . '.php';
        extract($viewVars, EXTR_SKIP);
        ob_start();
        require $viewFile;
        return (string) ob_get_clean();
    }

    // ---------- Plakát ----------

    public function testPosterWithoutJoinLinkPointsToWeb(): void
    {
        moda_setting('join_on', '0');
        $p = moda_poster(true);
        $this->assertFalse($p['join'], 'bez zapnutého odkazu pro přiřazení se použije adresa webu');
        $this->assertSame(absolute_url('/'), $p['target']);
        $this->assertSame('Společenství vlastníků jednotek Zahradní 12', $p['name']);
        $this->assertStringStartsWith('<svg', ltrim($p['qr']));
        $this->assertStringNotContainsString('http', $p['web'], 'adresa na plakátu bez https://');
        $this->assertStringContainsString('výbor', implode(' ', $p['steps']));
    }

    public function testPosterUsesJoinLinkWhenEnabledAndChosen(): void
    {
        moda_setting('join_token', 'abcdef0123456789abcdef01');
        moda_setting('join_on', '1');
        try {
            $p = moda_poster(true);
            $this->assertTrue($p['join']);
            $this->assertSame(absolute_url('/pripojit/abcdef0123456789abcdef01'), $p['target']);
            $this->assertStringContainsString('vyberte svou jednotku', implode(' ', $p['steps']));
            $this->assertFalse(moda_poster(false)['join'], 'správce může zvolit jen adresu webu');
            $html = $this->view('poster', ['p' => $p, 'hasJoin' => true, 'withJoin' => true]);
            $this->assertStringContainsString('Jak se zaregistrovat', $html);
            $this->assertStringContainsString('data-print', $html);
            $this->assertStringContainsString('Zahradní 12, Brno', $html);
        } finally {
            moda_setting('join_on', '0');
        }
    }

    public function testPosterEscapesHouseName(): void
    {
        $old = setting('site_full');
        moda_setting('site_full', 'SVJ <b>Test</b>');
        try {
            $html = $this->view('poster', ['p' => moda_poster(false), 'hasJoin' => false, 'withJoin' => false]);
            $this->assertStringContainsString('SVJ &lt;b&gt;Test&lt;/b&gt;', $html);
            $this->assertStringContainsString('/admin/uzivatele/zadosti', $html, 'nabídne zapnutí odkazu pro přiřazení');
        } finally {
            moda_setting('site_full', $old);
        }
    }

    // ---------- Export dat ----------

    public function testExportZipContainsAllDataAndReadme(): void
    {
        $file = moda_export_zip();
        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($file));
            $data = json_decode((string) $zip->getFromName('data.json'), true);
            $this->assertSame('svj-backup-1', $data['format']);
            $this->assertSame(BACKUP_TABLES, array_keys($data['tables']));
            $this->assertContains('petr.novak@example.cz', array_column($data['tables']['users'], 'email'));
            $this->assertStringContainsString('data.json', (string) $zip->getFromName('CTI-ME.txt'));
            $zip->close();
        } finally {
            @unlink($file);
        }
    }

    public function testExportAlwaysAllowedWithBackupFeature(): void
    {
        moda_setting('export_last', date('Y-m-d H:i:s'));
        $this->assertSame([true, ''], moda_export_allowed(), 'Premium má zálohu bez omezení');
        moda_setting('export_last', '');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFreeEditionExportOncePer30Days(): void
    {
        pq("UPDATE tenants SET billing = 1, plan = '', paid_until = '', trial_ends = '' WHERE slug = 'dum'");
        $this->assertFalse(plan_has('zaloha'));
        moda_setting('export_last', '');
        $this->assertSame([true, ''], moda_export_allowed(), 'první export vždy');
        moda_setting('export_last', date('Y-m-d H:i:s'));
        [$ok, $next] = moda_export_allowed();
        $this->assertFalse($ok);
        $this->assertSame(date('Y-m-d', time() + 30 * 86400), $next);
        moda_setting('export_last', date('Y-m-d H:i:s', time() - 31 * 86400));
        $this->assertTrue(moda_export_allowed()[0]);
        $html = $this->view('data', ['allowed' => false, 'next' => $next, 'error' => null, 'dpa' => true, 'backup' => false, 'last' => date('Y-m-d H:i:s'), 'files' => 0, 'size' => 0]);
        $this->assertStringContainsString('Další export bude možný', $html);
        $this->assertStringContainsString('jednou za 30 dní', $html);
        $this->assertStringNotContainsString('name="current_password"', $html);
    }

    public function testDataPageOffersExportAndContract(): void
    {
        $html = $this->view('data', ['allowed' => true, 'next' => '', 'error' => 'Heslo <x>', 'dpa' => true, 'backup' => true, 'last' => '', 'files' => 3, 'size' => 2048]);
        $this->assertStringContainsString('/admin/data-domu/export', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('/admin/data-domu/smlouva', $html);
        $this->assertStringContainsString('/bezpecnost', $html);
        $this->assertStringContainsString('Heslo &lt;x&gt;', $html);
    }

    // ---------- Zpracovatelská smlouva ----------

    public function testDpaPrefilledWithHouseAndOperator(): void
    {
        pset_save('op_name', 'Provozovatel s.r.o.');
        pset_save('op_ico', '12345678');
        try {
            $d = moda_dpa();
            $this->assertNotNull($d);
            $this->assertSame('Společenství vlastníků jednotek Zahradní 12', $d['svj']['name']);
            $this->assertSame(setting('ico'), $d['svj']['ico']);
            $this->assertSame('Zahradní 12, Brno', $d['svj']['address']);
            $this->assertSame('Provozovatel s.r.o.', $d['op']['name']);
            $this->assertStringContainsString('čl. 28', $d['text']);
            $html = $this->view('dpa', ['d' => $d]);
            $this->assertStringContainsString('Provozovatel s.r.o.', $html);
            $this->assertStringContainsString('IČO: 12345678', $html);
            $this->assertStringContainsString('Za správce', $html);
        } finally {
            pset_save('op_name', '');
            pset_save('op_ico', '');
        }
    }

    // ---------- Bezpečnost ----------

    public function testSecurityFactsClaimOnlyWhatIsConfigured(): void
    {
        $s = moda_security_facts();
        $this->assertFalse($s['backup'], 'testovací config.php nemá backup_key – denní šifrovanou zálohu stránka netvrdí');
        $this->assertSame('Servery v České republice (Evropská unie).', $s['hosting']);
        $html = $this->view('security', ['s' => $s]);
        $this->assertStringNotContainsString('zašifruje', $html);
        $this->assertStringContainsString('/zpracovani-udaju', $html);
        $this->assertStringContainsString('vlastní databázi', $html);
        pset_save('hosting_note', 'Servery v Praze.');
        try {
            $this->assertSame('Servery v Praze.', moda_security_facts()['hosting']);
        } finally {
            pset_save('hosting_note', '');
        }
    }

    public function testSecurityPageAndFooterAreWired(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertContains('bezpecnost', PLATFORM_PATHS, 'adresa nejde zabrat jako název webu SVJ');
        $this->assertNotNull(slug_problem('bezpecnost'));
        $this->assertStringContainsString("url('/bezpecnost')", (string) file_get_contents($root . '/app/views/platform/site_layout.php'));
        $this->assertStringContainsString("url('/admin/data-domu')", (string) file_get_contents($root . '/app/views/admin/settings.php'));
        $this->assertSame(['Plakát do vchodu', 'Komunikace'], $GLOBALS['EXT_ADMIN_NAV']['/admin/plakat']);
        $this->assertSame(['Data a smlouva', 'Správa webu'], $GLOBALS['EXT_ADMIN_NAV']['/admin/data-domu']);
    }

    // ---------- Obrazovky modulu ----------

    public function testPollHelpShowsGapAndReminderButton(): void
    {
        $poll = one('SELECT * FROM polls ORDER BY id LIMIT 1');
        $html = $this->view('_poll_help', ['poll' => $poll, 'state' => poll_state($poll), 'results' => poll_results((int) $poll['id'])]);
        $this->assertStringContainsString('Co chybí k přijetí', $html);
        if (poll_state($poll) === 'open') {
            $this->assertStringContainsString('/admin/hlasovani/' . $poll['id'] . '/pripomenout', $html);
            $this->assertStringContainsString('Připomenout nehlasujícím', $html);
        }
    }

    public function testDigestBoxForAdminHasHouseSwitch(): void
    {
        $admin = one("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $html = $this->view('_digest_box', ['u' => $admin]);
        $this->assertStringContainsString('id="tydenni-prehled"', $html);
        $this->assertStringContainsString('name="digest" checked', $html);
        $this->assertStringContainsString('name="house"', $html);
        $resident = one("SELECT * FROM users WHERE kind = 'tenant' ORDER BY id LIMIT 1");
        $html = $this->view('_digest_box', ['u' => $resident]);
        $this->assertStringNotContainsString('name="house"', $html);
        $this->assertStringNotContainsString('name="digest" checked', $html);
    }

    public function testMagicLinkScreensAndLoginLink(): void
    {
        $html = $this->view('magic_request', ['state' => '', 'email' => '"><x', 'mailReady' => true]);
        $this->assertStringContainsString('Poslat odkaz pro přihlášení', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;x', $html);
        $this->assertStringContainsString('jen jednou', $this->view('magic_request', ['state' => 'sent', 'email' => '', 'mailReady' => true]));
        $this->assertStringContainsString('neumí posílat e-maily', $this->view('magic_request', ['state' => '', 'email' => '', 'mailReady' => false]));
        $confirm = $this->view('magic_confirm', ['token' => str_repeat('a', 64), 'name' => 'Petr <Novák>']);
        $this->assertStringContainsString('method="post"', $confirm, 'odkaz se spotřebuje až tlačítkem (ne náhledem v poště)');
        $this->assertStringContainsString('Petr &lt;Novák&gt;', $confirm);
        $this->assertStringContainsString("url('/prihlaseni/odkaz')", (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/login.php'));
    }
}
