<?php
declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Tests\TestCase;

/**
 * Modul H – shromáždění online a hybridně (app/ext/hybrid_lib.php): nastavení termínu, připojení vlastníka,
 * usnášeníschopnost, hlasování online a podklady pro zápis. Prezence a výsledky návrhů jsou v app/routes_presence.php.
 */
final class ModHHybridTest extends TestCase
{
    private array $savedSettings = [];
    private array $savedPset = [];
    private array $savedShares = [];
    /** Termíny založené testem – po testu se smažou (jinak by se objevily třeba v týdenním přehledu jiných testů). */
    private array $events = [];

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('presence')) {
            $route = function (): void {};   // soubor tras: trasy se jen „zaregistrují“ naprázdno
            require_once dirname(__DIR__, 2) . '/app/routes_presence.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->platformSetting('beta_' . HYBRID_BETA, 'on');
        $this->tenantSetting('hybrid_on', '1');
        $this->tenantSetting('hybrid_email_code', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedShares as $id => $share) {
            q('UPDATE units SET share = ? WHERE id = ?', [$share, $id]);
        }
        foreach ($this->savedSettings as $key => $value) {
            self::setSetting($key, $value);
        }
        foreach ($this->savedPset as $key => $value) {
            if ($value === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
                pset('__reset');
            } else {
                pset_save($key, $value);
            }
        }
        $this->events && hybrid_ready();
        foreach ($this->events as $id) {
            foreach (['hybrid_attendance', 'hybrid_events', 'event_presence'] as $t) {
                q("DELETE FROM $t WHERE event_id = ?", [$id]);
            }
            q('DELETE FROM events WHERE id = ?', [$id]);
        }
        $this->savedSettings = $this->savedPset = $this->savedShares = $this->events = [];
    }

    private static function setSetting(string $key, ?string $value): void
    {
        if ($value === null) {
            q('DELETE FROM settings WHERE `key` = ?', [$key]);
        } else {
            q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', [$key, $value]);
        }
        setting('__reset');
    }

    private function tenantSetting(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->savedSettings)) {
            $this->savedSettings[$key] = ($r = one('SELECT value FROM settings WHERE `key` = ?', [$key])) ? (string) $r['value'] : null;
        }
        self::setSetting($key, $value);
    }

    private function platformSetting(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->savedPset)) {
            $r = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetch();
            $this->savedPset[$key] = $r ? (string) $r['value'] : null;
        }
        if ($value === null) {
            pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
            pset('__reset');
        } else {
            pset_save($key, $value);
        }
    }

    /** Termín (výchozí dnes) s nastavenou formou. */
    private function event(string $form = 'hybridni', int $dayOffset = 0, bool $statutes = true, string $url = 'https://meet.jit.si/Shromazdeni-test'): int
    {
        q('INSERT INTO events (title, event_date, event_time, place, note, rsvp, program) VALUES (?,?,?,?,?,1,?)',
            ['Shromáždění ' . uniqid(), date('Y-m-d', strtotime(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' day')), '18:00', 'Sušárna', '', '']);
        $id = (int) db()->lastInsertId();
        $this->events[] = $id;
        if ($form !== 'prezencni') {
            hybrid_event_store($id, ['hybrid_form' => $form, 'hybrid_video_url' => $url, 'hybrid_statutes' => $statutes ? '1' : ''], 1);
            if (!$statutes) {   // bez potvrzení by se neuložilo – zapíšeme přímo, abychom ověřili, že se pak online nekoná
                q("INSERT INTO hybrid_events (event_id, form, video_url, statutes_ok) VALUES (?,?,?,0)", [$id, $form, $url]);
            }
        }
        return $id;
    }

    /** Nový vlastník s danými jednotkami. */
    private function owner(array $unitIds, string $kind = 'owner'): array
    {
        q('INSERT INTO users (email, name, password_hash, role, kind) VALUES (?,?,?,?,?)', ['h' . bin2hex(random_bytes(5)) . '@example.cz', 'Vlastník Online', 'x', 'resident', $kind]);
        $id = (int) db()->lastInsertId();
        foreach ($unitIds as $u) {
            q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$id, $u]);
        }
        return one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function motion(int $eventId, string $status = 'open', string $majority = 'half'): int
    {
        q("INSERT INTO meeting_motions (event_id, title, majority, sort, status, closed_at) VALUES (?,?,?,1,?,'')", [$eventId, 'Návrh ' . uniqid(), $majority, $status]);
        return (int) db()->lastInsertId();
    }

    private function onSite(int $eventId, int $unitId, string $mode = 'self'): void
    {
        q("INSERT INTO event_presence (event_id, unit_id, mode, proxy_name, proxy_ok, checked_by, checked_at) VALUES (?,?,?,'',0,1,?)", [$eventId, $unitId, $mode, date('Y-m-d H:i:s')]);
    }

    private function weight(int $unitId): float
    {
        return share_value((string) val('SELECT share FROM units WHERE id = ?', [$unitId]));
    }

    // ---------- Tabulky ----------

    public function testReadyCreatesTablesAndVoteColumnsOnce(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE meeting_votes (motion_id INTEGER NOT NULL, unit_id INTEGER NOT NULL, choice TEXT NOT NULL DEFAULT '', PRIMARY KEY (motion_id, unit_id))");
        $pdo->exec("INSERT INTO meeting_votes (motion_id, unit_id, choice) VALUES (1, 1, 'no')");
        hybrid_ready($pdo);
        hybrid_ready($pdo);
        $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertSame(['motion_id', 'unit_id', 'choice', 'method', 'voted_at'], $cols('meeting_votes'));
        $this->assertSame(['event_id', 'form', 'video_url', 'statutes_ok', 'statutes_by', 'statutes_at', 'updated_at'], $cols('hybrid_events'));
        $this->assertSame(['id', 'event_id', 'unit_id', 'user_id', 'joined_at', 'left_at', 'ip', 'verified'], $cols('hybrid_attendance'));
        $this->assertSame(['motion_id', 'opened_at', 'opened_by'], $cols('hybrid_live'));
        // dřívější hlasy zapsané správcem mají prázdný způsob (= na místě)
        $this->assertSame('', $pdo->query('SELECT method FROM meeting_votes')->fetchColumn());
    }

    // ---------- Dostupnost ----------

    public function testAvailabilityFollowsBetaStageAndTenantSwitch(): void
    {
        $this->platformSetting('beta_' . HYBRID_BETA, null);
        $this->assertSame('off', beta_stage(HYBRID_BETA), 'nová funkce je ve výchozím stavu vypnutá');
        $this->assertFalse(hybrid_available());
        $this->assertFalse(hybrid_enabled());
        $this->platformSetting('beta_' . HYBRID_BETA, 'beta');
        $this->assertTrue(hybrid_available(), 'testovací dům má edici Premium – beta je pro něj vidět');
        $this->platformSetting('beta_' . HYBRID_BETA, 'on');
        $this->assertTrue(hybrid_enabled());
        $this->tenantSetting('hybrid_on', '0');
        $this->assertTrue(hybrid_available());
        $this->assertFalse(hybrid_enabled(), 'bez zapnutí správcem domu se nic nenabízí');
    }

    public function testAdminMenuItemIsRegisteredAndHiddenWhenUnavailable(): void
    {
        $item = $GLOBALS['EXT_ADMIN_NAV']['/admin/shromazdeni-online'] ?? null;
        $this->assertNotNull($item);
        $this->assertTrue(($item[2])());
        $this->platformSetting('beta_' . HYBRID_BETA, 'off');
        $this->assertFalse(($item[2])());
    }

    // ---------- Nastavení termínu ----------

    public function testVideoUrlValidation(): void
    {
        $this->assertNotNull(hybrid_url_error(''));
        $this->assertNotNull(hybrid_url_error('meet.jit.si/abc'));
        $this->assertNotNull(hybrid_url_error('http://meet.jit.si/abc'), 'jen https');
        $this->assertNotNull(hybrid_url_error('javascript:alert(1)'));
        $this->assertNotNull(hybrid_url_error('https://' . str_repeat('a', 500) . '.cz'));
        $this->assertNull(hybrid_url_error('https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0?context=%7b%7d'));
        $this->assertNull(hybrid_url_error('https://us02web.zoom.us/j/123456789?pwd=abc'));
    }

    public function testProviderNamesFromUrl(): void
    {
        $this->assertSame('Jitsi Meet', hybrid_provider('https://meet.jit.si/Room'));
        $this->assertSame('Jitsi Meet', hybrid_provider('https://jitsi.example.cz/Room'));
        $this->assertSame('Microsoft Teams', hybrid_provider('https://teams.microsoft.com/l/meetup-join/x'));
        $this->assertSame('Zoom', hybrid_provider('https://us02web.zoom.us/j/1'));
        $this->assertSame('Google Meet', hybrid_provider('https://meet.google.com/abc-defg-hij'));
        $this->assertSame('videohovor (video.example.cz)', hybrid_provider('https://video.example.cz/x'));
        $this->assertSame('', hybrid_provider('nic'));
    }

    public function testJitsiRoomIsRandomAndValid(): void
    {
        $a = hybrid_jitsi_room();
        $b = hybrid_jitsi_room();
        $this->assertMatchesRegularExpression('~^https://meet\.jit\.si/Shromazdeni-[a-z2-9]{20}$~', $a);
        $this->assertNotSame($a, $b);
        $this->assertNull(hybrid_url_error($a));
        $this->assertSame('Jitsi Meet', hybrid_provider($a));
    }

    public function testEventInputRequiresStatutesAndUrlForRemoteForms(): void
    {
        [$errors, $d] = hybrid_event_input(['hybrid_form' => 'prezencni', 'hybrid_video_url' => 'https://x.cz/a']);
        $this->assertSame([], $errors);
        $this->assertSame(['form' => 'prezencni', 'video_url' => '', 'statutes_ok' => 0], $d);

        [$errors] = hybrid_event_input(['hybrid_form' => 'hybridni', 'hybrid_video_url' => 'https://meet.jit.si/a']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('stanovy', $errors[0]);

        [$errors] = hybrid_event_input(['hybrid_form' => 'online', 'hybrid_statutes' => '1', 'hybrid_video_url' => '']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('odkaz', $errors[0]);

        [$errors, $d] = hybrid_event_input(['hybrid_form' => 'online', 'hybrid_statutes' => '1', 'hybrid_video_url' => ' https://meet.jit.si/a ']);
        $this->assertSame([], $errors);
        $this->assertSame(['form' => 'online', 'video_url' => 'https://meet.jit.si/a', 'statutes_ok' => 1], $d);

        [, $d] = hybrid_event_input(['hybrid_form' => 'nesmysl']);
        $this->assertSame('prezencni', $d['form']);
    }

    public function testEventCheckIsSilentWhenFeatureOff(): void
    {
        $bad = ['hybrid_form' => 'online'];
        $this->assertNotEmpty(hybrid_event_check($bad));
        $this->tenantSetting('hybrid_on', '0');
        $this->assertSame([], hybrid_event_check($bad));
    }

    public function testEventStoreKeepsConfirmationAuthorAndClearsUrlForOnSite(): void
    {
        $id = $this->event('prezencni');
        $this->assertSame('prezencni', hybrid_event($id)['form'], 'bez záznamu je termín prezenční');
        hybrid_event_store($id, ['hybrid_form' => 'hybridni', 'hybrid_video_url' => 'https://meet.jit.si/a', 'hybrid_statutes' => '1'], 7);
        $h = hybrid_event($id);
        $this->assertSame(['hybridni', 'https://meet.jit.si/a', 1, 7], [$h['form'], $h['video_url'], (int) $h['statutes_ok'], (int) $h['statutes_by']]);
        $this->assertNotSame('', $h['statutes_at']);
        // další uložení jiným správcem nemění, kdo stanovy potvrdil
        hybrid_event_store($id, ['hybrid_form' => 'online', 'hybrid_video_url' => 'https://meet.jit.si/b', 'hybrid_statutes' => '1'], 9);
        $this->assertSame(7, (int) hybrid_event($id)['statutes_by']);
        $this->assertNotNull(hybrid_remote($id));
        // neplatný vstup se neuloží
        hybrid_event_store($id, ['hybrid_form' => 'online', 'hybrid_video_url' => ''], 9);
        $this->assertSame('https://meet.jit.si/b', hybrid_event($id)['video_url']);
        hybrid_event_store($id, ['hybrid_form' => 'prezencni', 'hybrid_video_url' => 'https://meet.jit.si/b'], 9);
        $h = hybrid_event($id);
        $this->assertSame(['prezencni', '', 0, 0], [$h['form'], $h['video_url'], (int) $h['statutes_ok'], (int) $h['statutes_by']]);
        $this->assertNull(hybrid_remote($id));
    }

    public function testEventStoreDoesNothingWhenFeatureOff(): void
    {
        $id = $this->event('prezencni');
        $this->tenantSetting('hybrid_on', '0');
        hybrid_event_store($id, ['hybrid_form' => 'online', 'hybrid_video_url' => 'https://meet.jit.si/a', 'hybrid_statutes' => '1'], 1);
        $this->assertSame('prezencni', hybrid_event($id)['form']);
    }

    public function testRemoteNeedsConfirmedStatutesAndEnabledFeature(): void
    {
        $this->assertNull(hybrid_remote($this->event('online', 0, false)));
        $id = $this->event('online');
        $this->assertNotNull(hybrid_remote($id));
        $this->platformSetting('beta_' . HYBRID_BETA, 'off');
        $this->assertNull(hybrid_remote($id), 'když provozovatel funkci vypne, termín se online nekoná');
    }

    // ---------- Připojení ----------

    public function testJoinErrors(): void
    {
        $owner = $this->owner([1]);
        $this->assertNotNull(hybrid_join_error($this->event('prezencni'), $owner));
        $this->assertStringContainsString('v den konání', (string) hybrid_join_error($this->event('online', 2), $owner));
        $this->assertStringContainsString('proběhlo', (string) hybrid_join_error($this->event('online', -1), $owner));
        $id = $this->event('online');
        $this->assertNull(hybrid_join_error($id, $owner));
        $this->assertNotNull(hybrid_join_error($id, $this->owner([2], 'tenant')), 'nájemník se jako vlastník nepřipojí');
        $this->assertNotNull(hybrid_join_error($id, $this->owner([])), 'vlastník bez jednotky');
        $this->onSite($id, 1);
        $this->assertStringContainsString('na místě', (string) hybrid_join_error($id, $owner));
        $this->assertSame([], hybrid_join($id, $owner), 'bez možnosti připojení se nic nezapíše');
    }

    public function testJoinIsBlockedWithoutShares(): void
    {
        $id = $this->event('online');
        $this->savedShares[5] = (string) val('SELECT share FROM units WHERE id = 5');
        q("UPDATE units SET share = '' WHERE id = 5");
        $this->assertStringContainsString('podíly', (string) hybrid_join_error($id, $this->owner([3])));
    }

    public function testJoinRecordsOnlinePresenceAndSkipsUnitsOnSite(): void
    {
        $id = $this->event('hybridni');
        $owner = $this->owner([2, 3, 4]);
        $this->onSite($id, 4, 'proxy');
        $joined = hybrid_join($id, $owner, 'login', '203.0.113.9');
        $this->assertSame([2, 3], $joined);
        $this->assertSame('online', val('SELECT mode FROM event_presence WHERE event_id = ? AND unit_id = 2', [$id]));
        $this->assertSame('proxy', val('SELECT mode FROM event_presence WHERE event_id = ? AND unit_id = 4', [$id]), 'zmocněnec na místě zůstává');
        $log = hybrid_attendance_log($id);
        $this->assertCount(2, $log);
        $this->assertSame(['203.0.113.9', 'login', '', (int) $owner['id']], [$log[0]['ip'], $log[0]['verified'], $log[0]['left_at'], (int) $log[0]['user_id']]);
        $this->assertTrue(hybrid_is_online($id, $owner));
        // opakované připojení nic nezdvojí
        hybrid_join($id, $owner, 'email');
        $this->assertCount(2, hybrid_attendance_log($id));
    }

    public function testQuorumCountsOnlineTogetherWithOnSiteAndProxies(): void
    {
        $id = $this->event('hybridni');
        $this->onSite($id, 1, 'self');
        $this->onSite($id, 5, 'proxy');
        hybrid_join($id, $this->owner([2, 3]));
        $p = presence($id);
        $this->assertSame(['yes' => 1, 'proxy' => 1, 'online' => 2], array_intersect_key($p['counts'], ['yes' => 0, 'proxy' => 0, 'online' => 0]));
        $total = array_sum(array_map(fn($r) => $r['weight'], $p['rows']));
        $expected = ($this->weight(1) + $this->weight(5) + $this->weight(2) + $this->weight(3)) / $total * 100;
        $this->assertEqualsWithDelta($expected, $p['percent'], 1e-9);
        $s = presence_summary($p);
        $this->assertSame(2, $s['online']);
        $this->assertSame($p['units'] - 4, $s['absent']);
    }

    public function testLeaveEndsPresenceAndRejoinStartsNewRecord(): void
    {
        $id = $this->event('online');
        $owner = $this->owner([6]);
        hybrid_join($id, $owner);
        $this->assertSame(1, hybrid_leave($id, $owner));
        $this->assertFalse((bool) val('SELECT 1 FROM event_presence WHERE event_id = ? AND unit_id = 6', [$id]));
        $this->assertFalse(hybrid_is_online($id, $owner));
        $this->assertNotSame('', hybrid_attendance_log($id)[0]['left_at']);
        $this->assertSame(0, hybrid_leave($id, $owner), 'odejít podruhé nejde');
        hybrid_join($id, $owner);
        $log = hybrid_attendance_log($id);
        $this->assertCount(2, $log);
        $this->assertSame('', $log[1]['left_at']);
    }

    public function testLeaveDoesNotTouchOnSitePresence(): void
    {
        $id = $this->event('hybridni');
        $owner = $this->owner([7, 8]);
        hybrid_join($id, $owner);
        // předsedající jednotku 8 přepsal na „osobně“ (vlastník dorazil)
        q("UPDATE event_presence SET mode = 'self' WHERE event_id = ? AND unit_id = 8", [$id]);
        $this->assertSame(1, hybrid_leave($id, $owner));
        $this->assertSame('self', val('SELECT mode FROM event_presence WHERE event_id = ? AND unit_id = 8', [$id]));
    }

    // ---------- Ověření kódem z e-mailu ----------

    private function mailedCode(): string
    {
        $mails = $this->mails();
        $this->assertNotEmpty($mails);
        $subject = mb_decode_mimeheader(end($mails)['subject']);
        $this->assertMatchesRegularExpression('/(\d{6})$/', $subject);
        preg_match('/(\d{6})$/', $subject, $m);
        return $m[1];
    }

    public function testEmailCodeFlow(): void
    {
        $this->tenantSetting('hybrid_email_code', '1');
        $this->assertTrue(hybrid_code_required());
        $id = $this->event('online');
        $owner = $this->owner([9]);
        $this->assertFalse(hybrid_code_pending($id, $owner));
        $this->assertTrue(hybrid_code_send($id, $owner));
        $code = $this->mailedCode();
        $mails = $this->mails();
        $this->assertSame($owner['email'], end($mails)['to'] ?? null);
        $this->assertTrue(hybrid_code_pending($id, $owner));
        $this->assertStringNotContainsString($code, json_encode($_SESSION), 'v relaci je jen otisk kódu');
        $other = $this->owner([10]);
        $this->assertFalse(hybrid_code_check($id, $other, $code), 'kód platí jen pro vlastníka, kterému přišel');
        $this->assertFalse(hybrid_code_check($id, $owner, $code === '000000' ? '111111' : '000000'));
        $this->assertTrue(hybrid_code_check($id, $owner, substr($code, 0, 3) . ' ' . substr($code, 3)));
        $this->assertFalse(hybrid_code_pending($id, $owner), 'kód je jednorázový');
        $this->assertFalse(hybrid_code_check($id, $owner, $code));
    }

    public function testEmailCodeExpiresAndLimitsAttempts(): void
    {
        $id = $this->event('online');
        $owner = $this->owner([9]);
        hybrid_code_send($id, $owner);
        $code = $this->mailedCode();
        for ($i = 0; $i < HYBRID_CODE_TRIES; $i++) {
            hybrid_code_check($id, $owner, 'x');
        }
        $this->assertFalse(hybrid_code_check($id, $owner, $code), 'po pěti chybách ani správný kód neprojde');
        hybrid_code_send($id, $owner);
        $code = $this->mailedCode();
        $_SESSION['hybrid_code'][$id]['until'] = time() - 1;
        $this->assertFalse(hybrid_code_pending($id, $owner));
        $this->assertFalse(hybrid_code_check($id, $owner, $code));
    }

    // ---------- Hlasování online ----------

    public function testLiveSetRefusesClosedAndForeignMotions(): void
    {
        $id = $this->event('online');
        $other = $this->event('online');
        $closed = $this->motion($id, 'closed');
        $open = $this->motion($id);
        $this->assertFalse(hybrid_live_set($id, $closed, true));
        $this->assertFalse(hybrid_live_set($other, $open, true), 'návrh jiného termínu');
        $this->assertTrue(hybrid_live_set($id, $open, true, 1));
        $this->assertSame([$open], array_map(fn($m) => (int) $m['id'], hybrid_live_motions($id)));
        $this->assertSame([], hybrid_live_motions($other));
        $this->assertTrue(hybrid_live_set($id, $open, false));
        $this->assertSame([], hybrid_live_motions($id));
    }

    public function testVoteValidation(): void
    {
        $id = $this->event('online');
        $owner = $this->owner([11]);
        $m = $this->motion($id);
        hybrid_join($id, $owner);
        $this->assertNotNull(hybrid_vote($id, $m, $owner, 'maybe'));
        $this->assertStringContainsString('nehlasuje', (string) hybrid_vote($id, $m, $owner, 'yes'), 'návrh ještě není puštěný');
        hybrid_live_set($id, $m, true);
        $this->assertStringContainsString('připojený', (string) hybrid_vote($id, $m, $this->owner([12]), 'yes'), 'nepřipojený vlastník');
        $this->assertNull(hybrid_vote($id, $m, $owner, 'no'));
        q("UPDATE meeting_motions SET status = 'closed' WHERE id = ?", [$m]);
        $this->assertNotNull(hybrid_vote($id, $m, $owner, 'yes'), 'po uzavření se hlasovat nedá');
        $this->assertSame('no', val('SELECT choice FROM meeting_votes WHERE motion_id = ? AND unit_id = 11', [$m]));
    }

    public function testVoteCoversAllOnlineUnitsOfOwnerAndCanBeChanged(): void
    {
        $id = $this->event('hybridni');
        $owner = $this->owner([2, 3, 4]);
        $this->onSite($id, 4);   // jednotka 4 hlasuje na místě
        hybrid_join($id, $owner);
        $m = $this->motion($id);
        hybrid_live_set($id, $m, true);
        $this->assertNull(hybrid_vote($id, $m, $owner, 'no'));
        $rows = all('SELECT unit_id, choice, method, voted_at FROM meeting_votes WHERE motion_id = ? ORDER BY unit_id', [$m]);
        $this->assertSame([2, 3], array_map(fn($r) => (int) $r['unit_id'], $rows));
        $this->assertSame(['online', 'online'], array_column($rows, 'method'));
        $this->assertNotSame('', $rows[0]['voted_at']);
        $this->assertNull(hybrid_vote($id, $m, $owner, 'yes'));
        $this->assertSame(['yes', 'yes'], array_column(all('SELECT choice FROM meeting_votes WHERE motion_id = ? ORDER BY unit_id', [$m]), 'choice'));
        $this->assertSame([$m => 'yes'], hybrid_my_votes($id, $owner));
        $this->assertCount(2, hybrid_online_votes($id));
    }

    public function testWeightedResultCountsOnlineNonVotersAsAbstainAndSplitsByMode(): void
    {
        $id = $this->event('hybridni');
        $this->onSite($id, 1);                 // na místě, neřekl nic → pro
        $this->onSite($id, 5);                 // na místě proti
        $a = $this->owner([2]);
        $b = $this->owner([3]);
        hybrid_join($id, $a);
        hybrid_join($id, $b);                  // online, nehlasuje → zdržel se
        $m = $this->motion($id);
        hybrid_live_set($id, $m, true);
        q("INSERT INTO meeting_votes (motion_id, unit_id, choice) VALUES (?, 5, 'no')", [$m]);
        $this->assertNull(hybrid_vote($id, $m, $a, 'yes'));
        $p = presence($id);
        $r = motion_result(one('SELECT * FROM meeting_motions WHERE id = ?', [$m]), $p);
        $this->assertSame(['yes' => 2, 'no' => 1, 'abstain' => 1], $r['count']);
        $this->assertSame(['site' => ['yes' => 1, 'no' => 1, 'abstain' => 0], 'online' => ['yes' => 1, 'no' => 0, 'abstain' => 1]], $r['byMode']);
        $present = $this->weight(1) + $this->weight(5) + $this->weight(2) + $this->weight(3);
        $this->assertEqualsWithDelta(($this->weight(1) + $this->weight(2)) / $present * 100, $r['yes'], 1e-9, 'hlasy se váží podíly jednotek');
        $this->assertEqualsWithDelta($this->weight(3) / $present * 100, $r['abstain'], 1e-9);
        $this->assertSame('Online pro 1, proti 0, zdrželo se 1; na místě pro 1, proti 1, zdrželo se 0 (počet jednotek).', hybrid_motion_split($r));
    }

    public function testMotionSplitIsEmptyWithoutOnlineVotesAndForOldResults(): void
    {
        $this->assertSame('', hybrid_motion_split(['count' => ['yes' => 3]]), 'uložený výsledek ze starší verze');
        $this->assertSame('', hybrid_motion_split(['byMode' => ['site' => ['yes' => 3, 'no' => 0, 'abstain' => 0], 'online' => ['yes' => 0, 'no' => 0, 'abstain' => 0]]]));
    }

    public function testOnSiteResultWithoutOnlineParticipantsIsUnchanged(): void
    {
        $id = $this->event('prezencni');
        $this->onSite($id, 1);
        $this->onSite($id, 2);
        $m = $this->motion($id);
        $r = motion_result(one('SELECT * FROM meeting_motions WHERE id = ?', [$m]), presence($id));
        $this->assertSame(['yes' => 2, 'no' => 0, 'abstain' => 0], $r['count'], 'na místě dál platí „kdo neřekne jinak, je pro“');
        $this->assertSame(0, presence($id)['counts']['online']);
    }

    // ---------- Průběžná aktualizace ----------

    public function testAdminStateReportsOnlineModesAndOpenMotions(): void
    {
        $id = $this->event('hybridni');
        $owner = $this->owner([2]);
        hybrid_join($id, $owner);
        $open = $this->motion($id);
        $closed = $this->motion($id, 'closed');
        hybrid_live_set($id, $open, true);
        hybrid_vote($id, $open, $owner, 'no');
        $s = hybrid_admin_state($id);
        $this->assertTrue($s['ok']);
        $this->assertSame(1, $s['online']);
        $this->assertSame('online', $s['modes'][2]);
        $this->assertSame('', $s['modes'][1]);
        $this->assertArrayHasKey($open, $s['motions']);
        $this->assertArrayNotHasKey($closed, $s['motions']);
        $this->assertSame(1, $s['motions'][$open]['cno']);
        $this->assertStringContainsString('Online pro 0, proti 1', $s['motions'][$open]['online']);
        $this->assertSame([$open], $s['live']);
        $this->assertNotFalse(json_encode($s));
    }

    public function testOwnerSignatureChangesWhenVotingStartsOrVoteIsCast(): void
    {
        $id = $this->event('online');
        $owner = $this->owner([2]);
        hybrid_join($id, $owner);
        $m = $this->motion($id);
        $s1 = hybrid_owner_signature($id, $owner);
        $this->assertSame($s1, hybrid_owner_signature($id, $owner));
        hybrid_live_set($id, $m, true);
        $s2 = hybrid_owner_signature($id, $owner);
        $this->assertNotSame($s1, $s2);
        hybrid_vote($id, $m, $owner, 'abstain');
        $s3 = hybrid_owner_signature($id, $owner);
        $this->assertNotSame($s2, $s3);
        q("UPDATE meeting_motions SET status = 'closed' WHERE id = ?", [$m]);
        $this->assertNotSame($s3, hybrid_owner_signature($id, $owner));
    }

    // ---------- Výstupy (háčky v šablonách) ----------

    public function testMinutesAndProtocolBlocksListOnlineParticipation(): void
    {
        $id = $this->event('hybridni');
        $owner = $this->owner([2]);
        hybrid_join($id, $owner);
        $m = $this->motion($id);
        hybrid_live_set($id, $m, true);
        hybrid_vote($id, $m, $owner, 'yes');
        $ev = one('SELECT * FROM events WHERE id = ?', [$id]);
        ob_start();
        hybrid_minutes_block($ev);
        $minutes = ob_get_clean();
        $this->assertStringContainsString('hybridní', $minutes);
        $this->assertStringContainsString('Jitsi Meet', $minutes);
        $this->assertStringContainsString(e(unit_name(one('SELECT * FROM units WHERE id = 2'))), $minutes);
        ob_start();
        hybrid_protocol_block($ev);
        $protocol = ob_get_clean();
        $this->assertStringContainsString('Účast online', $protocol);
        $this->assertStringContainsString('Hlasy odevzdané online', $protocol);
        $this->assertStringContainsString('Pro', $protocol);
        // prezenční termín bez online účasti: v zápisu nic navíc
        $plain = one('SELECT * FROM events WHERE id = ?', [$this->event('prezencni')]);
        ob_start();
        hybrid_minutes_block($plain);
        hybrid_protocol_block($plain);
        $this->assertSame('', ob_get_clean());
    }

    public function testEventBoxIsShownOnlyForRemoteEventsAndLoggedInUsers(): void
    {
        $ev = one('SELECT * FROM events WHERE id = ?', [$this->event('prezencni')]);
        ob_start();
        hybrid_event_box($ev);
        $this->assertSame('', ob_get_clean());
        $ev = one('SELECT * FROM events WHERE id = ?', [$this->event('online')]);
        ob_start();
        hybrid_event_box($ev);   // nikdo přihlášený
        $this->assertSame('', ob_get_clean());
    }

    public function testFormFieldsRenderOnlyWhenEnabled(): void
    {
        $id = $this->event('online');
        ob_start();
        hybrid_form_fields($id);
        $html = ob_get_clean();
        $this->assertStringContainsString('name="hybrid_form"', $html);
        $this->assertStringContainsString('value="online" checked', $html);
        $this->assertStringContainsString('name="hybrid_statutes" value="1" checked', $html);
        $this->assertMatchesRegularExpression('~data-jitsi-room="https://meet\.jit\.si/Shromazdeni-[a-z2-9]{20}"~', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->tenantSetting('hybrid_on', '0');
        ob_start();
        hybrid_form_fields($id);
        $this->assertSame('', ob_get_clean());
    }
}
