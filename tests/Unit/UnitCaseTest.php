<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Závady v bytě: nájemník ↔ vlastník (app/ext/unitcase_lib.php), výbor obsah nevidí. */
final class UnitCaseTest extends TestCase
{
    private int $unit;
    private array $tenant;
    private array $owner;
    private bool $linked = false;

    protected function setUp(): void
    {
        parent::setUp();
        unitcase_ready();
        $this->tenant = one("SELECT u.* FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE u.kind = 'tenant' AND u.active = 1 ORDER BY u.id LIMIT 1");
        $this->unit = (int) val('SELECT unit_id FROM user_units WHERE user_id = ?', [$this->tenant['id']]);
        $this->owner = one("SELECT * FROM users WHERE kind = 'owner' AND active = 1 AND role <> 'admin' AND id NOT IN (SELECT user_id FROM user_units WHERE unit_id = ?) ORDER BY id DESC LIMIT 1", [$this->unit]);
        q("DELETE FROM settings WHERE `key` = 'unitcase_enabled'");
        setting('__reset');
    }

    protected function tearDown(): void
    {
        if ($this->linked) {
            q('DELETE FROM user_units WHERE user_id = ? AND unit_id = ?', [$this->owner['id'], $this->unit]);
        }
        q('INSERT INTO user_units (user_id, unit_id) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM user_units WHERE user_id = ? AND unit_id = ?)', [$this->tenant['id'], $this->unit, $this->tenant['id'], $this->unit]);
        q('DELETE FROM unit_case_messages WHERE case_id IN (SELECT id FROM unit_cases WHERE unit_id = ?)', [$this->unit]);
        q('DELETE FROM unit_case_reads WHERE case_id IN (SELECT id FROM unit_cases WHERE unit_id = ?)', [$this->unit]);
        q('DELETE FROM unit_cases WHERE unit_id = ?', [$this->unit]);
        q("DELETE FROM settings WHERE `key` = 'unitcase_enabled'");
        setting('__reset');
        parent::tearDown();
    }

    private function linkOwner(): void
    {
        q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$this->owner['id'], $this->unit]);
        $this->linked = true;
    }

    public function testTenantWithoutOwnerAccountIsToldWhy(): void
    {
        $units = unitcase_my_units($this->tenant);
        $this->assertSame(0, $units[$this->unit]['other']);
        $this->assertSame('tenant', $units[$this->unit]['side']);
        $this->assertStringContainsString('nemá na webu účet', (string) unitcase_create($this->tenant, $this->unit, 'zavada', 'Kape kohoutek', 'Od včera.'));
        $this->assertArrayNotHasKey($this->unit, unitcase_my_units($this->owner), 'cizí byt vlastník nevidí');
    }

    public function testTenantReportsOwnerRepliesStatusAndPrivacy(): void
    {
        if ((int) val('SELECT COUNT(*) FROM push_subs') > 0) {
            $this->markTestSkipped('push subscriptions present – push_notify() would contact a push service');
        }
        $this->linkOwner();
        $id = unitcase_create($this->tenant, $this->unit, 'zavada', 'Kape kohoutek v kuchyni', "Kape od včera.\nJsem doma odpoledne.");
        $this->assertIsInt($id);
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame($this->owner['email'], $mails[0]['to']);
        $case = one('SELECT * FROM unit_cases WHERE id = ?', [$id]);
        $this->assertSame(['zavada', 'new', 'tenant'], [$case['kind'], $case['status'], $case['side']]);

        // Kdo vidí: nájemník i vlastník jednotky ano; jiný obyvatel ani výbor ne.
        $this->assertTrue(unitcase_can_see($case, $this->tenant));
        $this->assertTrue(unitcase_can_see($case, $this->owner));
        $admin = one("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
        $this->assertFalse(unitcase_can_see($case, $admin), 'výbor obsah nevidí');
        $other = one('SELECT * FROM users WHERE active = 1 AND id NOT IN (?, ?) AND id NOT IN (SELECT user_id FROM user_units WHERE unit_id = ?) ORDER BY id LIMIT 1', [$this->tenant['id'], $this->owner['id'], $this->unit]);
        $this->assertFalse(unitcase_can_see($case, $other));

        // Nepřečtené u vlastníka, odpověď vlastníka jde nájemníkovi.
        $this->assertSame(1, unitcase_unread($this->owner));
        unitcase_add_message($id, $this->owner, 'Zítra přijde instalatér.');
        $this->assertSame(0, unitcase_unread($this->owner), 'vlastní odpovědí je přečteno');
        $this->assertSame(1, unitcase_unread($this->tenant));
        $mails = $this->mails();
        $this->assertSame($this->tenant['email'], $mails[1]['to']);

        // Stav: vlastník označí jako řeší se → záznam ve vláknu a upozornění.
        $this->assertTrue(unitcase_set_status($case, $this->owner, 'in_progress'));
        $this->assertFalse(unitcase_set_status(array_merge($case, ['status' => 'in_progress']), $this->owner, 'in_progress'), 'stejný stav nic nemění');
        $this->assertSame('in_progress', (string) val('SELECT status FROM unit_cases WHERE id = ?', [$id]));
        $this->assertSame(3, (int) val('SELECT COUNT(*) FROM unit_case_messages WHERE case_id = ?', [$id]));
        $this->assertCount(3, $this->mails());

        // Audit bez obsahu zprávy (soukromé).
        $this->assertStringNotContainsString('kohoutek', (string) val("SELECT detail FROM audit_log WHERE action = 'unitcase_new' ORDER BY id DESC LIMIT 1"));

        // Odpojený nájemník vlákno už nevidí, vlastník ano.
        q('DELETE FROM user_units WHERE user_id = ? AND unit_id = ?', [$this->tenant['id'], $this->unit]);
        $this->assertFalse(unitcase_can_see($case, $this->tenant));
        $this->assertTrue(unitcase_can_see($case, $this->owner));
        $this->assertCount(1, unitcase_list($this->owner));
    }

    public function testValidationAndFileLimit(): void
    {
        $this->linkOwner();
        $this->assertStringContainsString('Vyplňte', (string) unitcase_create($this->tenant, $this->unit, 'zavada', '', 'x'));
        $this->assertStringContainsString('Vyplňte', (string) unitcase_create($this->tenant, $this->unit, 'zavada', 'x', str_repeat('a', UNITCASE_MAX + 1)));
        $this->assertStringContainsString('přístup', (string) unitcase_create($this->tenant, $this->unit + 999, 'zavada', 'x', 'y'));
        $five = ['name' => array_fill(0, 5, 'a.jpg'), 'tmp_name' => array_fill(0, 5, '/nic'), 'error' => array_fill(0, 5, UPLOAD_ERR_OK), 'size' => array_fill(0, 5, 10)];
        $this->assertStringContainsString('nejvýše ' . UNITCASE_FILES, (string) unitcase_store_files($five));
        $this->assertSame([], unitcase_store_files([]));
        $id = unitcase_create($this->owner, $this->unit, 'cokoli', 'Odečet vodoměru', 'Prosím o stav vodoměru.');
        $this->assertIsInt($id);
        $this->assertSame(['zavada', 'owner'], [(string) val('SELECT kind FROM unit_cases WHERE id = ?', [$id]), (string) val('SELECT side FROM unit_cases WHERE id = ?', [$id])], 'neznámý druh → závada; i vlastník může začít');
    }

    public function testMenuSwitchAndRoutesGuard(): void
    {
        $this->assertSame('Závady v bytě (nájemník ↔ vlastník)', $GLOBALS['EXT_MENU']['zavady-bytu'][0]);
        ext_menu_save([]);
        $this->assertFalse(unitcase_enabled());
        $this->assertFalse(($GLOBALS['EXT_RESIDENT_NAV']['/byt'][1])());
        ext_menu_save(['zavady-bytu']);
        $this->assertTrue(unitcase_enabled());
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/ext/routes_unitcase.php');
        $this->assertStringContainsString("!unitcase_can_see(['unit_id' => \$f['unit_id']], \$u)", $src, 'přílohy jen účastníkům');
        $this->assertStringContainsString('!unitcase_can_see($case, $u)', $src);
    }
}
