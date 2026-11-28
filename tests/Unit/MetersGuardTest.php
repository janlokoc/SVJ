<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Ochrana automatického příjmu odečtů a hlídání chybějících dat (app/meters_lib.php, 11/2026). */
final class MetersGuardTest extends TestCase
{
    private array $saved = [];
    private string $meter;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        meters_ready();
        $this->meter = 'G-' . bin2hex(random_bytes(4));
        $this->unit = (int) val("SELECT id FROM units WHERE entrance = 'B' AND number = '4'");
    }

    protected function tearDown(): void
    {
        q("DELETE FROM readings WHERE meter_no LIKE 'G-%'");
        q("DELETE FROM reading_pending WHERE meter_no LIKE 'G-%'");
        q("DELETE FROM reading_history WHERE meter_no LIKE 'G-%'");
        q("DELETE FROM meter_api_log WHERE ip LIKE '192.0.2.%'");
        foreach ($this->saved as $k => $v) {
            q('DELETE FROM settings WHERE `key` = ?', [$k]);
            if ($v !== null) {
                q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$k, $v]);
            }
        }
        setting('__reset');
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_API_KEY']);
        parent::tearDown();
    }

    private function set(string $k, ?string $v): void
    {
        if (!array_key_exists($k, $this->saved)) {
            $r = one('SELECT value FROM settings WHERE `key` = ?', [$k]);
            $this->saved[$k] = $r ? (string) $r['value'] : null;
        }
        q('DELETE FROM settings WHERE `key` = ?', [$k]);
        if ($v !== null) {
            q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$k, $v]);
        }
        setting('__reset');
    }

    private function reading(float $amount, string $date, string $source = 'api', ?int $user = null): void
    {
        q('INSERT INTO readings (unit_id, kind, meter_no, amount, read_date, user_id, note, source) VALUES (?,?,?,?,?,?,?,?)', [$this->unit, 'tv', $this->meter, $amount, $date, $user, '', $source]);
    }

    private function row(float $amount, string $date): array
    {
        return ['unit_id' => $this->unit, 'kind' => 'tv', 'meter_no' => $this->meter, 'amount' => $amount, 'read_date' => $date];
    }

    private static function ago(int $days): string
    {
        return date('Y-m-d', strtotime("-$days days"));
    }

    public function testGuardAcceptsNormalGrowth(): void
    {
        foreach ([[100, 120], [102, 90], [104, 60], [106, 30]] as [$a, $d]) {
            $this->reading($a, self::ago($d));
        }
        $this->assertSame('', meter_guard($this->row(108.5, self::ago(1)), null));
    }

    public function testGuardHoldsDecreaseJumpAndOutOfOrder(): void
    {
        foreach ([[100, 120], [102, 90], [104, 60], [106, 30]] as [$a, $d]) {
            $this->reading($a, self::ago($d));
        }
        $this->assertStringContainsString('nižší než předchozí', meter_guard($this->row(105, self::ago(1)), null));
        $this->assertStringContainsString('Nezvykle vysoká spotřeba', meter_guard($this->row(140, self::ago(1)), null), '34 m³ za měsíc místo 2');
        $this->assertStringContainsString('vyšší než pozdější', meter_guard($this->row(107, self::ago(45)), null));
    }

    public function testGuardProtectsManualOldAndNotifiedReadings(): void
    {
        $this->reading(50, self::ago(3), 'manual', null);
        $old = one('SELECT * FROM readings WHERE meter_no = ?', [$this->meter]);
        $this->assertStringContainsString('ručně zadaný', meter_guard($this->row(51, self::ago(3)), $old));
        $old['source'] = 'api';
        $old['read_date'] = self::ago(60);
        $this->assertStringContainsString('starší než', meter_guard($this->row(51, self::ago(60)), $old));
        $ym = substr(self::ago(3), 0, 7);
        $this->set('meter_notice_' . $ym, '2026-01-01 08:00|1');
        $old['read_date'] = self::ago(3);
        $this->assertStringContainsString('upozornění', meter_guard($this->row(51, self::ago(3)), $old));
        $this->assertStringContainsString('Změnil by spotřebu', meter_guard($this->row(52, $ym . '-01' === self::ago(3) ? $ym . '-02' : $ym . '-01'), null), 'nový odečet v uzavřeném měsíci u měřidla, které už v něm data má');
    }

    public function testApiHoldsSuspiciousRowAndAdminDecides(): void
    {
        $this->set('meter_notice_' . substr(self::ago(2), 0, 7), null);
        $this->reading(100, self::ago(20));
        [$new, $upd, $held] = meter_rows_save([[1, $this->row(90, self::ago(2)), '', []]], 'api');
        $this->assertSame([0, 0, 1], [$new, $upd, $held]);
        $this->assertSame(1, (int) val('SELECT COUNT(*) FROM readings WHERE meter_no = ?', [$this->meter]), 'podezřelý řádek se neuložil');
        $p = one('SELECT * FROM reading_pending WHERE meter_no = ?', [$this->meter]);
        $this->assertStringContainsString('nižší', $p['reason']);
        $this->assertSame([1, 0, 0], array_slice(meter_rows_save([[1, $this->row(90, self::ago(2)), '', []]], 'import'), 0, 3), 'ruční import správce se nezadržuje');
        q('DELETE FROM readings WHERE meter_no = ? AND read_date = ?', [$this->meter, self::ago(2)]);
        $this->assertTrue(meter_pending_decide((int) $p['id'], true));
        $this->assertEquals(90, (float) val('SELECT amount FROM readings WHERE meter_no = ? AND read_date = ?', [$this->meter, self::ago(2)]));
        $this->assertFalse(meter_pending_decide((int) $p['id'], true), 'vyřízený už nejde znovu');
        meter_hold($this->row(80, self::ago(1)), 'api', 'test');
        $id = (int) val('SELECT id FROM reading_pending WHERE meter_no = ?', [$this->meter]);
        $this->assertTrue(meter_pending_decide($id, false));
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM readings WHERE meter_no = ? AND read_date = ?', [$this->meter, self::ago(1)]), 'zamítnutý se neuloží');
    }

    public function testIpAllowList(): void
    {
        $this->assertTrue(meter_ip_allowed('198.51.100.7', ''));
        $this->assertTrue(meter_ip_allowed('203.0.113.10', '203.0.113.10'));
        $this->assertFalse(meter_ip_allowed('203.0.113.11', '203.0.113.10'));
        $this->assertTrue(meter_ip_allowed('198.51.100.77', '203.0.113.10, 198.51.100.0/24'));
        $this->assertFalse(meter_ip_allowed('198.51.101.1', '198.51.100.0/24'));
        $this->assertTrue(meter_ip_allowed('10.1.2.3', '10.0.0.0/12'));
        $this->assertFalse(meter_ip_allowed('10.16.0.1', '10.0.0.0/12'));
        $this->assertTrue(meter_ip_allowed('2001:db8::5', '2001:db8::/32'));
        $this->assertFalse(meter_ip_allowed('2001:db9::5', '2001:db8::/32'));
        $this->assertFalse(meter_ip_allowed('203.0.113.10', '2001:db8::/32'), 'IPv4 proti IPv6 rozsahu');
        $this->assertSame('203.0.113.10, 198.51.100.0/24', meter_ip_list_clean(" 203.0.113.10;198.51.100.0/24 203.0.113.10 "));
        $this->assertIsArray(meter_ip_list_clean('firma.cz'));
        $this->assertIsArray(meter_ip_list_clean('10.0.0.0/33'));
        $this->assertSame('', meter_ip_list_clean(''));
    }

    public function testBearerHeaderOrApiKey(): void
    {
        $key = str_repeat('ab', 20);
        $this->assertSame('', meter_bearer());
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . strtoupper($key);
        $this->assertSame($key, meter_bearer());
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic xyz';
        $this->assertSame('', meter_bearer());
        $_SERVER['HTTP_X_API_KEY'] = $key;
        $this->assertSame($key, meter_bearer());
        $_SERVER['HTTP_X_API_KEY'] = 'short';
        $this->assertSame('', meter_bearer());
        $this->assertStringContainsString('api/odecty(/[a-f0-9]{40})?|api/v1(/.*)?|mcp', (string) file_get_contents(dirname(__DIR__, 2) . '/index.php'), 'adresy pro stroje jsou vyjmuté z kontroly formulářového tokenu');
    }

    public function testFailedAttemptsBlockAddressAndMailAdminsOnce(): void
    {
        $ip = '192.0.2.' . random_int(1, 250);
        for ($i = 1; $i < METER_API_FAIL_LIMIT; $i++) {
            meter_api_fail($ip, 'url', 'špatný klíč');
        }
        $this->assertFalse(meter_api_blocked($ip));
        $this->assertSame([], $this->mails());
        meter_api_fail($ip, 'url', 'špatný klíč');
        $this->assertTrue(meter_api_blocked($ip));
        $this->assertFalse(meter_api_blocked('192.0.2.251'), 'jiná adresa blokovaná není');
        $mails = $this->mails();
        $this->assertCount(count(meter_admin_emails()), $mails);
        $this->assertStringContainsString($ip, $mails[0]['body']);
        meter_api_fail($ip, 'url', 'špatný klíč');
        $this->assertCount(count(meter_admin_emails()), $this->mails(), 'e-mail jen jednou');
    }

    public function testApiRouteChecksBlockKeyIpAndLogs(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/routes_meters.php');
        $a = strpos($src, 'meter_api_blocked($ip)');
        $b = strpos($src, 'hash_equals($secret, $token)');
        $c = strpos($src, 'meter_ip_allowed($ip');
        $d = strpos($src, "meter_rows_save(\$checked, 'api')");
        $this->assertTrue($a !== false && $b !== false && $c !== false && $d !== false && $a < $b && $b < $c && $c < $d, 'blokace → klíč → IP → uložení');
        $this->assertStringContainsString("\$route('POST', '/api/odecty', ", $src);
        $this->assertStringContainsString("'held' => \$held", $src);
    }

    public function testMissingMetersDetectedAndAdminWarnedOnce(): void
    {
        $this->set('remote_kinds', 'tv,teplo,itn');
        $ym = '2030-02';
        $this->set('meter_missing_warned_' . $ym, null);
        $this->set('meter_firm_email', 'odecty@example.org');
        $this->set('meter_firm_auto', '1');
        $this->set('meter_missing_asked_' . $ym, null);
        q('INSERT INTO readings (unit_id, kind, meter_no, amount, read_date, user_id, note, source) VALUES (?,?,?,?,?,NULL,?,?)', [$this->unit, 'tv', $this->meter, 10, '2030-01-31', '', 'api']);
        $other = $this->meter . 'X';
        q('INSERT INTO readings (unit_id, kind, meter_no, amount, read_date, user_id, note, source) VALUES (?,?,?,?,?,NULL,?,?)', [$this->unit, 'tv', $other, 5, '2030-01-31', '', 'api']);
        q('INSERT INTO readings (unit_id, kind, meter_no, amount, read_date, user_id, note, source) VALUES (?,?,?,?,?,NULL,?,?)', [$this->unit, 'tv', $other, 6, '2030-02-28', '', 'api']);
        $this->assertSame([[$this->unit, 'tv', $this->meter]], array_values(array_filter(meter_missing($ym), fn($m) => str_starts_with($m[2], 'G-'))));
        meter_missing_daily('2030-03-02');
        $this->assertSame([], $this->mails(), 'před 3. dnem v měsíci nic');
        meter_missing_daily('2030-03-03');
        $mails = $this->mails();
        $to = array_column($mails, 'to');
        $this->assertContains('odecty@example.org', $to, 'firma automaticky požádána');
        $firm = $mails[array_search('odecty@example.org', $to, true)];
        $body = quoted_printable_decode(str_replace("=\r\n", '', $firm['body']));
        $this->assertStringContainsString('měřidlo č. ' . $this->meter, $body);
        $this->assertStringNotContainsString('měřidlo č. ' . $other . '<', $body, 'měřidlo s daty v seznamu není');
        $this->assertCount(1 + count(meter_admin_emails()), $mails);
        $this->assertNotSame('', setting('meter_missing_asked_' . $ym));
        meter_missing_daily('2030-03-04');
        $this->assertCount(1 + count(meter_admin_emails()), $this->mails(), 'jednou za měsíc');
    }

    public function testResidentSeesLateNoteAndFallbackAllowsManualEntry(): void
    {
        $this->set('remote_kinds', 'tv,teplo,itn');
        $last = date('Y-m', strtotime('first day of last month'));
        $before = date('Y-m', strtotime('first day of -2 months'));
        q('INSERT INTO readings (unit_id, kind, meter_no, amount, read_date, user_id, note, source) VALUES (?,?,?,?,?,NULL,?,?)', [$this->unit, 'tv', $this->meter, 10, $before . '-28', '', 'api']);
        $this->assertSame($last, meter_late_month([$this->unit]));
        $this->assertSame('', meter_late_month([0]));
        $this->set('meter_manual_fallback', null);
        $this->assertFalse(meter_manual_fallback());
        $this->set('meter_manual_fallback', '1');
        $this->assertTrue(meter_manual_fallback());
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/routes_extra.php');
        $this->assertStringContainsString('remote_kinds(), true) && !meter_manual_fallback()', $src);
        $this->assertStringContainsString("'lateMonth' =>", $src);
    }
}
