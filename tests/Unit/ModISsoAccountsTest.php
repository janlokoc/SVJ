<?php
declare(strict_types=1);

namespace Tests\Unit;

require_once __DIR__ . '/ModISsoCase.php';

/**
 * Modul I: komu SSO dovolí přihlášení (jen existující aktivní účty s ověřeným e-mailem nebo propojením – nikdy nový účet),
 * propojení účtů na webu domu, v partnerském portálu a v konzoli, texty a přehled v konzoli.
 */
final class ModISsoAccountsTest extends ModISsoCase
{
    private static function mail(string $prefix = 'u'): string
    {
        return $prefix . '.' . bin2hex(random_bytes(4)) . '@example.org';
    }

    public function testSiteResolvesExistingAccountByVerifiedEmailOnly(): void
    {
        $email = self::mail();
        $u = $this->makeUser($email);
        $before = (int) val('SELECT COUNT(*) FROM users');
        $r = sso_site_resolve(self::identity('google', 'g-' . $email, strtoupper($email)));
        $this->assertSame((int) $u['id'], (int) $r['user']['id'], 'e-mail bez ohledu na velikost písmen');
        $this->assertSame('unverified', sso_site_resolve(self::identity('google', 'g2-' . $email, $email, false))['error']);
        $this->assertSame('unverified', sso_site_resolve(self::identity('apple', 'a-' . $email))['error'], 'bez e-mailu a bez propojení');
        $this->assertSame('no_account', sso_site_resolve(self::identity('google', 'g3', self::mail('nikdo')))['error']);
        $this->assertSame($before, (int) val('SELECT COUNT(*) FROM users'), 'SSO nikdy nezakládá účet');
    }

    public function testInactiveAndInvitedAccountsCannotSignIn(): void
    {
        $off = $this->makeUser(self::mail('off'), ['active' => 0]);
        $this->assertSame('no_account', sso_site_resolve(self::identity('google', 'x1', $off['email']))['error']);
        $inv = $this->makeUser(self::mail('inv'), ['password_hash' => NO_PASSWORD]);
        $this->assertSame('invite', sso_site_resolve(self::identity('google', 'x2', $inv['email']))['error']);
        // Propojený, ale mezitím vypnutý účet se nepřihlásí.
        $u = $this->makeUser(self::mail('lnk'));
        sso_site_link((int) $u['id'], self::identity('microsoft', 'ms-lnk', ''));
        q('UPDATE users SET active = 0 WHERE id = ?', [$u['id']]);
        $this->assertSame('no_account', sso_site_resolve(self::identity('microsoft', 'ms-lnk', ''))['error']);
    }

    public function testLinkedSubjectWinsEvenWithHiddenOrDifferentEmail(): void
    {
        $u = $this->makeUser(self::mail('jana'));
        $other = $this->makeUser(self::mail('petr'));
        $this->assertNull(sso_site_link((int) $u['id'], self::identity('apple', 'apple-001', 'relay123@privaterelay.appleid.com')));
        $r = sso_site_resolve(self::identity('apple', 'apple-001', 'relay123@privaterelay.appleid.com'));
        $this->assertSame((int) $u['id'], (int) $r['user']['id']);
        // I když poskytovatel teď hlásí e-mail jiného uživatele, platí propojení (stálý identifikátor).
        $r = sso_site_resolve(self::identity('apple', 'apple-001', $other['email']));
        $this->assertSame((int) $u['id'], (int) $r['user']['id']);
        $links = sso_links((int) $u['id']);
        $this->assertSame(['apple'], array_keys($links));
        $this->assertSame('relay123@privaterelay.appleid.com', $links['apple']['email']);
    }

    public function testLinkConflictsReplacementAndUnlink(): void
    {
        $a = $this->makeUser(self::mail('a'));
        $b = $this->makeUser(self::mail('b'));
        $this->assertNull(sso_site_link((int) $a['id'], self::identity('google', 'g-shared', 'x@example.org')));
        $this->assertSame('linked_other', sso_site_link((int) $b['id'], self::identity('google', 'g-shared', 'x@example.org')), 'jeden účet Google patří jen jednomu uživateli');
        // Nové propojení stejného poskytovatele nahradí staré.
        $this->assertNull(sso_site_link((int) $a['id'], self::identity('google', 'g-new', 'y@example.org')));
        $this->assertSame('g-new', sso_links((int) $a['id'])['google']['subject']);
        $this->assertSame(1, (int) val("SELECT COUNT(*) FROM user_sso WHERE user_id = ? AND provider = 'google'", [$a['id']]));
        // Starý identifikátor už nikoho nepřihlásí (a bez ověřeného e-mailu nic nenajde).
        $this->assertSame('unverified', sso_site_resolve(self::identity('google', 'g-shared', ''))['error']);
        $this->assertTrue(sso_site_unlink((int) $a['id'], 'google'));
        $this->assertFalse(sso_site_unlink((int) $a['id'], 'google'));
        $this->assertSame([], sso_links((int) $a['id']));
        // Propojení smazaného uživatele se uvolní.
        $c = $this->makeUser(self::mail('c'));
        sso_site_link((int) $c['id'], self::identity('facebook', 'fb-c'));
        q('DELETE FROM users WHERE id = ?', [$c['id']]);
        $this->assertNull(sso_site_link((int) $b['id'], self::identity('facebook', 'fb-c')));
        $this->assertSame((int) $b['id'], (int) sso_site_resolve(self::identity('facebook', 'fb-c'))['user']['id']);
    }

    public function testDemoTenantUserWithTotpIsResolvedAndKeepsTotpFlag(): void
    {
        $u = $this->makeUser(self::mail('totp'), ['totp_enabled' => 1]);
        $r = sso_site_resolve(self::identity('google', 'g-totp', $u['email']));
        $this->assertSame(1, (int) $r['user']['totp_enabled'], 'route po SSO vyžádá kód z aplikace');
    }

    private function partnerUser(array $over = []): array
    {
        $over += ['email' => self::mail('pracovnik'), 'password_hash' => password_hash('heslo-pro-test-1', PASSWORD_DEFAULT), 'active' => 1, 'partner_active' => 1];
        pq('INSERT INTO partners (name, ico, dic, address, email, phone, active, created_at) VALUES (?,?,?,?,?,?,?,?)', ['Správa ' . bin2hex(random_bytes(3)), '', '', '', '', '', $over['partner_active'], date('Y-m-d H:i:s')]);
        $pid = (int) platform_db()->lastInsertId();
        pq('INSERT INTO partner_users (partner_id, name, email, password_hash, role, active, created_at) VALUES (?,?,?,?,?,?,?)', [$pid, 'Pracovník', $over['email'], $over['password_hash'], 'member', $over['active'], date('Y-m-d H:i:s')]);
        $uid = (int) platform_db()->lastInsertId();
        $this->cleanup[] = function () use ($pid, $uid) {
            pq("DELETE FROM platform_sso WHERE kind = 'partner' AND user_id = ?", [$uid]);
            pq('DELETE FROM partner_users WHERE id = ?', [$uid]);
            pq('DELETE FROM partners WHERE id = ?', [$pid]);
        };
        return pq('SELECT * FROM partner_users WHERE id = ?', [$uid])->fetch();
    }

    public function testPartnerPortalResolution(): void
    {
        $pu = $this->partnerUser();
        $this->assertSame((int) $pu['id'], (int) sso_platform_resolve('partner', self::identity('microsoft', 'm-1', $pu['email']))['user']['id']);
        $this->assertSame('unverified', sso_platform_resolve('partner', self::identity('microsoft', 'm-2', $pu['email'], false))['error']);
        $off = $this->partnerUser(['partner_active' => 0]);
        $this->assertSame('no_account', sso_platform_resolve('partner', self::identity('google', 'g-1', $off['email']))['error'], 'firma je vypnutá');
        $inv = $this->partnerUser(['password_hash' => NO_PASSWORD]);
        $this->assertSame('invite', sso_platform_resolve('partner', self::identity('google', 'g-2', $inv['email']))['error'], 'pozvánka ještě nepřijatá');
        // Propojení.
        $this->assertNull(sso_platform_link('partner', (int) $pu['id'], self::identity('apple', 'ap-1', 'r@privaterelay.appleid.com')));
        $this->assertSame((int) $pu['id'], (int) sso_platform_resolve('partner', self::identity('apple', 'ap-1', 'r@privaterelay.appleid.com'))['user']['id']);
        $this->assertSame('linked_other', sso_platform_link('partner', (int) $inv['id'], self::identity('apple', 'ap-1')));
        $this->assertSame(['apple'], array_keys(sso_platform_links('partner', (int) $pu['id'])));
        $this->assertTrue(sso_platform_unlink('partner', (int) $pu['id'], 'apple'));
        $this->assertSame([], sso_platform_links('partner', (int) $pu['id']));
    }

    public function testConsoleResolutionIsSeparateFromPartners(): void
    {
        $email = self::mail('super');
        pq('INSERT INTO super_admins (email, name, password_hash, created_at) VALUES (?,?,?,?)', [$email, 'Správce', password_hash('heslo-pro-test-1', PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        $sid = (int) platform_db()->lastInsertId();
        $this->cleanup[] = function () use ($sid) {
            pq("DELETE FROM platform_sso WHERE kind = 'super' AND user_id = ?", [$sid]);
            pq('DELETE FROM super_admins WHERE id = ?', [$sid]);
        };
        $this->assertSame($sid, (int) sso_platform_resolve('super', self::identity('google', 'gs-1', strtoupper($email)))['user']['id']);
        $this->assertNull(sso_platform_link('super', $sid, self::identity('google', 'gs-link')));
        $this->assertSame($sid, (int) sso_platform_resolve('super', self::identity('google', 'gs-link'))['user']['id']);
        $this->assertSame('unverified', sso_platform_resolve('partner', self::identity('google', 'gs-link'))['error'], 'propojení konzole neplatí v portálu');
        $this->assertSame('no_account', sso_platform_resolve('partner', self::identity('google', 'gs-x', $email))['error'], 'hlavní správce není pracovník partnera');
    }

    public function testMessagesNameTheProviderAndNeverPromiseNewAccounts(): void
    {
        $this->assertStringContainsString('Google', sso_message('failed', 'google'));
        $this->assertStringContainsString('zrušili', sso_message('denied', 'apple'));
        $m = sso_message('no_account', 'microsoft', 'jana@example.org');
        $this->assertStringContainsString('jana@example.org', $m);
        $this->assertStringContainsString('nezakládá', $m);
        $this->assertStringContainsString('propojte', sso_message('unverified', 'facebook'));
        foreach (['invite', 'blocked', 'expired', 'linked_other', 'link_wrong_user'] as $code) {
            $this->assertNotSame(sso_message('failed', 'google'), sso_message($code, 'google'), $code);
        }
    }

    public function testConsoleSectionShowsStatusAndRedirectUrisButNoSecrets(): void
    {
        $GLOBALS['SSO_CONFIG']['facebook'] = [];
        $html = sso_console_section();
        $this->assertStringContainsString('http://test.invalid/sso/callback/google', $html);
        $this->assertStringContainsString('http://test.invalid/sso/callback/apple', $html);
        $this->assertStringContainsString('Nastaveno', $html);
        $this->assertStringContainsString('chybí v config.php: app_id, app_secret', $html);
        foreach (['g-secret-XYZ', 'ms-secret-XYZ', 'PRIVATE KEY', 'TEAM123456'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        $this->assertStringContainsString('name="sso_console"', $html);
        $this->assertStringNotContainsString('name="sso_console" value="1" checked', $html, 'konzole výchozí vypnuto');
        $this->assertStringContainsString('name="sso_partner" value="1" checked', $html, 'portál výchozí zapnuto');
    }

    public function testBetaRegistrationAndAuditLabels(): void
    {
        $this->assertArrayHasKey('sso', BETA_FEATURES);
        pset_save('beta_sso', '');
        $this->assertSame('off', beta_stage('sso'), 'nová funkce je výchozí vypnutá');
        $this->assertContains('sso', PLATFORM_PATHS, 'adresa /sso na hlavní doméně nepatří žádnému domu');
        $this->assertNotNull(slug_problem('sso'));
    }
}
