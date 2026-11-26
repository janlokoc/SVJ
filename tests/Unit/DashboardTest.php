<?php
declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Tests\TestCase;

/** app/dashboard_lib.php – ukazatele domu nad vlastním (prázdným) domem s přesně danými daty. */
final class DashboardTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $ids = [];
    private static int $now = 0;

    private static function at(float $daysAgo): string
    {
        return date('Y-m-d H:i:s', (int) round(self::$now - $daysAgo * 86400));
    }

    private static function day(int $offset): string
    {
        return date('Y-m-d', self::$now + $offset * 86400);
    }

    /** Dům s daty: 5 hlášení v různém stavu, revize, termíny, aktuality a záznamy činnosti. */
    private static function house(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        self::$now = time();
        $slug = 'dash-' . bin2hex(random_bytes(4));
        tenant_create(['slug' => $slug, 'name' => 'SVJ Přehled', 'title' => 'Přehled']);
        $pdo = tenant_pdo($slug);
        ticket_ready($pdo);
        foreach (['tickets', 'ticket_log', 'events', 'maintenance', 'posts', 'mailings', 'audit_log'] as $tbl) {
            $pdo->exec("DELETE FROM $tbl");
        }
        $ins = function (string $sql, array $p) use ($pdo): int {
            $pdo->prepare($sql)->execute($p);
            return (int) $pdo->lastInsertId();
        };
        $admin = $ins("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?,'admin')", ['vybor@dash.example.org', 'Výbor', NO_PASSWORD]);
        $staff = $ins("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?,'admin')", ['firma@dash.example.org', 'Firma', NO_PASSWORD]);
        $res = $ins("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?,'resident')", ['obyvatel@dash.example.org', 'Obyvatel', NO_PASSWORD]);
        $t = fn(string $subj, string $status, float $created, int $assignee, string $due, ?float $updated = null) => $ins(
            'INSERT INTO tickets (user_id, subject, body, status, created_at, updated_at, assignee_id, due_date) VALUES (?,?,?,?,?,?,?,?)',
            [$res, $subj, 'x', $status, self::at($created), self::at($updated ?? $created), $assignee, $due]);
        $log = fn(int $tid, float $ago, int $uid, string $kind, string $text) => $ins('INSERT INTO ticket_log (ticket_id, at, user_id, user_name, kind, text) VALUES (?,?,?,?,?,?)',
            [$tid, self::at($ago), $uid, $uid === $staff ? 'Firma' : 'Výbor', $kind, $text]);
        // T1: nové, nepřiřazené, bez reakce.
        $t1 = $t('T1 nové', 'new', 2, 0, '');
        // T2: řeší se, přiřazené, po termínu; reakce (přiřazení) za 4 hodiny.
        $t2 = $t('T2 po termínu', 'in_progress', 10, $staff, self::day(-1));
        $log($t2, 10 - 4 / 24, $staff, 'assign', 'Firma');
        // T3: vyřešené za 2 dny (podle historie), první reakce = změna stavu po 48 h.
        $t3 = $t('T3 vyřešené', 'done', 5, $admin, '', 0.5);
        $log($t3, 3, $admin, 'status', 'Vyřešeno');
        // T4: vyřešené bez historie – podle updated_at (před 150 dny, 50 dní po založení).
        $t4 = $t('T4 staré', 'done', 200, 0, '', 150);
        // T5: vyřešené dávno – mimo všechna období.
        $t5 = $t('T5 dávné', 'done', 400, 0, '', 1);
        $log($t5, 399, $admin, 'status', 'Vyřešeno');
        // Revize: jedna po termínu, jedna do 30 dní, jedna daleko, jedna bez data.
        $ins('INSERT INTO maintenance (title, next_date) VALUES (?,?)', ['Výtah', self::day(-5)]);
        $ins('INSERT INTO maintenance (title, next_date) VALUES (?,?)', ['Hasicí přístroje', self::day(10)]);
        $ins('INSERT INTO maintenance (title, next_date) VALUES (?,?)', ['Komín', self::day(200)]);
        $ins('INSERT INTO maintenance (title, next_date) VALUES (?,?)', ['Bez data', null]);
        // Termíny: proběhlý před týdnem, nejbližší za 3 dny, další za 20 dní.
        $ins('INSERT INTO events (title, event_date) VALUES (?,?)', ['Úklid', self::day(-7)]);
        $ins('INSERT INTO events (title, event_date) VALUES (?,?)', ['Shromáždění', self::day(3)]);
        $ins('INSERT INTO events (title, event_date) VALUES (?,?)', ['Odečty', self::day(20)]);
        $ins('INSERT INTO posts (title, body, published_at) VALUES (?,?,?)', ['Nová', '', self::day(-10)]);
        $ins('INSERT INTO posts (title, body, published_at) VALUES (?,?,?)', ['Starší', '', self::day(-100)]);
        // Činnost: přihlášení se nepočítá, obyvatel taky ne.
        $audit = fn(float $ago, int $uid, string $name, string $action) => $ins('INSERT INTO audit_log (at, user_id, user_name, action, detail) VALUES (?,?,?,?,?)', [self::at($ago), $uid, $name, $action, 'detail']);
        $audit(1, $admin, 'Výbor', 'login');
        $audit(1.5, $admin, 'Výbor', 'Úprava aktuality');
        $audit(0.5, $staff, 'Firma', 'Nový dokument');
        $audit(0.2, $res, 'Obyvatel', 'Nové hlášení');
        $audit(0.1, $staff, 'Firma', 'partner_login');
        self::$ids = ['admin' => $admin, 'staff' => $staff, 'res' => $res, 't1' => $t1, 't2' => $t2, 't3' => $t3, 't4' => $t4, 't5' => $t5];
        return self::$pdo = $pdo;
    }

    public function testCountsFor90Days(): void
    {
        $d = house_dashboard(self::house(), 90, false);
        $this->assertSame(90, $d['days']);
        $this->assertSame(2, $d['open']);
        $this->assertSame(1, $d['late']);
        $this->assertSame(1, $d['unassigned']);
        $this->assertSame(3, $d['created']);
        $this->assertSame(1, $d['resolved']);
        $this->assertEquals(2.0, $d['avg_days']);
        $this->assertEquals(26.0, $d['react_hours'], 'Průměr reakce (4 h a 48 h).');
        $this->assertSame(1, $d['revize_late']);
        $this->assertSame(1, $d['revize_soon']);
        $this->assertSame(1, $d['posts']);
        $this->assertSame(0, $d['mailings']);
        $this->assertSame(1, $d['events']);
        $this->assertSame(['title' => 'Shromáždění', 'event_date' => self::day(3)], array_intersect_key($d['next_event'], ['title' => 1, 'event_date' => 1]));
        $this->assertArrayNotHasKey('series', $d);
        $this->assertArrayNotHasKey('timeline', $d);
    }

    public function testCountsFor365Days(): void
    {
        $d = house_dashboard(self::house(), 365, false);
        $this->assertSame(4, $d['created']);
        $this->assertSame(2, $d['resolved']);
        $this->assertEquals(26.0, $d['avg_days'], 'Průměr (2 dny a 50 dní).');
        $this->assertSame(2, $d['posts']);
    }

    public function testCountsFor30DaysAndNoDataGivesNull(): void
    {
        $d = house_dashboard(self::house(), 30, false);
        $this->assertSame(3, $d['created']);
        $this->assertSame(1, $d['resolved']);

        $slug = 'dash-empty-' . bin2hex(random_bytes(3));
        tenant_create(['slug' => $slug, 'name' => 'SVJ Prázdný', 'title' => 'Prázdný']);
        $e = house_dashboard(tenant_pdo($slug), 30, false);
        $this->assertSame(0, $e['open']);
        $this->assertNull($e['avg_days']);
        $this->assertNull($e['react_hours']);
        $this->assertNull($e['next_event']);
    }

    public function testSeriesBuckets(): void
    {
        $pdo = self::house();
        $monday = date('Y-m-d', strtotime('monday this week'));

        $d30 = house_dashboard($pdo, 30);
        $this->assertCount(5, $d30['series']);
        $this->assertSame($monday, end($d30['series'])['from']);
        $this->assertSame(date('j. n.', strtotime($monday)), end($d30['series'])['label']);
        $this->assertSame(3, array_sum(array_column($d30['series'], 'n')));
        $froms = array_column($d30['series'], 'from');
        $sorted = $froms;
        sort($sorted);
        $this->assertSame($sorted, $froms);

        $d90 = house_dashboard($pdo, 90);
        $this->assertCount(13, $d90['series']);
        $this->assertSame(3, array_sum(array_column($d90['series'], 'n')));

        $d365 = house_dashboard($pdo, 365);
        $this->assertCount(12, $d365['series']);
        $this->assertSame(cz_month_short(date('Y-m')), end($d365['series'])['label']);
        $this->assertSame(date('Y-m') . '-01', end($d365['series'])['from']);
        $this->assertSame(4, array_sum(array_column($d365['series'], 'n')), 'T1–T4 v posledních 12 měsících, T5 ne.');
        // Hlášení se započítá do správného týdne/měsíce.
        $t4month = substr(self::at(200), 0, 7) . '-01';
        $this->assertSame(1, array_column($d365['series'], 'n', 'from')[$t4month]);
    }

    public function testUpcomingSortedWithLateFlags(): void
    {
        $d = house_dashboard(self::house(), 90);
        $this->assertSame([
            ['date' => self::day(-5), 'title' => 'Revize: Výtah', 'late' => true],
            ['date' => self::day(-1), 'title' => 'Termín hlášení: T2 po termínu', 'late' => true],
            ['date' => self::day(3), 'title' => 'Shromáždění', 'late' => false],
            ['date' => self::day(10), 'title' => 'Revize: Hasicí přístroje', 'late' => false],
            ['date' => self::day(20), 'title' => 'Odečty', 'late' => false],
            ['date' => self::day(200), 'title' => 'Revize: Komín', 'late' => false],
        ], $d['upcoming']);
        $this->assertSame(['Výtah', 'Hasicí přístroje', 'Komín'], array_column($d['revize_next'], 'title'));
    }

    public function testTimelineExcludesQuietActionsAndFlagsStaff(): void
    {
        $pdo = self::house();
        $d = house_dashboard($pdo, 30, true, [self::$ids['staff']]);
        $whats = array_column($d['timeline'], 'what');
        $this->assertNotContains('login', $whats);
        $this->assertNotContains('partner_login', $whats);
        $this->assertNotContains('Nové hlášení', $whats, 'Činnost obyvatel do osy nepatří.');
        $this->assertContains('Úprava aktuality', $whats);
        $this->assertContains('Nový dokument', $whats);
        $this->assertContains('Hlášení „T2 po termínu“: řešitel', $whats);
        $this->assertContains('Hlášení „T3 vyřešené“: změna stavu', $whats);
        $this->assertNotContains('Hlášení „T5 dávné“: změna stavu', $whats, 'Mimo období.');
        // Nejnovější první.
        $ats = array_column($d['timeline'], 'at');
        $sorted = $ats;
        rsort($sorted);
        $this->assertSame($sorted, $ats);
        $byWhat = array_column($d['timeline'], 'staff', 'what');
        $this->assertTrue($byWhat['Nový dokument']);
        $this->assertTrue($byWhat['Hlášení „T2 po termínu“: řešitel']);
        $this->assertFalse($byWhat['Úprava aktuality']);
        $this->assertSame(2, $d['staff_actions']);

        $none = house_dashboard($pdo, 30);
        $this->assertSame(0, $none['staff_actions']);
    }

    public function testPeriodFromQuery(): void
    {
        $this->assertSame(90, dash_period());
        foreach (['30' => 30, '90' => 90, '365' => 365, '45' => 90, 'abc' => 90, '0' => 90] as $in => $out) {
            $_GET['obdobi'] = (string) $in;
            $this->assertSame($out, dash_period(), "obdobi=$in");
        }
    }

    public function testCzMonthShort(): void
    {
        $this->assertSame('led 26', cz_month_short('2026-01'));
        $this->assertSame('čvn 25', cz_month_short('2025-06'));
        $this->assertSame('čvc 25', cz_month_short('2025-07'));
        $this->assertSame('pro 30', cz_month_short('2030-12'));
    }

    public function testHoursAndDaysWording(): void
    {
        $this->assertSame('—', dash_hours(null));
        $this->assertSame('4,5 h', dash_hours(4.5));
        $this->assertSame('0 h', dash_hours(0.0));
        $this->assertSame('12 h', dash_hours(12.4));
        $this->assertSame('47 h', dash_hours(47.0));
        $this->assertSame('2 dne', dash_hours(48.0));
        $this->assertSame('2,5 dne', dash_hours(60.0));

        $this->assertSame('—', dash_days(null));
        $this->assertSame('1 den', dash_days(1.0));
        $this->assertSame('2 dny', dash_days(2.0));
        $this->assertSame('4 dny', dash_days(4.0));
        $this->assertSame('5 dní', dash_days(5.0));
        $this->assertSame('0 dní', dash_days(0.0));
        $this->assertSame('2,5 dne', dash_days(2.5));
        $this->assertSame('0,5 dne', dash_days(0.5));
    }
}
