<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/** app/partner_lib.php – správcovské firmy: domy, souhlas výboru, vstup do administrace, společná faktura, kalendář, šablony, faktury SVJ. */
final class PartnerTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $_GET = [];
    }

    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body'])], $this->mails());
    }

    private function mailsTo(string $to): array
    {
        return array_values(array_filter($this->decodedMails(), fn($m) => strtolower($m['to']) === strtolower($to)));
    }

    private function uid(): string
    {
        return bin2hex(random_bytes(4));
    }

    private function partner(array $over = []): array
    {
        $u = $this->uid();
        $p = $over + ['name' => 'Správa ' . $u . ' s.r.o.', 'ico' => '', 'dic' => '', 'address' => '', 'email' => 'firma.' . $u . '@example.org', 'phone' => '', 'active' => 1];
        pq('INSERT INTO partners (name, ico, dic, address, email, phone, active, created_at) VALUES (?,?,?,?,?,?,?,?)', [$p['name'], $p['ico'], $p['dic'], $p['address'], $p['email'], $p['phone'], $p['active'], date('Y-m-d H:i:s')]);
        $id = (int) platform_db()->lastInsertId();
        foreach (array_diff_key($over, array_flip(['name', 'ico', 'dic', 'address', 'email', 'phone', 'active'])) as $k => $v) {
            pq("UPDATE partners SET \"$k\" = ? WHERE id = ?", [$v, $id]);
        }
        return partner_row($id);
    }

    private function house(string $name = '', array $over = []): array
    {
        $slug = 'p-' . $this->uid();
        tenant_create($over + ['slug' => $slug, 'name' => $name !== '' ? $name : 'SVJ ' . $slug, 'title' => $slug]);
        return tenant_row($slug);
    }

    private function attach(array $p, array $t, string $status = 'active', int $billing = 1): array
    {
        pq('UPDATE tenants SET partner_id = ?, partner_status = ?, partner_billing = ? WHERE slug = ?', [$p['id'], $status, $billing, $t['slug']]);
        return tenant_row($t['slug']);
    }

    private function worker(array $p, string $name = 'Petra Dvořáková'): array
    {
        $email = 'pracovnik.' . $this->uid() . '@example.org';
        pq('INSERT INTO partner_users (partner_id, name, email, created_at) VALUES (?,?,?,?)', [$p['id'], $name, $email, date('Y-m-d H:i:s')]);
        return pq('SELECT * FROM partner_users WHERE id = ?', [(int) platform_db()->lastInsertId()])->fetch();
    }

    private function psetTemp(string $key, string $value): \Closure
    {
        $had = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
        pset_save($key, $value);
        return function () use ($key, $had) {
            if ($had === false) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
                pset('__reset');
            } else {
                pset_save($key, (string) $had);
            }
        };
    }

    private function tmp(string $content, string $suffix = ''): string
    {
        $f = tempnam(sys_get_temp_dir(), 'svjp') . $suffix;
        file_put_contents($f, $content);
        $this->tmpFiles[] = $f;
        return $f;
    }

    // ---------- Sleva ----------

    public function testDiscountTiersParsing(): void
    {
        $restore = $this->psetTemp('partner_discounts', "10 : 12\nnesmysl\n3:5\n1:2\n7:100\n  ");
        try {
            $this->assertSame([1 => 2, 3 => 5, 10 => 12], partner_discount_tiers(), 'Seřazené, neplatné řádky a víc než 2 číslice se vynechají.');
            $this->assertSame(0, partner_discount(0));
            $this->assertSame(2, partner_discount(1));
            $this->assertSame(2, partner_discount(2));
            $this->assertSame(5, partner_discount(3));
            $this->assertSame(5, partner_discount(9));
            $this->assertSame(12, partner_discount(10));
            $this->assertSame(12, partner_discount(500));
        } finally {
            $restore();
        }
    }

    public function testDiscountDefaultTiers(): void
    {
        $restore = $this->psetTemp('partner_discounts', '');
        try {
            $this->assertSame([5 => 10, 20 => 15, 50 => 20], partner_discount_tiers());
            $this->assertSame(0, partner_discount(4));
            $this->assertSame(10, partner_discount(5));
            $this->assertSame(10, partner_discount(19));
            $this->assertSame(15, partner_discount(20));
            $this->assertSame(20, partner_discount(50));
        } finally {
            $restore();
        }
    }

    // ---------- Pozvánka, domy, souhlas výboru ----------

    public function testInviteCreatesUserAndMailsLink(): void
    {
        $p = $this->partner();
        $email = 'novy.' . $this->uid() . '@example.org';
        $link = partner_invite($p, 'Nový Pracovník', $email, 'admin');
        $this->assertMatchesRegularExpression('~/partner/pozvanka/([a-f0-9]{64})$~', $link);
        preg_match('~([a-f0-9]{64})$~', $link, $m);
        $u = pq('SELECT * FROM partner_users WHERE email = ?', [$email])->fetch();
        $this->assertSame((int) $p['id'], (int) $u['partner_id']);
        $this->assertSame('member', $u['role'], 'Jiná role než owner = member.');
        $this->assertSame(hash('sha256', $m[1]), $u['invite_hash']);
        $this->assertSame('!', $u['password_hash']);
        $exp = strtotime($u['invite_expires']);
        $this->assertEqualsWithDelta(time() + 7 * 86400, $exp, 5);
        $mail = $this->mailsTo($email);
        $this->assertCount(1, $mail);
        $this->assertSame('Přístup do partnerského portálu – ' . $p['name'], $mail[0]['subject']);
        $this->assertStringContainsString($link, $mail[0]['body']);

        $owner = 'majitel.' . $this->uid() . '@example.org';
        partner_invite($p, 'Majitel', $owner, 'owner');
        $this->assertSame('owner', pq('SELECT role FROM partner_users WHERE email = ?', [$owner])->fetchColumn());
    }

    public function testAttachCreatedAndPartnerTenants(): void
    {
        $p = $this->partner();
        $b = $this->house('SVJ Bříza');
        $a = $this->house('SVJ Akát');
        $c = $this->house('SVJ Cedr');
        partner_attach_created($p, $b['slug']);
        partner_attach_created($p, $a['slug']);
        $this->attach($p, $c, 'pending', 0);
        $row = tenant_row($b['slug']);
        $this->assertSame((int) $p['id'], (int) $row['partner_id']);
        $this->assertSame('active', $row['partner_status']);
        $this->assertSame(1, (int) $row['partner_billing']);
        $this->assertNotSame('', $row['partner_since']);

        $this->assertSame(['SVJ Akát', 'SVJ Bříza'], array_column(partner_tenants((int) $p['id']), 'name'));
        $this->assertSame(['SVJ Akát', 'SVJ Bříza', 'SVJ Cedr'], array_column(partner_tenants((int) $p['id'], ''), 'name'));
        $this->assertSame(['SVJ Cedr'], array_column(partner_tenants((int) $p['id'], 'pending'), 'name'));
        $this->assertSame([], partner_tenants((int) $this->partner()['id']));
    }

    public function testRequestChecksIcoAndState(): void
    {
        $p = $this->partner(['ico' => '11223344']);
        $other = $this->partner();
        $owner = 'vybor.' . $this->uid() . '@example.org';
        $t = $this->house('SVJ Žádost', ['owner_email' => $owner]);
        tenant_set($t['slug'], 'ico', '123 45 678');

        $this->assertSame('Web s touto adresou neexistuje.', partner_request($p, 'neexistuje-' . $this->uid(), ''));
        $demo = $this->house('SVJ Ukázka', ['demo' => 2]);
        $this->assertSame('Web s touto adresou neexistuje.', partner_request($p, $demo['slug'], ''));

        $this->assertStringStartsWith('IČO nesouhlasí', (string) partner_request($p, $t['slug'], '87654321'));
        $this->assertStringStartsWith('IČO nesouhlasí', (string) partner_request($p, $t['slug'], ''));
        $this->assertSame(0, (int) tenant_row($t['slug'])['partner_id']);

        $this->assertNull(partner_request($p, $t['slug'], '12345678'), 'IČO se porovnává jen podle číslic.');
        $row = tenant_row($t['slug']);
        $this->assertSame((int) $p['id'], (int) $row['partner_id']);
        $this->assertSame('pending', $row['partner_status']);
        $this->assertSame(0, (int) $row['partner_billing']);
        $mail = $this->mailsTo($owner);
        $this->assertCount(1, $mail);
        $this->assertSame('Žádost o přístup správce domu – SVJ Žádost', $mail[0]['subject']);
        $this->assertStringContainsString($p['name'] . ' (IČO 11223344)', $mail[0]['body']);
        $this->assertStringContainsString('/admin/spravce-domu', $mail[0]['body']);

        $this->assertSame('Žádost už čeká na schválení výborem.', partner_request($p, $t['slug'], '12345678'));
        // Čekající žádost jiné firmy jde přepsat, aktivní správce ne.
        $this->assertNull(partner_request($other, $t['slug'], '12345678'));
        $this->assertSame((int) $other['id'], (int) tenant_row($t['slug'])['partner_id']);
        partner_approve(tenant_row($t['slug']), false, 'Výbor');
        $this->assertSame('Tento dům už spravuje jiný správce. Výbor ho musí nejdřív odebrat.', partner_request($p, $t['slug'], '12345678'));
        $this->assertSame('Tento dům už spravujete.', partner_request($other, $t['slug'], '12345678'));
    }

    public function testRequestWithoutKnownIcoAndWithoutOwnerMail(): void
    {
        $p = $this->partner();
        $t = $this->house();
        $before = count($this->mails());
        $this->assertNull(partner_request($p, $t['slug'], 'cokoli'));
        $this->assertSame('pending', tenant_row($t['slug'])['partner_status']);
        $this->assertCount($before, $this->mails(), 'Bez e-mailu kontaktní osoby se nic neposílá.');
    }

    public function testApproveSetsBillingAndMailsPartner(): void
    {
        $p = $this->partner();
        $t = $this->attach($p, $this->house('SVJ Schválení'), 'pending', 0);
        partner_approve($t, true, 'Jana');
        $row = tenant_row($t['slug']);
        $this->assertSame('active', $row['partner_status']);
        $this->assertSame(1, (int) $row['partner_billing']);
        $mail = $this->mailsTo($p['email']);
        $this->assertCount(1, $mail);
        $this->assertSame('Výbor schválil přístup – SVJ Schválení', $mail[0]['subject']);
        $this->assertStringContainsString('společné faktuře', $mail[0]['body']);

        $t2 = $this->attach($p, $this->house('SVJ Bez placení'), 'pending', 1);
        partner_approve($t2, false, 'Jana');
        $this->assertSame(0, (int) tenant_row($t2['slug'])['partner_billing']);
        $this->assertStringNotContainsString('společné faktuře', $this->mailsTo($p['email'])[1]['body']);
    }

    public function testRevokeDisablesPartnerAccountsInHouse(): void
    {
        $p = $this->partner();
        $t = $this->attach($p, $this->house('SVJ Odebrání'));
        $pu = $this->worker($p);
        $this->assertArrayHasKey('link', partner_enter_link($pu, $p, $t));
        $uid = (int) array_values(json_decode(tenant_settings($t['slug'])['partner_users'], true))[0];
        $pdo = tenant_pdo($t['slug']);
        $this->assertSame(1, (int) $pdo->query("SELECT active FROM users WHERE id = $uid")->fetchColumn());

        partner_revoke(tenant_row($t['slug']), 'Výbor');
        $this->assertSame(0, (int) $pdo->query("SELECT active FROM users WHERE id = $uid")->fetchColumn());
        $this->assertSame('', tenant_settings($t['slug'])['partner_users']);
        $row = tenant_row($t['slug']);
        $this->assertSame([0, '', 0, ''], [(int) $row['partner_id'], $row['partner_status'], (int) $row['partner_billing'], $row['partner_since']]);
        $mail = $this->mailsTo($p['email']);
        $this->assertCount(1, $mail);
        $this->assertSame('Přístup k webu ukončen – SVJ Odebrání', $mail[0]['subject']);
    }

    /** Bezpečnost 11/2026: vypnutý pracovník už nesmí do domů – ani přes „zapomenuté heslo“ či pozvánku. */
    public function testDisabledWorkerLosesHouseAccountsAndCannotUseLocalLogin(): void
    {
        $p = $this->partner();
        $t = $this->attach($p, $this->house('SVJ Vypnutí'));
        $t2 = $this->attach($p, $this->house('SVJ Vypnutí 2'));
        $pu = $this->worker($p);
        partner_enter_link($pu, $p, $t);
        partner_enter_link($pu, $p, $t2);
        $this->assertSame(2, partner_worker_disable_houses($p, strtoupper($pu['email'])));
        foreach ([$t, $t2] as $house) {
            $uid = (int) array_values(json_decode(tenant_settings($house['slug'])['partner_users'], true))[0];
            $this->assertSame(0, (int) tenant_pdo($house['slug'])->query("SELECT active FROM users WHERE id = $uid")->fetchColumn());
        }
        $this->assertSame(1, (int) json_decode(tenant_settings($t['slug'])['partner_login'], true)['pu'] > 0 ? 1 : 0, 'odkaz nese id pracovníka');

        // Ve webu domu (zde testovací dům): účet namapovaný na pracovníka partnera.
        $uid = (int) val("SELECT id FROM users WHERE role = 'admin' ORDER BY id DESC LIMIT 1");
        $prev = setting('partner_users');
        q("DELETE FROM settings WHERE `key` = 'partner_users'");
        q('INSERT INTO settings (`key`, value) VALUES (?,?)', ['partner_users', json_encode(['x@example.org' => $uid])]);
        setting('__reset');
        try {
            unset($_SESSION['partner_pu']);
            $this->assertFalse(partner_session_ok($uid), 'bez vstupu z portálu nefunguje');
            $this->assertTrue(partner_session_ok($uid + 100000), 'běžné účty se netýká');
            $_SESSION['partner_pu'] = (int) $pu['id'];
            $this->assertTrue(partner_session_ok($uid));
            $gone = $this->worker($p, 'Bývalý pracovník');
            pq('UPDATE partner_users SET active = 0 WHERE id = ?', [$gone['id']]);
            $_SESSION['partner_pu'] = (int) $gone['id'];
            $this->assertFalse(partner_session_ok($uid), 'vypnutý pracovník – ani otevřená relace už neplatí');
            $before = count($this->mails());
            $this->assertTrue(send_password_reset(one('SELECT * FROM users WHERE id = ?', [$uid])), 'stejná odpověď jako u jiných účtů');
            $this->assertSame([false, ''], send_account_invite(one('SELECT * FROM users WHERE id = ?', [$uid])));
            $this->assertCount($before, $this->mails(), 'žádný e-mail s heslem ani pozvánkou');
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/lib.php');
            $this->assertStringContainsString("!partner_session_ok((int) \$u['id'])", $src, 'kontrola v user() – platí pro heslo, odkaz i SSO');
        } finally {
            unset($_SESSION['partner_pu']);
            q("DELETE FROM settings WHERE `key` = 'partner_users'");
            if ($prev !== '') {
                q('INSERT INTO settings (`key`, value) VALUES (?,?)', ['partner_users', $prev]);
            }
            setting('__reset');
        }
        $portal = (string) file_get_contents(dirname(__DIR__, 2) . '/app/partner_portal.php');
        $this->assertStringContainsString('partner_worker_disable_houses($partner', $portal, 'vypnutí v portálu vypne i účty v domech');
    }

    // ---------- Vstup do administrace domu ----------

    public function testEnterLinkCreatesPasswordlessAdminAndStoresNext(): void
    {
        $p = $this->partner(['name' => 'Firma Vstup']);
        $foreign = $this->partner();
        $t = $this->attach($p, $this->house());
        $pu = $this->worker($p, 'Petra Dvořáková');

        $this->assertSame(['error' => 'K tomuto domu nemáte přístup.'], partner_enter_link($pu, $foreign, $t));
        $pending = $this->attach($p, $this->house(), 'pending');
        $this->assertSame(['error' => 'K tomuto domu nemáte přístup.'], partner_enter_link($pu, $p, $pending));

        $r = partner_enter_link($pu, $p, $t, '/admin/zavady');
        $this->assertMatchesRegularExpression('~/spravce-vstup/([a-f0-9]{64})$~', $r['link']);
        $this->assertStringStartsWith(tenant_url($t, '/spravce-vstup/'), $r['link']);
        preg_match('~([a-f0-9]{64})$~', $r['link'], $m);

        $pdo = tenant_pdo($t['slug']);
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$pu['email']]);
        $u = $st->fetch();
        $this->assertSame('Petra Dvořáková (Firma Vstup)', $u['name']);
        $this->assertSame('admin', $u['role']);
        $this->assertSame(NO_PASSWORD, $u['password_hash']);
        $s = tenant_settings($t['slug']);
        $this->assertSame([strtolower($pu['email']) => (int) $u['id']], json_decode($s['partner_users'], true));
        $login = json_decode($s['partner_login'], true);
        $this->assertSame(hash('sha256', $m[1]), $login['hash']);
        $this->assertSame((int) $u['id'], $login['uid']);
        $this->assertSame('/admin/zavady', $login['next']);
        $this->assertSame('Petra Dvořáková (Firma Vstup)', $login['by']);
        $this->assertEqualsWithDelta(time() + 120, $login['expires'], 5);

        // Druhý vstup: stejný účet (znovu aktivní), nový jednorázový kód.
        $pdo->exec('UPDATE users SET active = 0, role = \'resident\' WHERE id = ' . (int) $u['id']);
        $r2 = partner_enter_link($pu, $p, $t);
        $this->assertNotSame($r['link'], $r2['link']);
        $st->execute([$pu['email']]);
        $this->assertCount(1, $st->fetchAll());
        $again = $pdo->query('SELECT * FROM users WHERE id = ' . (int) $u['id'])->fetch();
        $this->assertSame([1, 'admin'], [(int) $again['active'], $again['role']]);
        $this->assertSame('', json_decode(tenant_settings($t['slug'])['partner_login'], true)['next']);
    }

    public function testEnterLinkRefusesForeignAccountWithSameEmail(): void
    {
        $p = $this->partner();
        $t = $this->attach($p, $this->house());
        $pu = $this->worker($p);
        tenant_pdo($t['slug'])->prepare("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?,'resident')")->execute([$pu['email'], 'Obyvatel', NO_PASSWORD]);
        $r = partner_enter_link($pu, $p, $t);
        $this->assertArrayHasKey('error', $r);
        $this->assertStringStartsWith('Ve webu tohoto domu už existuje jiný účet s e-mailem ' . $pu['email'], $r['error']);
        $this->assertArrayNotHasKey('partner_login', tenant_settings($t['slug']));
    }

    public function testNextFromQueryAndWhitelist(): void
    {
        $this->assertSame('', partner_next());
        $_GET = ['zavada' => '12'];
        $this->assertSame('/admin/zavady?f=vse#z12', partner_next());
        $_GET = ['zavada' => '12x', 'k' => 'revize'];
        $this->assertSame('/admin/revize', partner_next());
        $_GET = ['k' => 'prehled'];
        $this->assertSame('/admin/prehled-spravy', partner_next());
        $_GET = ['k' => 'zavady'];
        $this->assertSame('/admin/zavady', partner_next());
        $_GET = ['k' => 'uzivatele'];
        $this->assertSame('', partner_next());

        foreach (['/admin', '/admin/zavady', '/admin/zavady?f=vse', '/admin/zavady#z5', '/admin/zavady?f=vse#z12', '/admin/prehled-spravy', '/admin/revize'] as $ok) {
            $this->assertTrue(partner_next_ok($ok), $ok);
        }
        foreach (['', '/', '/admin/', '/admin/uzivatele', '//evil.example/admin', 'https://evil.example/admin', '/admin/zavady#z5x', '/admin/revize/../uzivatele'] as $bad) {
            $this->assertFalse(partner_next_ok($bad), $bad);
        }
    }

    public function testNextWhitelistRejectsTrailingNewline(): void
    {
        $this->assertFalse(partner_next_ok("/admin\n"));
        $this->assertFalse(partner_next_ok("/admin/revize\n"));
    }

    // ---------- Společná faktura ----------

    public function testOrderConfirmInvoiceAndStorno(): void
    {
        $restoreTiers = $this->psetTemp('partner_discounts', '2:10');
        $restoreAcc = $this->psetTemp('account', '19-2000145399/0800');
        try {
            $p = $this->partner(['name' => 'Firma Faktura', 'ico' => '12345678', 'address' => 'Hlavní 1']);
            $h1 = $this->attach($p, $this->house('SVJ Jedna'));
            $h2 = $this->attach($p, $this->house('SVJ Dva'));
            $h3 = $this->attach($p, $this->house('SVJ Tři'), 'active', 0);   // platí si samo
            $foreign = $this->house('SVJ Cizí');
            $std = edition_price('standard');
            $prem = edition_price('premium');

            $first = partner_order($p, [$h1['slug'] => 'standard']);
            $this->assertIsArray($first);
            $this->assertSame('partner', $first['kind']);
            $this->assertSame('partner:' . $p['id'], $first['tenant']);
            $this->assertSame(date('y') . sprintf('%06d', $first['id']), $first['vs']);
            $this->assertSame($std - (int) round($std * 0.1), (int) $first['amount'], '3 domy partnera → sleva 10 %.');

            $pay = partner_order($p, [$h1['slug'] => 'standard', $h2['slug'] => 'premium', $h3['slug'] => 'standard', $foreign['slug'] => 'standard', 'neexistuje' => 'premium']);
            $this->assertSame($first['id'], $pay['id'], 'Otevřená výzva se přepíše, nevzniká druhá.');
            $discount = (int) round(($std + $prem) * 10 / 100);
            $this->assertSame($discount, (int) $pay['discount']);
            $this->assertSame($std + $prem - $discount, (int) $pay['amount']);
            $this->assertSame([
                ['slug' => $h1['slug'], 'name' => 'SVJ Jedna', 'edition' => 'standard', 'price' => $std],
                ['slug' => $h2['slug'], 'name' => 'SVJ Dva', 'edition' => 'premium', 'price' => $prem],
            ], partner_lines($pay));
            $this->assertSame($pay['id'], partner_open_payment((int) $p['id'])['id']);

            // Zálohová faktura s QR kódem.
            $v = partner_invoice_view($pay);
            $this->assertFalse($v['paid']);
            $this->assertSame(substr($pay['created_at'], 0, 10), $v['issued']);
            $this->assertSame(date('Y-m-d', strtotime($v['issued'] . ' +' . pset_int('invoice_due_days', 0, 90) . ' day')), $v['due']);
            $this->assertSame(['Web společenství SVJ Jedna – edice Standard na 1 rok', $std], $v['lines'][0]);
            $this->assertSame(['Množstevní sleva správce', -$discount], end($v['lines']));
            $this->assertSame('Firma Faktura', $v['parties']['customer']['name']);
            $this->assertStringContainsString('<svg', $v['qr']);

            // Potvrzení platby: oba domy prodlouží o rok.
            $period = partner_billing_confirm($pay, 'Admin');
            $today = date('Y-m-d');
            $to = date('Y-m-d', strtotime($today . ' +12 month -1 day'));
            $this->assertSame(['from' => $today, 'to' => $to], $period);
            foreach ([[$h1, 'standard'], [$h2, 'premium']] as [$h, $ed]) {
                $row = tenant_row($h['slug']);
                $this->assertSame([$to, $ed, 1], [$row['paid_until'], $row['plan'], (int) $row['billing']]);
            }
            $this->assertSame('', tenant_row($h3['slug'])['paid_until']);
            $paid = pq('SELECT * FROM payments WHERE id = ?', [$pay['id']])->fetch();
            $this->assertSame('paid', $paid['status']);
            $this->assertMatchesRegularExpression('/^' . date('Y') . '\d{4}$/', $paid['invoice_no']);
            $this->assertSame('Admin', $paid['confirmed_by']);
            $this->assertSame([$today, $to], [$paid['period_from'], $paid['period_to']]);
            $this->assertSame('', partner_lines($paid)[0]['prev_until']);
            $mail = $this->mailsTo($p['email']);
            $this->assertCount(1, $mail);
            $this->assertSame('Platba přijata – faktura ' . $paid['invoice_no'], $mail[0]['subject']);
            $this->assertStringContainsString('2 domů', $mail[0]['body']);
            $this->assertNull(partner_billing_confirm($paid, 'Admin'), 'Zaplacenou platbu nejde potvrdit znovu.');
            $this->assertNull(partner_open_payment((int) $p['id']));

            $v = partner_invoice_view($paid);
            $this->assertTrue($v['paid']);
            $this->assertSame('', $v['qr']);
            $this->assertSame('Web společenství SVJ Dva – edice Premium v období ' . cz_date($today) . ' – ' . cz_date($to), $v['lines'][1][0]);

            // Storno: dům, jehož předplatné se mezitím změnilo, zůstane.
            pq("UPDATE tenants SET paid_until = '2099-01-01' WHERE slug = ?", [$h2['slug']]);
            $this->assertSame(['SVJ Jedna: předplatné vráceno na edici Zdarma'], partner_storno_revert($paid));
            $this->assertSame(['', ''], [tenant_row($h1['slug'])['paid_until'], tenant_row($h1['slug'])['plan']]);
            $this->assertSame('2099-01-01', tenant_row($h2['slug'])['paid_until']);

            $paid['status'] = 'cancelled';
            $this->assertNull(partner_invoice_view($paid));
        } finally {
            $restoreAcc();
            $restoreTiers();
        }
    }

    public function testStornoRestoresPreviousPeriod(): void
    {
        $t = $this->house('SVJ Storno');
        pq("UPDATE tenants SET paid_until = '2030-05-31', plan = 'premium' WHERE slug = ?", [$t['slug']]);
        $notes = partner_storno_revert(['item' => json_encode([['slug' => $t['slug'], 'name' => 'SVJ Storno', 'to' => '2030-05-31', 'prev_until' => '2029-05-31', 'prev_plan' => 'standard'],
            ['slug' => $t['slug'], 'name' => 'Bez období']])]);
        $this->assertSame(['SVJ Storno: předplatné vráceno do ' . cz_date('2029-05-31')], $notes);
        $this->assertSame(['2029-05-31', 'standard'], [tenant_row($t['slug'])['paid_until'], tenant_row($t['slug'])['plan']]);
    }

    public function testOrderRefusals(): void
    {
        $p = $this->partner();
        $h = $this->attach($p, $this->house());
        $this->assertSame('Vyberte aspoň jeden dům a edici.', partner_order($p, []));
        $this->assertSame('Vyberte aspoň jeden dům a edici.', partner_order($p, [$h['slug'] => 'free']));
        $pay = partner_order($p, [$h['slug'] => 'standard']);
        pq("UPDATE payments SET status = 'claimed' WHERE id = ?", [$pay['id']]);
        $this->assertSame('Platbu za předchozí fakturu už jste nahlásili jako odeslanou. Novou vystavíte, až ji potvrdíme.', partner_order($p, [$h['slug'] => 'premium']));
        pq("UPDATE payments SET status = 'cancelled' WHERE id = ?", [$pay['id']]);

        // Na nižší edici nejde přejít před koncem zaplaceného období.
        pq("UPDATE tenants SET billing = 1, paid_until = ?, plan = 'premium' WHERE slug = ?", [date('Y-m-d', strtotime('+100 day')), $h['slug']]);
        $r = partner_order($p, [$h['slug'] => 'standard']);
        $this->assertIsString($r);
        $this->assertStringStartsWith($h['name'] . ': Edici Premium máte zaplacenou do', $r);
    }

    // ---------- Registrační odkaz, logo, značka ----------

    public function testRefCodeAndLookup(): void
    {
        $p = $this->partner(['name' => 'Acme Sprava Domu Brno']);
        $code = partner_ref_code($p);
        $this->assertMatchesRegularExpression('/^acmespravado-[a-f0-9]{6}$/', $code);
        $p = partner_row((int) $p['id']);
        $this->assertSame($code, $p['ref_code']);
        $this->assertSame($code, partner_ref_code($p), 'Kód se vytvoří jen jednou.');
        $this->assertSame((int) $p['id'], (int) partner_by_code($code)['id']);
        $this->assertNull(partner_by_code('ACME!'));
        $this->assertNull(partner_by_code('abc'));
        $this->assertNull(partner_by_code('neexistuje-000000'));

        $cz = $this->partner(['name' => 'Správa Žluťoučký kůň']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{0,12}-[a-f0-9]{6}$/', partner_ref_code($cz));

        pq('UPDATE partners SET active = 0 WHERE id = ?', [$p['id']]);
        $this->assertNull(partner_by_code($code), 'Neaktivní firma.');
    }

    public function testLogoSaveValidation(): void
    {
        $p = $this->partner();
        $png = $this->tmp(base64_decode(self::PNG));
        $txt = $this->tmp('není obrázek');

        $this->assertSame('Logo se nepodařilo nahrát.', partner_logo_save($p, ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => $png, 'size' => 10]));
        $this->assertSame('Logo se nepodařilo nahrát.', partner_logo_save($p, []));
        $this->assertSame('Logo musí být obrázek PNG nebo JPG do 200 kB.', partner_logo_save($p, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $txt, 'size' => filesize($txt)]));
        $this->assertSame('Logo musí být obrázek PNG nebo JPG do 200 kB.', partner_logo_save($p, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'size' => 200001]));
        $this->assertSame('', partner_row((int) $p['id'])['logo']);

        $this->assertNull(partner_logo_save($p, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'size' => filesize($png)]));
        $p = partner_row((int) $p['id']);
        $this->assertMatchesRegularExpression('/^logo-' . $p['id'] . '-[a-f0-9]{16}\.png$/', $p['logo']);
        $first = partner_logo_path($p);
        $this->assertFileExists($first);
        $this->assertSame('data:image/png;base64,' . self::PNG, partner_logo_data($p));

        // Nové logo nahradí staré (staré se smaže).
        $this->assertNull(partner_logo_save($p, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'size' => filesize($png)]));
        $p = partner_row((int) $p['id']);
        $this->assertFileDoesNotExist($first);
        $this->assertFileExists(partner_logo_path($p));
        @unlink(partner_logo_path($p));

        $this->assertSame('', partner_logo_path(['logo' => '../../config.php']));
        $this->assertSame('', partner_logo_data(['logo' => 'logo-1-0123456789abcdef.png']), 'Soubor neexistuje.');
    }

    #[RunInSeparateProcess]
    public function testBrandOnHouseWebsite(): void
    {
        $p = $this->partner(['name' => 'Značková správa', 'phone' => '+420 111 222 333', 'web' => 'https://example.org', 'brand' => 1]);
        $this->attach($p, tenant_row('dum'));
        $this->assertSame(['name' => 'Značková správa', 'phone' => '+420 111 222 333', 'email' => $p['email'], 'web' => 'https://example.org', 'logo' => ''], partner_brand());
    }

    #[RunInSeparateProcess]
    public function testBrandHiddenWhenPartnerTurnsItOff(): void
    {
        $p = $this->partner(['brand' => 0]);
        $this->attach($p, tenant_row('dum'));
        $this->assertNull(partner_brand());
    }

    #[RunInSeparateProcess]
    public function testBrandNotShownForPendingPartner(): void
    {
        $p = $this->partner(['brand' => 1]);
        $this->attach($p, tenant_row('dum'), 'pending');
        $this->assertNull(partner_brand());
    }

    // ---------- Šablony a hromadné aktuality ----------

    public function testTemplatesAndHouseTemplates(): void
    {
        $p = $this->partner();
        foreach ([['post', 'Odstávka'], ['mailing', 'Pozvánka'], ['post', 'Odečty']] as [$k, $n]) {
            pq('INSERT INTO partner_templates (partner_id, kind, name, title, body, updated_at) VALUES (?,?,?,?,?,?)', [$p['id'], $k, $n, $n, 'Text', date('Y-m-d H:i:s')]);
        }
        $this->assertSame(['Odečty', 'Odstávka', 'Pozvánka'], array_column(partner_templates((int) $p['id']), 'name'));
        $this->assertSame(['Odečty', 'Odstávka'], array_column(partner_templates((int) $p['id'], 'post'), 'name'));
        $this->assertSame(['Pozvánka'], array_column(partner_templates((int) $p['id'], 'mailing'), 'name'));

        $orig = tenant_row('dum');
        try {
            pq("UPDATE tenants SET partner_id = 0, partner_status = '' WHERE slug = 'dum'");
            $this->assertSame([], partner_house_templates('post'));
            pq("UPDATE tenants SET partner_id = ?, partner_status = 'pending' WHERE slug = 'dum'", [$p['id']]);
            $this->assertSame([], partner_house_templates('post'));
            pq("UPDATE tenants SET partner_id = ?, partner_status = 'active' WHERE slug = 'dum'", [$p['id']]);
            $this->assertSame(['Odečty', 'Odstávka'], array_column(partner_house_templates('post'), 'name'));
        } finally {
            pq('UPDATE tenants SET partner_id = ?, partner_status = ? WHERE slug = ?', [$orig['partner_id'], $orig['partner_status'], 'dum']);
        }
    }

    public function testBulkPostWithAttachmentSkipsForeignHouses(): void
    {
        $p = $this->partner(['name' => 'Firma Hromadně']);
        $a = $this->attach($p, $this->house('SVJ Hromadné A'));
        $b = $this->attach($p, $this->house('SVJ Hromadné B'));
        $foreign = $this->attach($this->partner(), $this->house('SVJ Cizí'));
        $pending = $this->attach($p, $this->house('SVJ Čeká'), 'pending');
        $pu = $this->worker($p);
        partner_enter_link($pu, $p, $a);
        $mapped = (int) json_decode(tenant_settings($a['slug'])['partner_users'], true)[strtolower($pu['email'])];
        $file = $this->tmp('%PDF-1.4 obsah');
        $title = 'Odstávka vody ' . $this->uid();

        [$done, $errors] = partner_bulk_post($pu, $p, [$a['slug'], $b['slug'], $foreign['slug'], $pending['slug'], 'neexistuje-' . $this->uid()], $title, 'Ve čtvrtek nepoteče voda.', 'residents',
            ['tmp' => $file, 'name' => 'Oznámení o odstávce.PDF']);
        $this->assertSame(2, $done);
        $this->assertSame([], $errors);

        foreach ([[$a, $mapped], [$b, null]] as [$h, $author]) {
            $pdo = tenant_pdo($h['slug']);
            $st = $pdo->prepare('SELECT * FROM posts WHERE title = ?');
            $st->execute([$title]);
            $post = $st->fetch();
            $this->assertNotFalse($post, $h['name']);
            $this->assertSame('Ve čtvrtek nepoteče voda.', $post['body']);
            $this->assertSame('residents', $post['visibility']);
            $this->assertSame(date('Y-m-d'), $post['published_at']);
            $this->assertSame($author, $post['author_id'] === null ? null : (int) $post['author_id']);
            $doc = $pdo->query('SELECT d.* FROM documents d JOIN post_documents pd ON pd.document_id = d.id WHERE pd.post_id = ' . (int) $post['id'])->fetch();
            $this->assertSame('Oznámení o odstávce', $doc['title']);
            $this->assertSame('Oznámení o odstávce.PDF', $doc['original_name']);
            $this->assertSame('oznameni', $doc['category']);
            $this->assertSame('residents', $doc['visibility']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $doc['filename']);
            $this->assertSame('%PDF-1.4 obsah', file_get_contents(tenant_storage($h['slug']) . '/uploads/' . $doc['filename']));
            $this->assertSame(strlen('%PDF-1.4 obsah'), (int) $doc['size']);
        }
        foreach ([$foreign, $pending] as $h) {
            $st = tenant_pdo($h['slug'])->prepare('SELECT COUNT(*) FROM posts WHERE title = ?');
            $st->execute([$title]);
            $this->assertSame(0, (int) $st->fetchColumn(), $h['name']);
        }

        // Bez přílohy.
        [$done] = partner_bulk_post($pu, $p, [$b['slug']], $title . ' bez', 'Text', 'public', null);
        $this->assertSame(1, $done);
        $this->assertSame(0, (int) tenant_pdo($b['slug'])->query('SELECT COUNT(*) FROM post_documents pd JOIN posts p ON p.id = pd.post_id WHERE p.title = ' . tenant_pdo($b['slug'])->quote($title . ' bez'))->fetchColumn());
    }

    // ---------- Kalendář ----------

    private function calendarHouses(): array
    {
        $day = fn(int $d) => date('Y-m-d', strtotime("$d day"));
        $a = $this->house('SVJ Alfa');
        $b = $this->house('SVJ Beta');
        $pa = tenant_pdo($a['slug']);
        ticket_ready($pa);
        $ins = fn(\PDO $pdo, string $sql, array $p) => $pdo->prepare($sql)->execute($p);
        $ins($pa, 'INSERT INTO events (title, event_date, event_time) VALUES (?,?,?)', ['Schůze A, výbor', $day(5), '18:00']);
        $ins($pa, 'INSERT INTO events (title, event_date, event_time) VALUES (?,?,?)', ['Daleko', $day(100), '']);
        $ins($pa, 'INSERT INTO events (title, event_date, event_time) VALUES (?,?,?)', ['Proběhlo', $day(-3), '']);
        $ins($pa, 'INSERT INTO maintenance (title, next_date) VALUES (?,?)', ['Výtah A', $day(-10)]);
        $ins($pa, 'INSERT INTO maintenance (title, next_date) VALUES (?,?)', ['Komín A', $day(200)]);
        $ins($pa, 'INSERT INTO maintenance (title, next_date) VALUES (?,?)', ['Bez data', null]);
        $ins($pa, "INSERT INTO tickets (subject, body, status, due_date) VALUES (?,?,?,?)", ['Zvonek A', 'x', 'new', $day(-2)]);
        $tid = (int) $pa->lastInsertId();
        $ins($pa, "INSERT INTO tickets (subject, body, status, due_date) VALUES (?,?,?,?)", ['Hotovo A', 'x', 'done', $day(-2)]);
        $ins($pa, "INSERT INTO tickets (subject, body, status, due_date) VALUES (?,?,?,?)", ['Bez termínu A', 'x', 'new', '']);
        $ins($pa, 'INSERT INTO polls (title, opens_at, closes_at, published) VALUES (?,?,?,?)', ['Hlasování A', $day(-1), $day(7) . ' 20:00', 1]);
        $ins($pa, 'INSERT INTO polls (title, opens_at, closes_at, published) VALUES (?,?,?,?)', ['Koncept A', $day(-1), $day(7) . ' 20:00', 0]);
        $pb = tenant_pdo($b['slug']);
        $ins($pb, 'INSERT INTO events (title, event_date, event_time) VALUES (?,?,?)', ['Schůze B', $day(5), '09:00']);
        $ins($pb, 'INSERT INTO maintenance (title, next_date) VALUES (?,?)', ['Výtah B', $day(-10)]);
        return [$a, $b, $tid, $day];
    }

    public function testCalendarCollectsSortsAndFlagsLate(): void
    {
        [$a, $b, $tid, $day] = $this->calendarHouses();
        // Pořadí domů na vstupu nehraje roli.
        $items = partner_calendar([$b, $a], date('Y-m-d'), $day(60));
        $this->assertSame([
            [$day(-10), '', 'revize', 'Výtah A', 'SVJ Alfa', true, 'revize'],
            [$day(-10), '', 'revize', 'Výtah B', 'SVJ Beta', true, 'revize'],
            [$day(-2), '', 'zavada', 'Zvonek A', 'SVJ Alfa', true, 'zavada=' . $tid],
            [$day(5), '09:00', 'termin', 'Schůze B', 'SVJ Beta', false, ''],
            [$day(5), '18:00', 'termin', 'Schůze A, výbor', 'SVJ Alfa', false, ''],
            [$day(7) . ' 20:00', '', 'hlasovani', 'Hlasování A', 'SVJ Alfa', false, ''],
        ], array_map(fn($i) => [$i['date'], $i['time'], $i['kind'], $i['title'], $i['house'], $i['late'], $i['k']], $items));
        $this->assertSame($a['slug'], $items[0]['slug']);

        // Užší období: revize a hlášení po termínu zůstanou, termín za 5 dní ne.
        $kinds = array_column(partner_calendar([$a], date('Y-m-d'), $day(1)), 'title');
        $this->assertSame(['Výtah A', 'Zvonek A'], $kinds);
        $this->assertSame([], partner_calendar([], date('Y-m-d'), $day(30)));
    }

    public function testCalendarIcsIsValidAndFolded(): void
    {
        [$a, $b] = $this->calendarHouses();
        $items = partner_calendar([$a, $b], date('Y-m-d'), date('Y-m-d', strtotime('+60 day')));
        $items[] = ['date' => date('Y-m-d', strtotime('+3 day')), 'time' => '', 'kind' => 'termin', 'title' => str_repeat('Velmi dlouhý název termínu se žluťoučkým koněm, ', 4), 'house' => 'SVJ Alfa', 'slug' => $a['slug'], 'late' => false, 'k' => ''];
        $ics = partner_calendar_ics(['name' => 'Firma; Kalendář'], $items);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $physical = explode("\r\n", substr($ics, 0, -2));
        foreach ($physical as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), $line);
            $this->assertTrue(mb_check_encoding(ltrim($line, ' '), 'UTF-8'), 'Zalomení nesmí rozdělit znak: ' . $line);
            $this->assertStringNotContainsString("\n", $line);
        }
        $this->assertGreaterThan(0, count(array_filter($physical, fn($l) => str_starts_with($l, ' '))), 'Dlouhé řádky se zalomí.');
        $this->assertSame(count($items), substr_count($ics, "BEGIN:VEVENT\r\n"));
        $this->assertSame(count($items), substr_count($ics, "END:VEVENT\r\n"));
        $this->assertSame(count($items), substr_count($ics, "TRIGGER:-P1D\r\n"));

        $unfolded = str_replace("\r\n ", '', $ics);
        $this->assertStringContainsString("X-WR-CALNAME:Firma\\; Kalendář – domy\r\n", $unfolded);
        $this->assertStringContainsString("SUMMARY:Revize: Výtah A – SVJ Alfa\r\n", $unfolded);
        $this->assertStringContainsString("SUMMARY:Termín: Schůze A\\, výbor – SVJ Alfa\r\n", $unfolded);
        $this->assertStringContainsString('DESCRIPTION:Začátek 18:00\\n' . platform_url('/partner/kalendar'), $unfolded);
        $d = strtotime(date('Y-m-d', strtotime('-10 day')));
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . date('Ymd', $d) . "\r\nDTEND;VALUE=DATE:" . date('Ymd', $d + 86400) . "\r\n", $unfolded);
        $this->assertMatchesRegularExpression('/UID:[a-f0-9]{32}@' . preg_quote(platform_domain(), '/') . '/', $unfolded);
    }

    // ---------- Faktury partnera společenstvím ----------

    public function testSvjMissing(): void
    {
        $this->assertSame(['IČO', 'sídlo', 'číslo účtu'], partner_svj_missing(['ico' => '', 'address' => '', 'account' => '']));
        $this->assertSame(['číslo účtu'], partner_svj_missing(['ico' => '1', 'address' => 'x']));
        $this->assertSame([], partner_svj_missing(['ico' => '1', 'address' => 'x', 'account' => '123/0800']));
    }

    private function invoicingPartner(int $vat = 21): array
    {
        return $this->partner(['name' => 'Fakturující správa', 'ico' => '12345678', 'dic' => 'CZ12345678', 'address' => 'Hlavní 1, Brno', 'phone' => '+420 5',
            'account' => '19-2000145399/0800', 'iban' => 'CZ65 0800 0000 1920 0014 5399', 'vat_rate' => $vat, 'inv_due' => 10]);
    }

    public function testSvjIssueNumberingAndView(): void
    {
        $p = $this->invoicingPartner();
        $t = $this->attach($p, $this->house('SVJ Odběratel', ['full' => 'Společenství vlastníků Odběratel', 'address' => 'Dvorní 2']));
        tenant_set($t['slug'], 'ico', '87654321');
        tenant_set($t['slug'], 'address', 'Dvorní 2, 602 00 Brno');
        $year = date('Y');

        $id1 = partner_svj_issue($p, $t, '2026-01-01', '2026-12-31', 1000, 'Petra');
        $id2 = partner_svj_issue($p, $t, '2027-01-01', '2027-12-31', 1500, 'Petra');
        $inv1 = pq('SELECT * FROM partner_svj_invoices WHERE id = ?', [$id1])->fetch();
        $inv2 = pq('SELECT * FROM partner_svj_invoices WHERE id = ?', [$id2])->fetch();
        $this->assertSame([$year . '-0001', $year . '0001'], [$inv1['number'], $inv1['vs']]);
        $this->assertSame([$year . '-0002', $year . '0002'], [$inv2['number'], $inv2['vs']]);
        $this->assertSame($year . '-0001', pq('SELECT number FROM partner_svj_invoices WHERE id = ?', [partner_svj_issue($this->invoicingPartner(), $t, '', '', 1, 'x')])->fetchColumn(), 'Řada je pro každou firmu zvlášť.');
        $this->assertSame(date('Y-m-d'), $inv1['issued']);
        $this->assertSame(date('Y-m-d', strtotime('+10 day')), $inv1['due']);
        $this->assertSame('issued', $inv1['status']);
        $this->assertSame(21, (int) $inv1['vat_rate']);
        $this->assertSame('Web společenství SVJ Odběratel (' . tenant_address($t['slug']) . ')', $inv1['item']);
        $data = json_decode($inv1['data'], true);
        $this->assertSame(['name' => 'Společenství vlastníků Odběratel', 'address' => 'Dvorní 2, 602 00 Brno', 'ico' => '87654321', 'dic' => ''], $data['customer']);
        $this->assertSame('12345678', $data['supplier']['ico']);
        $this->assertSame('CZ65 0800 0000 1920 0014 5399', $data['supplier']['iban']);

        $v = partner_svj_view($inv1);
        $this->assertSame(210, $v['vat']);
        $this->assertSame(1210, $v['total']);
        $this->assertSame('Fakturující správa', $v['s']['name']);
        $this->assertSame('87654321', $v['c']['ico']);
        $this->assertStringContainsString('<svg', $v['qr']);

        $this->assertSame([315, 1815], [partner_svj_view($inv2)['vat'], partner_svj_view($inv2)['total']]);
        $this->assertSame('', partner_svj_view(['status' => 'paid'] + $inv1)['qr'], 'Zaplacená faktura bez QR.');
        $noIban = $inv1;
        $noIban['data'] = json_encode(['supplier' => ['iban' => ' '], 'customer' => []]);
        $this->assertSame('', partner_svj_view($noIban)['qr']);

        $p0 = $this->invoicingPartner(0);
        $inv0 = pq('SELECT * FROM partner_svj_invoices WHERE id = ?', [partner_svj_issue($p0, $t, '', '', 999, 'x')])->fetch();
        $this->assertSame([0, 999], [partner_svj_view($inv0)['vat'], partner_svj_view($inv0)['total']]);
    }

    public function testSvjNotifyMailsCommitteeOnly(): void
    {
        $p = $this->invoicingPartner();
        $t = $this->attach($p, $this->house('SVJ Oznámení'));
        $u = $this->uid();
        $pdo = tenant_pdo($t['slug']);
        $add = fn(string $email, string $role, int $active = 1) => $pdo->prepare('INSERT INTO users (email, name, password_hash, role, active) VALUES (?,?,?,?,?)')->execute([$email, 'X', NO_PASSWORD, $role, $active]);
        $committee = "predseda.$u@example.org";
        $add($committee, 'admin');
        $add("Firma.$u@example.org", 'admin');
        $add("ukazka.$u@example.invalid", 'admin');
        $add("obyvatel.$u@example.org", 'resident');
        $add("byvaly.$u@example.org", 'admin', 0);
        tenant_set($t['slug'], 'partner_users', json_encode(["firma.$u@example.org" => 2]));

        $id = partner_svj_issue($p, $t, '2026-01-01', '2026-12-31', 1000, 'Petra');
        $inv = pq('SELECT * FROM partner_svj_invoices WHERE id = ?', [$id])->fetch();
        partner_svj_notify($p, $t, $id);
        $mine = array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['to'], $u)));
        $this->assertSame([$committee], array_column($mine, 'to'));
        $this->assertSame('Faktura za web domu od správce Fakturující správa', $mine[0]['subject']);
        $this->assertStringContainsString('fakturu č. ' . $inv['number'] . ' za web společenství SVJ Oznámení', $mine[0]['body']);
        $this->assertStringContainsString(money_czk(1000) . ' bez DPH, splatnost ' . cz_date($inv['due']), $mine[0]['body']);
        $this->assertStringContainsString('/admin/predplatne#spravce-faktury', $mine[0]['body']);
    }

    // ---------- Ukázka partnerského portálu ----------

    #[RunInSeparateProcess]
    public function testDemoEnsureCreatesDemoPartnerAndHouses(): void
    {
        $this->assertNull(partner_demo_ensure(), 'Bez ukázkového webu se nic nezakládá.');
        $this->assertSame(0, partner_demo_id());

        demo_create();
        $u = partner_demo_ensure();
        $this->assertSame('ukazka-partner@example.invalid', $u['email']);
        $pid = partner_demo_id();
        $this->assertSame($pid, (int) $u['partner_id']);
        $p = partner_row($pid);
        $this->assertSame('Ukázková správa domů s.r.o.', $p['name']);
        $this->assertSame(1, (int) $p['brand']);
        $this->assertSame('4', pset('demo_partner_v'));
        $this->assertCount(3, partner_templates($pid));
        foreach (array_keys(PARTNER_DEMO_HOUSES) as $slug) {
            $t = tenant_row($slug);
            $this->assertSame([2, $pid, 'active', 1], [(int) $t['demo'], (int) $t['partner_id'], $t['partner_status'], (int) $t['partner_billing']], $slug);
            $this->assertGreaterThan(0, (int) tenant_pdo($slug)->query('SELECT COUNT(*) FROM tickets')->fetchColumn());
        }
        $demo = demo_tenant();
        $this->assertSame($pid, (int) tenant_row($demo['slug'])['partner_id']);
        $this->assertSame(['SVJ Lipová 8', 'SVJ Sady 3', 'SVJ Zahradní 12'], array_column(partner_tenants($pid), 'name'));

        // Opakované volání nic nezdvojí.
        $partners = (int) pq('SELECT COUNT(*) FROM partners')->fetchColumn();
        $tickets = (int) tenant_pdo('ukazka-sady')->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
        $this->assertSame((int) $u['id'], (int) partner_demo_ensure()['id']);
        $this->assertSame($partners, (int) pq('SELECT COUNT(*) FROM partners')->fetchColumn());
        $this->assertSame($tickets, (int) tenant_pdo('ukazka-sady')->query('SELECT COUNT(*) FROM tickets')->fetchColumn());
        $this->assertCount(3, partner_templates($pid));
        $this->assertCount(3, partner_tenants($pid));
    }
}
