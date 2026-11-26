<?php
declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Tests\TestCase;

/** app/ticket_lib.php – historie hlášení, příjemci upozornění, připomínky po termínu. */
final class TicketTest extends TestCase
{
    /** Zachycené e-maily s dekódovaným předmětem a textem. */
    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body'])], $this->mails());
    }

    private function uid(): string
    {
        return bin2hex(random_bytes(4));
    }

    private function adminId(): int
    {
        return (int) val("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
    }

    private function newTicket(array $over = []): array
    {
        ticket_ready();
        $t = $over + ['user_id' => (int) val("SELECT id FROM users WHERE role = 'resident' AND active = 1 ORDER BY id LIMIT 1"), 'subject' => 'Testovací hlášení ' . $this->uid(),
            'location' => 'sklep', 'body' => 'Popis', 'status' => 'new', 'assignee_id' => 0, 'due_date' => ''];
        q('INSERT INTO tickets (user_id, subject, location, body, status, assignee_id, due_date) VALUES (?,?,?,?,?,?,?)',
            [$t['user_id'], $t['subject'], $t['location'], $t['body'], $t['status'], $t['assignee_id'], $t['due_date']]);
        return one('SELECT * FROM tickets WHERE id = ?', [(int) db()->lastInsertId()]);
    }

    public function testTicketReadyAddsColumnsAndLogTableToFreshDatabaseOnce(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, subject TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'new')");
        ticket_ready($pdo);
        $cols = array_column($pdo->query('PRAGMA table_info(tickets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertContains('assignee_id', $cols);
        $this->assertContains('due_date', $cols);
        $logCols = array_column($pdo->query('PRAGMA table_info(ticket_log)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertSame(['id', 'ticket_id', 'at', 'user_id', 'user_name', 'kind', 'text'], $logCols);
        // Opakované volání nic nemění (ALTER by jinak selhal na duplicitním sloupci).
        ticket_ready($pdo);
        $this->assertCount(count($cols), $pdo->query('PRAGMA table_info(tickets)')->fetchAll());
        $pdo->exec("INSERT INTO tickets (subject) VALUES ('x')");
        $this->assertSame(['assignee_id' => 0, 'due_date' => ''], $pdo->query('SELECT assignee_id, due_date FROM tickets')->fetch(PDO::FETCH_ASSOC));
    }

    public function testTicketReadyLeavesDatabaseWithExistingLogAlone(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE tickets (id INTEGER PRIMARY KEY, subject TEXT)');
        $pdo->exec('CREATE TABLE ticket_log (id INTEGER PRIMARY KEY, ticket_id INTEGER)');
        ticket_ready($pdo);
        $cols = array_column($pdo->query('PRAGMA table_info(tickets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertSame(['id', 'subject'], $cols);
    }

    public function testLogAddAndPublicFilter(): void
    {
        $t = $this->newTicket();
        $by = ['id' => $this->adminId(), 'name' => 'Jana Výborová'];
        ticket_log_add((int) $t['id'], 'created', '', $by);
        ticket_log_add((int) $t['id'], 'assign', 'Jana Výborová', $by);
        ticket_log_add((int) $t['id'], 'msg_admin', str_repeat('á', 6000), $by);
        ticket_log_add((int) $t['id'], 'due', '2030-01-01', $by);
        ticket_log_add((int) $t['id'], 'status', 'Řeší se', $by);

        $all = ticket_log([(int) $t['id']]);
        $this->assertSame(['created', 'assign', 'msg_admin', 'due', 'status'], array_column($all[(int) $t['id']], 'kind'));
        $this->assertSame(5000, mb_strlen($all[(int) $t['id']][2]['text']), 'Text se ořízne na 5000 znaků.');
        $this->assertSame('Jana Výborová', $all[(int) $t['id']][0]['user_name']);
        $this->assertSame($by['id'], (int) $all[(int) $t['id']][0]['user_id']);

        $public = ticket_log([(int) $t['id']], true);
        $this->assertSame(['created', 'msg_admin', 'status'], array_column($public[(int) $t['id']], 'kind'));

        $this->assertSame([], ticket_log([]));
        $this->assertSame([], ticket_log([0, 'abc']));
    }

    public function testLogAddUsesPartnerNameFromSession(): void
    {
        $t = $this->newTicket();
        $_SESSION['partner'] = 'Petra (Správa s.r.o.)';
        ticket_log_add((int) $t['id'], 'msg_admin', 'Ahoj', ['id' => 99, 'name' => 'Jiné jméno']);
        ticket_log_add((int) $t['id'], 'reminder', 'Web', ['id' => 0, 'name' => 'Web']);
        unset($_SESSION['partner']);
        $log = ticket_log([(int) $t['id']])[(int) $t['id']];
        $this->assertSame('Petra (Správa s.r.o.)', $log[0]['user_name']);
        $this->assertSame(99, (int) $log[0]['user_id']);
    }

    public function testOverdue(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->assertTrue(ticket_overdue(['due_date' => $yesterday, 'status' => 'new']));
        $this->assertTrue(ticket_overdue(['due_date' => $yesterday, 'status' => 'in_progress']));
        $this->assertFalse(ticket_overdue(['due_date' => $yesterday, 'status' => 'done']));
        $this->assertFalse(ticket_overdue(['due_date' => date('Y-m-d'), 'status' => 'new']));
        $this->assertFalse(ticket_overdue(['due_date' => '', 'status' => 'new']));
        $this->assertFalse(ticket_overdue(['status' => 'new']));
    }

    public function testRecipientsAssigneeOrAllAdmins(): void
    {
        $email = 'druhy.spravce.' . $this->uid() . '@example.org';
        q("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?,'admin')", [$email, 'Druhý Správce', NO_PASSWORD]);
        $second = (int) db()->lastInsertId();
        try {
            $admins = array_map('intval', array_column(all("SELECT id FROM users WHERE role = 'admin' AND active = 1"), 'id'));
            $this->assertGreaterThanOrEqual(2, count($admins));

            $r = ticket_recipients(['assignee_id' => $second]);
            $this->assertCount(1, $r);
            $this->assertSame($email, $r[0]['email']);

            $this->assertEqualsCanonicalizing($admins, array_map('intval', array_column(ticket_recipients(['assignee_id' => 0]), 'id')));
            $this->assertEqualsCanonicalizing($admins, array_map('intval', array_column(ticket_recipients([]), 'id')));
            // Řešitel, který není správce, nebo neaktivní – upozornění dostanou všichni správci.
            $resident = (int) val("SELECT id FROM users WHERE role = 'resident' AND active = 1 ORDER BY id LIMIT 1");
            $this->assertEqualsCanonicalizing($admins, array_map('intval', array_column(ticket_recipients(['assignee_id' => $resident]), 'id')));
            q('UPDATE users SET active = 0 WHERE id = ?', [$second]);
            $r = array_map('intval', array_column(ticket_recipients(['assignee_id' => $second]), 'id'));
            $this->assertNotContains($second, $r);
            $this->assertContains($this->adminId(), $r);
        } finally {
            q('UPDATE users SET active = 0 WHERE id = ?', [$second]);
        }
    }

    public function testNotifyAdminsSendsToAllAdminsOrOnlyAssignee(): void
    {
        $admin = one('SELECT * FROM users WHERE id = ?', [$this->adminId()]);
        $email = 'resitel.' . $this->uid() . '@example.org';
        q("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?,'admin')", [$email, 'Řešitel', NO_PASSWORD]);
        $second = (int) db()->lastInsertId();
        try {
            $t = $this->newTicket();
            ticket_notify_admins($t, 'Nové hlášení', 'Teče voda.');
            $mine = array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $t['subject'])));
            $this->assertEqualsCanonicalizing([$admin['email'], $email], array_column($mine, 'to'));
            $this->assertSame('Nové hlášení: ' . $t['subject'] . ' – ' . site('name'), $mine[0]['subject']);
            $this->assertStringContainsString('Teče voda.', $mine[0]['body']);
            $this->assertStringContainsString($t['subject'] . ' (sklep)', $mine[0]['body']);
            $this->assertStringContainsString('/admin/zavady#z' . $t['id'], $mine[0]['body']);

            $t2 = $this->newTicket(['assignee_id' => $second]);
            ticket_notify_admins($t2, 'Odpověď obyvatele', 'Pořád teče.');
            $mine = array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $t2['subject'])));
            $this->assertSame([$email], array_column($mine, 'to'));
        } finally {
            q('UPDATE users SET active = 0 WHERE id = ?', [$second]);
        }
    }

    public function testNotifyAdminsIncludesPartnerStaffWithNotificationsOn(): void
    {
        $orig = tenant_row('dum');
        pq("INSERT INTO partners (name, email, created_at) VALUES (?,?,?)", ['Správa Ticket ' . $this->uid(), 'firma@example.org', date('Y-m-d H:i:s')]);
        $pid = (int) platform_db()->lastInsertId();
        $on = 'pracovnik.on.' . $this->uid() . '@example.org';
        $off = 'pracovnik.off.' . $this->uid() . '@example.org';
        $gone = 'pracovnik.gone.' . $this->uid() . '@example.org';
        pq('INSERT INTO partner_users (partner_id, name, email, notify_tickets, active, created_at) VALUES (?,?,?,?,?,?)', [$pid, 'On', $on, 1, 1, date('Y-m-d H:i:s')]);
        pq('INSERT INTO partner_users (partner_id, name, email, notify_tickets, active, created_at) VALUES (?,?,?,?,?,?)', [$pid, 'Off', $off, 0, 1, date('Y-m-d H:i:s')]);
        pq('INSERT INTO partner_users (partner_id, name, email, notify_tickets, active, created_at) VALUES (?,?,?,?,?,?)', [$pid, 'Gone', $gone, 1, 0, date('Y-m-d H:i:s')]);
        try {
            pq("UPDATE tenants SET partner_id = ?, partner_status = 'pending' WHERE slug = 'dum'", [$pid]);
            $this->assertSame([], ticket_partner_emails(), 'Čekající žádost partnera upozornění nedostává.');

            pq("UPDATE tenants SET partner_id = ?, partner_status = 'active' WHERE slug = 'dum'", [$pid]);
            $this->assertSame([$on], ticket_partner_emails());

            $t = $this->newTicket();
            ticket_notify_admins($t, 'Nové hlášení', 'Text');
            $to = array_column(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $t['subject'])), 'to');
            $this->assertContains(strtolower($on), $to);
            $this->assertNotContains($off, $to);
            $this->assertNotContains($gone, $to);
            $this->assertContains(one('SELECT email FROM users WHERE id = ?', [$this->adminId()])['email'], $to);

            // Přiřazené hlášení: jen řešitel, pracovníci partnera ne.
            $t2 = $this->newTicket(['assignee_id' => $this->adminId()]);
            ticket_notify_admins($t2, 'Nové hlášení', 'Text');
            $to = array_column(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $t2['subject'])), 'to');
            $this->assertNotContains(strtolower($on), $to);
            $this->assertCount(1, $to);
        } finally {
            pq('UPDATE tenants SET partner_id = ?, partner_status = ? WHERE slug = ?', [$orig['partner_id'], $orig['partner_status'], 'dum']);
        }
    }

    public function testNotifyAuthor(): void
    {
        $resident = one("SELECT * FROM users WHERE role = 'resident' AND active = 1 ORDER BY id LIMIT 1");
        $t = $this->newTicket(['user_id' => (int) $resident['id'], 'status' => 'in_progress']);
        ticket_notify_author($t, 'Technik přijde zítra.');
        $mine = array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $t['subject'])));
        $this->assertCount(1, $mine);
        $this->assertSame($resident['email'], $mine[0]['to']);
        $this->assertSame('Odpověď na Vaše hlášení: ' . $t['subject'] . ' – ' . site('name'), $mine[0]['subject']);
        $this->assertStringContainsString('Technik přijde zítra.', $mine[0]['body']);
        $this->assertStringContainsString('Stav: řeší se', $mine[0]['body']);
        $this->assertStringContainsString('/zavady#z' . $t['id'], $mine[0]['body']);

        // Bez autora (anonymní hlášení) se nic neposílá.
        $t0 = $this->newTicket(['user_id' => null]);
        $t0['user_id'] = 0;
        ticket_notify_author($t0, 'x');
        $this->assertCount(0, array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $t0['subject'])));
    }

    public function testDailyReminderOncePerDayPerLateTicket(): void
    {
        $admin = one('SELECT * FROM users WHERE id = ?', [$this->adminId()]);
        $late = $this->newTicket(['assignee_id' => (int) $admin['id'], 'due_date' => date('Y-m-d', strtotime('-3 day'))]);
        $doneLate = $this->newTicket(['status' => 'done', 'due_date' => date('Y-m-d', strtotime('-3 day'))]);
        $future = $this->newTicket(['due_date' => date('Y-m-d', strtotime('+3 day'))]);
        try {
            ticket_daily();
            $mails = $this->decodedMails();
            $mine = array_values(array_filter($mails, fn($m) => str_contains($m['subject'], $late['subject'])));
            $this->assertCount(1, $mine);
            $this->assertSame($admin['email'], $mine[0]['to']);
            $this->assertSame('Po termínu: ' . $late['subject'] . ' – ' . site('name'), $mine[0]['subject']);
            $this->assertStringContainsString('mělo být vyřešené do ' . cz_date($late['due_date']), $mine[0]['body']);
            $this->assertCount(0, array_filter($mails, fn($m) => str_contains($m['subject'], $doneLate['subject']) || str_contains($m['subject'], $future['subject'])));

            $log = ticket_log([(int) $late['id']])[(int) $late['id']];
            $this->assertSame('reminder', $log[0]['kind']);
            $this->assertSame('Web', $log[0]['user_name']);
            $this->assertSame(0, (int) $log[0]['user_id']);

            // Druhý běh týž den už nic neposílá.
            ticket_daily();
            $this->assertCount(1, array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $late['subject'])));
            $this->assertCount(1, ticket_log([(int) $late['id']])[(int) $late['id']]);

            // Připomínka ze včerejška nebrání dnešní.
            q("UPDATE ticket_log SET at = ? WHERE ticket_id = ? AND kind = 'reminder'", [date('Y-m-d 23:59:59', strtotime('-1 day')), $late['id']]);
            ticket_daily();
            $this->assertCount(2, array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $late['subject'])));
        } finally {
            q("UPDATE tickets SET status = 'done' WHERE id IN (?,?,?)", [$late['id'], $doneLate['id'], $future['id']]);
        }
    }

    public function testLogLabel(): void
    {
        $expect = ['created' => 'nahlásil(a)', 'status' => 'změna stavu', 'assign' => 'řešitel', 'due' => 'termín', 'supplier' => 'dodavatel', 'edit' => 'úprava textu',
            'msg_admin' => 'zpráva obyvateli', 'msg_author' => 'odpověď obyvatele', 'reminder' => 'připomínka', 'neznamy' => 'neznamy'];
        foreach ($expect as $kind => $label) {
            $this->assertSame($label, ticket_log_label(['kind' => $kind]));
        }
    }
}
