<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Zájemci o inzerát (app/ext/listing_lib.php, routes_listing.php, views/ext/listing/inquiries.php, inquiry.php):
 * uložení zpráv, upozornění vlastníka, kdo zprávy vidí, odpověď z webu bez prozrazení adresy vlastníka,
 * odpověď zájemce e-mailem (app/ext/inbound_lib.php, značka „I“), úklid po skončení inzerátu.
 */
final class ListingInquiryTest extends TestCase
{
    private const INBOUND = ['host' => 'imap.test.invalid', 'port' => 993, 'secure' => 'ssl', 'user' => 'odpovedi@example.cz', 'password' => 'x', 'address' => 'odpovedi@example.cz'];
    private array $units = [];
    private array $users = [];
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        listing_ready();
        q("DELETE FROM settings WHERE `key` = 'listing_enabled'");
        setting('__reset');
        $GLOBALS['INBOUND_CONFIG'] = [];   // příjem odpovědí vypnutý, pokud ho test nezapne
        $GLOBALS['INBOUND_STAGING'] = false;
        $this->now = time();
        $this->units['a'] = $this->unit();
        $this->units['b'] = $this->unit();
        $this->users['owner'] = $this->user('owner', [$this->units['a']]);
        $this->users['coowner'] = $this->user('owner', [$this->units['a']]);
        $this->users['tenant'] = $this->user('tenant', [$this->units['a']]);
        $this->users['other'] = $this->user('owner', [$this->units['b']]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['INBOUND_CONFIG'], $GLOBALS['INBOUND_STAGING']);
        foreach (all('SELECT id FROM listings') as $l) {
            listing_delete((int) $l['id']);
        }
        q('DELETE FROM listing_hits');
        q('DELETE FROM listing_inquiries');
        q('DELETE FROM listing_inquiry_messages');
        foreach ($this->users as $id) {
            q('DELETE FROM push_subs WHERE user_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
        }
        foreach ($this->units as $id) {
            q('DELETE FROM units WHERE id = ?', [$id]);
        }
        $_SESSION = [];
        user(true);
    }

    private function unit(): int
    {
        q("INSERT INTO units (entrance, number, floor, area) VALUES ('A', ?, '2', 61)", ['Z' . bin2hex(random_bytes(3))]);
        return (int) db()->lastInsertId();
    }
    private function user(string $kind, array $unitIds): int
    {
        q("INSERT INTO users (email, name, password_hash, role, kind) VALUES (?, ?, 'x', 'resident', ?)", ['inq-' . bin2hex(random_bytes(4)) . '@example.cz', 'Vlastník Bytu', $kind]);
        $id = (int) db()->lastInsertId();
        foreach ($unitIds as $u) {
            q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$id, $u]);
        }
        return $id;
    }
    private function u(string $who): array
    {
        return one('SELECT * FROM users WHERE id = ?', [$this->users[$who]]);
    }
    /** Zveřejněný inzerát jednotky A (rovnou v databázi, bez fotek). */
    private function listing(array $over = []): array
    {
        $c = $over + ['unit_id' => $this->units['a'], 'user_id' => $this->users['owner'], 'type' => 'prodej', 'price' => 4250000, 'layout' => '2+kk', 'description' => 'Světlý byt.',
            'contact_name' => 'Jana P.', 'status' => 'published', 'created_at' => date('Y-m-d H:i:s'), 'published_at' => date('Y-m-d H:i:s', $this->now - 86400),
            'public_from' => date('Y-m-d H:i:s', $this->now - 86400), 'expires_on' => date('Y-m-d', $this->now + 30 * 86400)];
        q('INSERT INTO listings (' . implode(', ', array_keys($c)) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')', array_values($c));
        return listing_get((int) db()->lastInsertId());
    }
    private function contact(array $l, string $contact = 'Petr.Zajemce@Example.org', string $message = 'Dobrý den, mohu na prohlídku v sobotu?'): array
    {
        $r = listing_contact($l, ['name' => 'Petr Zájemce', 'contact' => $contact, 'message' => $message, 't' => listing_form_token($this->now - 30)],
            '10.77.' . random_int(0, 255) . '.' . random_int(1, 254), false, $this->now);
        $this->assertTrue($r['ok'] ?? false, implode(' ', $r['errors'] ?? []));
        return one('SELECT * FROM listing_inquiries WHERE id = ?', [$r['inquiry']]);
    }
    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body']), 'reply_to' => $m['reply_to']], $this->mails());
    }
    private function mailsTo(string $to): array
    {
        return array_values(array_filter($this->decodedMails(), fn($m) => strtolower($m['to']) === strtolower($to)));
    }
    private function view(string $name, array $vars): string
    {
        extract($vars);
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/listing/' . $name . '.php';
        return (string) ob_get_clean();
    }
    private function eml(string $from, string $subject, string $body): string
    {
        return "From: $from\r\nTo: odpovedi@example.cz\r\nSubject: $subject\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@test>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
            . str_replace("\n", "\r\n", $body);
    }

    // ---------- Uložení a upozornění ----------

    public function testContactMessageIsStoredAsInquiryThread(): void
    {
        $l = $this->listing();
        $inq = $this->contact($l);
        $this->assertSame((int) $l['id'], (int) $inq['listing_id']);
        $this->assertSame('Petr Zájemce', $inq['name']);
        $this->assertSame('Petr.Zajemce@Example.org', $inq['contact'], 'kontakt, jak ho zájemce zadal');
        $this->assertSame('petr.zajemce@example.org', $inq['email']);
        $this->assertSame('new', $inq['status']);
        $msgs = listing_inquiry_messages((int) $inq['id']);
        $this->assertCount(1, $msgs);
        $this->assertSame(['visitor', 'web', 'Dobrý den, mohu na prohlídku v sobotu?'], [$msgs[0]['author'], $msgs[0]['via'], $msgs[0]['body']]);

        $phone = $this->contact($l, '+420 777 123 456', 'Zavolejte prosím.');
        $this->assertSame('', $phone['email']);
        $this->assertSame('+420 777 123 456', listing_inquiry_phone($phone));
        $this->assertSame(['new' => 2, 'total' => 2], listing_inquiry_counts((int) $l['id']));

        // Ochrana proti robotům zůstává: vyplněné skryté pole nic neuloží.
        $r = listing_contact($l, ['name' => 'Robot', 'contact' => 'r@example.org', 'message' => 'spam spam', 'website' => 'x', 't' => listing_form_token($this->now - 30)], '10.78.0.1', false, $this->now);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, listing_inquiry_counts((int) $l['id'])['total']);
    }

    public function testOwnerGetsEmailWithLinkAndPush(): void
    {
        $this->assertTrue(plan_has('push'), 'předpoklad: ukázkový dům má upozornění do telefonu');
        $owner = $this->u('owner');
        // Odběr s adresou mimo povolené push služby: push_notify() doručení zkusí a započte neúspěch (žádná síť).
        q("INSERT INTO push_subs (user_id, endpoint, p256dh, auth, device, created_at, last_ok, fails) VALUES (?, ?, 'x', 'x', 'test', ?, '', 0)",
            [$owner['id'], 'https://push.example.invalid/sub/' . bin2hex(random_bytes(4)), date('Y-m-d H:i:s')]);
        $sub = (int) db()->lastInsertId();
        $l = $this->listing();
        $inq = $this->contact($l);
        $mails = $this->mailsTo($owner['email']);
        $this->assertCount(1, $mails);
        $this->assertSame('petr.zajemce@example.org', $mails[0]['reply_to'], 'odpověď ze schránky dál jde zájemci');
        $this->assertStringContainsString('Zájemce o Váš inzerát: Prodej bytu 2+kk', $mails[0]['subject']);
        $this->assertStringContainsString(absolute_url('/inzeraty/' . $l['id'] . '/zajemci/' . $inq['id']), $mails[0]['body']);
        $this->assertStringContainsString('mohu na prohlídku v sobotu', $mails[0]['body']);
        $this->assertSame(1, (int) val('SELECT fails FROM push_subs WHERE id = ?', [$sub]), 'upozornění do telefonu se pokusilo odejít');
        $this->assertSame([], $this->mailsTo('petr.zajemce@example.org'), 'zájemce nedostane nic');

        // Vlastník si upozornění vypnul: e-mail ano, push ne.
        q('UPDATE users SET push_notify = 0 WHERE id = ?', [$owner['id']]);
        $this->contact($l, 'eva@example.org');
        $this->assertSame(1, (int) val('SELECT fails FROM push_subs WHERE id = ?', [$sub]));
        $this->assertCount(2, $this->mailsTo($owner['email']));
    }

    // ---------- Kdo zprávy vidí ----------

    public function testOnlyUnitOwnersCanReadInquiries(): void
    {
        $l = $this->listing();
        $inq = $this->contact($l);
        $this->assertCount(1, listing_inquiries_for($l, $this->u('owner')));
        $this->assertNotNull(listing_inquiry_for($l, (int) $inq['id'], $this->u('owner')));
        $this->assertNotNull(listing_inquiry_for($l, (int) $inq['id'], $this->u('coowner')), 'spoluvlastník jednotky ano');
        $admin = one("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
        foreach (['vlastník jiné jednotky' => $this->u('other'), 'nájemník jednotky' => $this->u('tenant'), 'správce webu (výbor)' => $admin, 'nepřihlášený' => null] as $who => $u) {
            $this->assertNull(listing_inquiries_for($l, $u), $who);
            $this->assertNull(listing_inquiry_for($l, (int) $inq['id'], $u), $who);
        }
        // Vlákno jiného inzerátu přes adresu tohoto inzerátu nejde otevřít.
        $other = $this->listing(['unit_id' => $this->units['b'], 'user_id' => $this->users['other']]);
        $this->assertNull(listing_inquiry_for($other, (int) $inq['id'], $this->u('other')));
        // Trasy: každá adresa zájemců ověřuje listing_can_manage (přes $listingManaged) a listing_inquiry_for.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/ext/routes_listing.php');
        $this->assertSame(4, substr_count($src, "\$route('GET', '/inzeraty/(\\d+)/zajemci") + substr_count($src, "\$route('POST', '/inzeraty/(\\d+)/zajemci"));
        $this->assertSame(4, substr_count($src, '= $listingManaged($id);'), 'každá adresa (i společné zobrazení vlákna) ověřuje vlastníka');
        $this->assertSame(3, substr_count($src, 'listing_inquiry_for($l, $inqId, $u) ?? not_found()'));
        $this->assertStringContainsString('if (!$l || !listing_can_manage($l, $u)) {', $src);

        // Výbor v přehledu vidí jen počet, ne obsah.
        $rows = all('SELECT l.*, un.entrance, un.number, u.name owner_name, 0 reports, (SELECT COUNT(*) FROM listing_inquiries i WHERE i.listing_id = l.id) inquiries
            FROM listings l LEFT JOIN units un ON un.id = l.unit_id LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ?', [$l['id']]);
        $html = $this->view('admin', ['rows' => $rows, 'reports' => [], 'penb' => listing_penb()]);
        $this->assertStringContainsString('<td data-label="Zájemci"', $html);
        $this->assertStringNotContainsString('Petr', $html);
        $this->assertStringNotContainsString('prohlídku', $html);
        $this->assertStringNotContainsString('/zajemci', $html);
    }

    // ---------- Pohled vlastníka ----------

    public function testInboxAndThreadViews(): void
    {
        $l = $this->listing();
        $a = $this->contact($l);
        $b = $this->contact($l, '+420 777 123 456', 'Volejte po 17. hodině.');
        listing_inquiry_set_status((int) $a['id'], 'read');
        $rows = listing_inquiries_for($l, $this->u('owner'));
        $this->assertSame([(int) $b['id'], (int) $a['id']], array_map(fn($r) => (int) $r['id'], $rows), 'nejnovější nahoře');
        $html = $this->view('inquiries', ['l' => $l, 'rows' => $rows, 'archive' => false]);
        $this->assertSame(1, substr_count($html, 'lst-inq-new'), 'nová zpráva zvýrazněná');
        $this->assertStringContainsString(listing_inquiry_path($l, $a), $html);
        $this->assertStringContainsString('+420 777 123 456', $html);
        $this->assertSame('Zájemci (1 nový)', listing_inquiry_label(...array_values(listing_inquiry_counts((int) $l['id']))));
        $this->assertSame('Zájemci (3 noví)', listing_inquiry_label(3, 4));
        $this->assertSame('Zájemci (7 nových)', listing_inquiry_label(7, 9));
        $this->assertSame('Zájemci (4)', listing_inquiry_label(0, 4));
        $this->assertSame('Zájemci', listing_inquiry_label(0, 0));

        // Vlákno s e-mailem: formulář odpovědi a AI pomocník (uhladit, zkrátit).
        $html = $this->view('inquiry', ['l' => $l, 'inq' => $a, 'msgs' => listing_inquiry_messages((int) $a['id']), 'error' => null, 'draft' => '']);
        $this->assertStringContainsString('name="body"', $html);
        $this->assertSame(substr_count($html, '<form method="post"'), substr_count($html, 'csrf_field') + substr_count($html, 'name="_csrf"'));
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/ext/listing/inquiry.php');
        $this->assertStringContainsString("\$aiTasks = ['polish', 'shorten']", $src);
        $this->assertStringContainsString('_ai_mini.php', $src);
        // Jen telefon: místo formuláře rada zavolat nebo poslat SMS.
        $html = $this->view('inquiry', ['l' => $l, 'inq' => $b, 'msgs' => listing_inquiry_messages((int) $b['id']), 'error' => null, 'draft' => '']);
        $this->assertStringNotContainsString('name="body"', $html);
        $this->assertStringContainsString('href="tel:+420777123456"', $html);
        $this->assertStringContainsString('href="sms:+420777123456"', $html);
        $this->assertStringContainsString('Zájemce nechal jen telefon', $html);

        // Odkaz „Zájemci“ v Moje inzeráty a u úpravy inzerátu.
        $this->assertStringContainsString("listing_inquiry_label(\$ic['new'], \$ic['total'])", (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/ext/listing/mine.php'));
        $this->assertStringContainsString("listing_inquiry_label(\$ic['new'], \$ic['total'])", (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/ext/listing/form.php'));

        // Archiv
        listing_inquiry_set_status((int) $b['id'], 'archived');
        $rows = listing_inquiries_for($l, $this->u('owner'));
        $this->assertSame(1, substr_count($this->view('inquiries', ['l' => $l, 'rows' => $rows, 'archive' => false]), 'class="card lst-inq'));
        $this->assertStringContainsString('Archiv (1)', $this->view('inquiries', ['l' => $l, 'rows' => $rows, 'archive' => false]));
        $this->assertSame(1, substr_count($this->view('inquiries', ['l' => $l, 'rows' => $rows, 'archive' => true]), 'class="card lst-inq'));
    }

    // ---------- Odpověď z webu ----------

    public function testWebReplyWithoutInboundUsesNoReplyAndLinkToForm(): void
    {
        $l = $this->listing();
        $inq = $this->contact($l);
        $owner = $this->u('owner');
        $this->assertNull(listing_inquiry_reply($l, $inq, "Dobrý den,\nsobota v 10 se hodí.", $this->now + 60));
        $mails = $this->mailsTo('petr.zajemce@example.org');
        $this->assertCount(1, $mails);
        $m = $mails[0];
        $this->assertStringContainsString('sobota v 10 se hodí', $m['body']);
        $this->assertStringNotContainsString($owner['email'], $m['body'] . $m['subject'] . $m['reply_to'], 'adresa vlastníka se neprozradí');
        $this->assertNotSame(strtolower($owner['email']), strtolower($m['reply_to']));
        $this->assertSame('neodpovidejte@' . substr((string) strrchr(setting('mail_from'), '@'), 1), $m['reply_to']);
        $this->assertStringContainsString('Odpovědět můžete přes formulář u inzerátu: ' . absolute_url(listing_path($l)), $m['body']);
        $this->assertSame([], inbound_tokens_in($m['subject'] . $m['body']), 'bez značky vlákna');
        $msgs = listing_inquiry_messages((int) $inq['id']);
        $this->assertSame(['visitor', 'owner'], array_column($msgs, 'author'));
        $this->assertSame('web', $msgs[1]['via']);
        $this->assertSame('read', one('SELECT status FROM listing_inquiries WHERE id = ?', [$inq['id']])['status']);
        // Prázdná odpověď neodejde.
        $this->assertNotNull(listing_inquiry_reply($l, $inq, ' '));
        $this->assertNotNull(listing_inquiry_reply($l, $inq, str_repeat('x', LISTING_INQUIRY_MAX + 1)));
        $this->assertCount(1, $this->mailsTo('petr.zajemce@example.org'));
    }

    public function testWebReplyWithInboundHasSignedTokenAndInboxReplyTo(): void
    {
        $GLOBALS['INBOUND_CONFIG'] = self::INBOUND;
        $l = $this->listing();
        $inq = $this->contact($l);
        $owner = $this->u('owner');
        $this->assertNull(listing_inquiry_reply($l, $inq, 'Prohlídka v sobotu v 10 je možná.'));
        $m = $this->mailsTo('petr.zajemce@example.org')[0];
        $this->assertSame('odpovedi@example.cz', $m['reply_to']);
        $this->assertStringNotContainsString($owner['email'], $m['body'] . $m['subject']);
        $token = inbound_token('I', TENANT, (int) $inq['id'], 'petr.zajemce@example.org');
        $this->assertStringContainsString('[#' . $token . ']', $m['subject']);
        $this->assertStringContainsString('#' . $token, $m['body']);
        $found = inbound_tokens_in($m['subject']);
        $this->assertTrue(inbound_token_valid($found[0], 'petr.zajemce@example.org'));
        $this->assertFalse(inbound_token_valid($found[0], 'utocnik@example.org'));
        $this->assertStringContainsString('Odpovědět můžete přímo na tento e-mail', $m['body']);
    }

    public function testPhoneOnlyInquiryCannotBeAnsweredByEmail(): void
    {
        $l = $this->listing();
        $inq = $this->contact($l, '777 123 456', 'Zavolejte prosím.');
        $this->assertStringContainsString('jen telefon', (string) listing_inquiry_reply($l, $inq, 'Dobrý den'));
        $this->assertCount(1, listing_inquiry_messages((int) $inq['id']));
        $owner = $this->u('owner');
        $this->assertNotSame('777 123 456', $this->mailsTo($owner['email'])[0]['reply_to']);
        $this->assertCount(1, $this->decodedMails(), 'jen upozornění vlastníkovi');
    }

    // ---------- Odpověď zájemce e-mailem ----------

    public function testVisitorEmailReplyIsAppendedAndOwnerNotified(): void
    {
        $GLOBALS['INBOUND_CONFIG'] = self::INBOUND;
        $l = $this->listing();
        $inq = $this->contact($l);
        listing_inquiry_reply($l, $inq, 'Sobota v 10?');
        $token = inbound_token('I', TENANT, (int) $inq['id'], 'petr.zajemce@example.org');
        $res = inbound_handle($this->eml('Petr <Petr.Zajemce@example.org>', 'Re: Odpověď k inzerátu [#' . $token . ']',
            "Ano, v sobotu v 10 přijdu.\n\nOn Sat, Vlastník wrote:\n> Sobota v 10?\n> Značka vlákna (při odpovědi ji prosím ponechte): #" . $token));
        $this->assertSame('attached', $res['status'], $res['reason']);
        $msgs = listing_inquiry_messages((int) $inq['id']);
        $this->assertSame(['visitor', 'owner', 'visitor'], array_column($msgs, 'author'));
        $this->assertSame('email', $msgs[2]['via']);
        $this->assertSame('Ano, v sobotu v 10 přijdu.', $msgs[2]['body']);
        $this->assertSame('new', one('SELECT status FROM listing_inquiries WHERE id = ?', [$inq['id']])['status'], 'znovu mezi nepřečtenými');
        $mails = $this->mailsTo($this->u('owner')['email']);
        $this->assertCount(2, $mails);
        $this->assertStringContainsString('Odpověď zájemce o Váš inzerát', $mails[1]['subject']);
        $this->assertStringContainsString('v sobotu v 10 přijdu', $mails[1]['body']);
        $this->assertSame('petr.zajemce@example.org', $mails[1]['reply_to']);
    }

    public function testVisitorEmailReplyFromWrongSenderIsRejected(): void
    {
        $GLOBALS['INBOUND_CONFIG'] = self::INBOUND;
        $l = $this->listing();
        $inq = $this->contact($l);
        $token = inbound_token('I', TENANT, (int) $inq['id'], 'petr.zajemce@example.org');
        // Cizí odesílatel se značkou zájemce: podpis nesedí.
        $res = inbound_handle($this->eml('utocnik@example.org', 'Re: [#' . $token . ']', 'Podvržená zpráva'));
        $this->assertSame('rejected', $res['status']);
        // Cizí odesílatel se značkou podepsanou na sebe (zná číslo vlákna): není zájemce, který dotaz poslal.
        $res = inbound_handle($this->eml('utocnik@example.org', 'Re: [#' . inbound_token('I', TENANT, (int) $inq['id'], 'utocnik@example.org') . ']', 'Podvržená zpráva'));
        $this->assertSame('rejected', $res['status']);
        $this->assertStringContainsString('není zájemce', $res['reason']);
        // Dotaz s telefonem nemá e-mail, odpověď e-mailem k němu nepatří.
        $phone = $this->contact($l, '777 123 456');
        $res = inbound_handle($this->eml('x@example.org', 'Re: [#' . inbound_token('I', TENANT, (int) $phone['id'], 'x@example.org') . ']', 'text'));
        $this->assertSame('rejected', $res['status']);
        $this->assertCount(1, listing_inquiry_messages((int) $inq['id']));
        $this->assertCount(2, $this->mailsTo($this->u('owner')['email']), 'jen upozornění na obě nové zprávy z webu');
        $this->assertSame('dotaz k inzerátu neexistuje', listing_inquiry_inbound(999999, 'a@b.cz', 'x'));
    }

    /** Běh v konzoli platformy (jiný dům): odpověď jde do fronty a dům ji převezme. */
    public function testQueuedVisitorReplyIsAppliedByHouse(): void
    {
        $GLOBALS['INBOUND_CONFIG'] = self::INBOUND;
        $l = $this->listing();
        $inq = $this->contact($l);
        $raw = $this->eml('petr.zajemce@example.org', 'Re: [#' . inbound_token('I', TENANT, (int) $inq['id'], 'petr.zajemce@example.org') . ']', 'Mám ještě otázku na sklep.');
        $route = inbound_route($raw);
        $this->assertTrue($route['ok'], $route['reason']);
        $logId = inbound_log_add(['status' => 'queued'] + $route);
        inbound_queue($route, $logId);
        $this->assertSame(1, inbound_apply_pending());
        $msgs = listing_inquiry_messages((int) $inq['id']);
        $this->assertSame('Mám ještě otázku na sklep.', end($msgs)['body']);
        $this->assertSame('attached', inbound_pdo()->query('SELECT status FROM inbound_log WHERE id = ' . $logId)->fetchColumn());
    }

    public function testEverythingWorksWithoutInbound(): void
    {
        $this->assertFalse(inbound_on());
        $this->assertNull(inbound_reply('I', TENANT, 1, 'a@example.org'));
        $l = $this->listing();
        $inq = $this->contact($l);
        $this->assertNull(listing_inquiry_reply($l, $inq, 'Odpověď bez schránky.'));
        $this->assertCount(2, listing_inquiry_messages((int) $inq['id']));
    }

    // ---------- Úklid ----------

    public function testRetentionAfterListingEndsAndOnDelete(): void
    {
        $day = 86400;
        $sold = $this->listing(['status' => 'sold', 'sold_at' => date('Y-m-d H:i:s', $this->now - (LISTING_INQUIRY_KEEP_DAYS + 1) * $day)]);
        $soldRecent = $this->listing(['status' => 'sold', 'sold_at' => date('Y-m-d H:i:s', $this->now - (LISTING_INQUIRY_KEEP_DAYS - 1) * $day)]);
        $expired = $this->listing(['status' => 'expired', 'expires_on' => date('Y-m-d', $this->now - (LISTING_INQUIRY_KEEP_DAYS + 2) * $day)]);
        $expiredRecent = $this->listing(['status' => 'expired', 'expires_on' => date('Y-m-d', $this->now - 10 * $day)]);
        $live = $this->listing();
        foreach ([$sold, $soldRecent, $expired, $expiredRecent, $live] as $l) {
            listing_inquiry_add($l, 'Eva', 'eva@example.org', 'eva@example.org', 'Zpráva', $this->now - 200 * $day);
        }
        q("INSERT INTO listing_inquiries (listing_id, name, status) VALUES (987654, 'sirotek', 'new')");
        $orphan = (int) db()->lastInsertId();
        q("INSERT INTO listing_inquiry_messages (inquiry_id, author, body) VALUES (?, 'visitor', 'x')", [$orphan]);

        $this->assertContains('listing_daily', $GLOBALS['EXT_DAILY']);
        listing_daily($this->now);
        $left = fn(array $l) => listing_inquiry_counts((int) $l['id'])['total'];
        $this->assertSame(0, $left($sold), 'prodáno před víc než 90 dny');
        $this->assertSame(0, $left($expired), 'vypršel před víc než 90 dny');
        $this->assertSame(1, $left($soldRecent));
        $this->assertSame(1, $left($expiredRecent));
        $this->assertSame(1, $left($live));
        $this->assertFalse((bool) val('SELECT 1 FROM listing_inquiries WHERE id = ?', [$orphan]));
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM listing_inquiry_messages WHERE inquiry_id NOT IN (SELECT id FROM listing_inquiries)'), 'žádné zprávy bez vlákna');

        // Smazání inzerátu smaže zájemce hned.
        $ids = array_column(listing_inquiries_for($live, $this->u('owner')), 'id');
        listing_delete((int) $live['id']);
        $this->assertSame(0, $left($live));
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM listing_inquiry_messages WHERE inquiry_id = ?', [(int) $ids[0]]));
    }

    public function testTablesAreCreatedOnExistingDatabase(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE listings (id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER NOT NULL DEFAULT 0)");
        listing_ready($pdo);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('listing_inquiries', $tables);
        $this->assertContains('listing_inquiry_messages', $tables);
    }
}
