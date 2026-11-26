<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul F – vyúčtování služeb (app/ext/modf_settle_lib.php): párování souborů, zveřejnění, potvrzení, námitky, lhůty. */
final class ModFSettlementTest extends TestCase
{
    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body'])], $this->mails());
    }
    /** Uložený soubor, jak ho vrací store_upload(). */
    private function stored(string $orig): array
    {
        $name = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents(UPLOAD_DIR . '/' . $name, "%PDF-1.4\n%test\n");
        return [$name, $orig, 15];
    }
    private function settlement(array $over = []): int
    {
        [$d, $err] = modf_settlement_validate($over + ['title' => 'Vyúčtování ' . bin2hex(random_bytes(3)), 'period_from' => '2025-01-01', 'period_to' => '2025-12-31']);
        $this->assertSame([], $err);
        return modf_settlement_save($d);
    }
    private function unit(string $entrance, string $number): array
    {
        return one('SELECT * FROM units WHERE entrance = ? AND number = ?', [$entrance, $number]);
    }
    /** Vlastník s účtem a jeho jednotka. */
    private function owner(): array
    {
        $u = one("SELECT u.* FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE u.role = 'resident' AND u.active = 1 AND u.kind = 'owner' ORDER BY u.id LIMIT 1");
        $this->assertNotNull($u, 'Ukázkový dům má vlastníka s jednotkou.');
        $unit = one('SELECT un.* FROM units un JOIN user_units uu ON uu.unit_id = un.id WHERE uu.user_id = ? ORDER BY un.id LIMIT 1', [$u['id']]);
        return [$u, $unit];
    }

    public function testDeadlinesFollowLaw(): void
    {
        $this->assertSame('2026-04-30', modf_delivery_deadline('2025-12-31'), '4 měsíce od konce období; 31. 4. neexistuje → 30. 4.');
        $this->assertSame('2026-10-30', modf_delivery_deadline('2026-06-30'));
        $this->assertSame('2026-05-31', modf_objection_deadline('2026-05-01'));
        $this->assertSame('2026-03-03', modf_answer_deadline('2026-02-01'));
    }

    public function testValidation(): void
    {
        [, $err] = modf_settlement_validate(['title' => '', 'period_from' => '2025-12-31', 'period_to' => '2025-01-01']);
        $this->assertCount(2, $err);
        [, $err] = modf_settlement_validate(['title' => 'X', 'period_from' => '2020-01-01', 'period_to' => '2025-01-01']);
        $this->assertStringContainsString('dva roky', $err[0]);
        [, $err] = modf_settlement_validate(['title' => 'X', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'delivery_date' => '2025-06-01']);
        $this->assertStringContainsString('dřív', $err[0]);
        [$d, $err] = modf_settlement_validate(['title' => ' Vyúčtování 2025 ', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'delivery_date' => '']);
        $this->assertSame([], $err);
        $this->assertSame('Vyúčtování 2025', $d['title']);
    }

    public function testMatchUnitFromFileName(): void
    {
        $units = all_units();
        $this->assertSame(['A', '5'], array_values(array_intersect_key(modf_match_unit('A-5.pdf', $units), ['entrance' => 1, 'number' => 1])));
        $this->assertSame('B/3', ($m = modf_match_unit('vyuctovani_12B_3.pdf', $units)) ? $m['entrance'] . '/' . $m['number'] : null);
        $this->assertSame('B/1', ($m = modf_match_unit('Vyúčtování 2025 b1.PDF', $units)) ? $m['entrance'] . '/' . $m['number'] : null);
        $this->assertNull(modf_match_unit('vyuctovani.pdf', $units));
        $this->assertNull(modf_match_unit('C-1.pdf', $units), 'Vchod C dům nemá.');
        $this->assertNull(modf_match_unit('A1_B2.pdf', $units), 'Dvě různé jednotky v názvu – nejednoznačné.');
        // Dům bez vchodů: stačí číslo, rok v názvu nevadí.
        $flat = [['id' => 1, 'entrance' => '', 'number' => '7'], ['id' => 2, 'entrance' => '', 'number' => '12']];
        $this->assertSame(1, (int) modf_match_unit('vyuctovani_2025_byt_07.pdf', $flat)['id']);
        $this->assertNull(modf_match_unit('byt 7 a 12.pdf', $flat));
        $this->assertTrue(modf_settlement_file_ok('x.PDF'));
        $this->assertFalse(modf_settlement_file_ok('x.docx'));
    }

    public function testUploadAssignPublishConfirm(): void
    {
        [$owner, $unit] = $this->owner();
        $sid = $this->settlement();
        $label = $unit['entrance'] . '-' . $unit['number'] . '.pdf';
        $matched = modf_settlement_add_file($sid, $this->stored($label));
        $this->assertSame((int) $unit['id'], (int) $matched['unit_id']);
        $this->assertSame('', $matched['delivered_on'], 'Před zveřejněním se nic nedoručuje.');
        $loose = modf_settlement_add_file($sid, $this->stored('sken0001.pdf'));
        $this->assertSame(0, (int) $loose['unit_id']);
        $this->assertSame('unmatched', modf_item_state($loose));
        $this->assertSame('Vyberte jednotku.', modf_item_assign((int) $loose['id'], 999999));
        $other = one('SELECT * FROM units WHERE id NOT IN (SELECT unit_id FROM user_units WHERE user_id = ?) ORDER BY id LIMIT 1', [$owner['id']]);
        $this->assertNull(modf_item_assign((int) $loose['id'], (int) $other['id']));
        $this->assertSame('draft', modf_item_state(modf_item((int) $loose['id'])));

        // Vlastník nevidí nic, dokud není zveřejněno.
        $this->assertFalse(modf_can_view_item($owner, $matched));
        $this->assertSame([], array_filter(modf_items_for_user($owner), fn($i) => (int) $i['settlement_id'] === $sid));

        $this->assertStringContainsString('budoucnu', (string) modf_settlement_publish($sid, date('Y-m-d', strtotime('+2 day'))));
        $this->assertStringContainsString('dřív', (string) modf_settlement_publish($sid, '2025-06-30'));
        $n = modf_settlement_publish($sid, '2026-01-20');
        $this->assertSame(2, $n);
        $this->assertSame('Vyúčtování už je zveřejněné.', modf_settlement_publish($sid, '2026-01-20'));
        $mails = array_filter($this->decodedMails(), fn($m) => str_starts_with($m['subject'], 'Vyúčtování služeb') && $m['to'] === $owner['email']);
        $this->assertCount(1, $mails, 'Vlastník jednotky dostal e-mail.');
        $this->assertStringContainsString('19. 2. 2026', array_values($mails)[0]['body'], 'E-mail uvádí konec lhůty pro námitky (30 dnů od doručení).');

        $item = modf_item((int) $matched['id']);
        $this->assertSame('2026-01-20', $item['delivered_on']);
        $this->assertTrue(modf_can_view_item($owner, $item));
        $this->assertSame('Doručené vyúčtování nelze smazat – slouží jako doklad o doručení.', modf_item_delete((int) $item['id']));
        $this->assertSame('Zveřejněné vyúčtování nelze smazat – slouží jako doklad o doručení.', modf_settlement_delete($sid));

        // Potvrzení převzetí: jen vlastník, jen jednou, s časem.
        $admin = one("SELECT * FROM users WHERE role = 'admin' AND active = 1 AND id NOT IN (SELECT user_id FROM user_units) ORDER BY id LIMIT 1");
        if ($admin) {
            $this->assertTrue(modf_can_view_item($admin, $item), 'Správce soubor otevře.');
            $this->assertFalse(modf_confirm((int) $item['id'], $admin), 'Správce nepotvrzuje převzetí za vlastníka.');
        }
        $this->assertFalse(modf_confirm((int) $loose['id'], $owner), 'Cizí jednotka.');
        $this->assertTrue(modf_confirm((int) $item['id'], $owner));
        $this->assertFalse(modf_confirm((int) $item['id'], $owner));
        $item = modf_item((int) $item['id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $item['confirmed_at']);
        $this->assertSame($owner['name'], $item['confirmed_name']);
        $this->assertSame('confirmed', modf_item_state($item));

        // Nájemník vyúčtování nedostává.
        $this->assertSame([], modf_items_for_user(['id' => $owner['id'], 'kind' => 'tenant', 'role' => 'resident']));
    }

    public function testLateAssignmentAfterPublishDeliversToday(): void
    {
        [$owner, $unit] = $this->owner();
        $sid = $this->settlement();
        modf_settlement_add_file($sid, $this->stored($unit['entrance'] . $unit['number'] . '.pdf'));
        $this->assertSame(1, modf_settlement_publish($sid, date('Y-m-d')));
        $late = modf_settlement_add_file($sid, $this->stored('neznamy.pdf'));
        $this->assertSame('', $late['delivered_on']);
        $other = $this->unit('B', '2');
        $this->assertNull(modf_item_assign((int) $late['id'], (int) $other['id']));
        $this->assertSame(date('Y-m-d'), modf_item((int) $late['id'])['delivered_on']);
        $this->assertStringContainsString('nelze měnit', (string) modf_item_assign((int) $late['id'], (int) $unit['id']));
        // Přiřazení podle názvu u zveřejněného vyúčtování doručí hned.
        $direct = modf_settlement_add_file($sid, $this->stored('A-4.pdf'));
        $this->assertSame(date('Y-m-d'), $direct['delivered_on']);
    }

    public function testPublishNeedsMatchedFiles(): void
    {
        $sid = $this->settlement();
        $this->assertStringContainsString('Nejdřív nahrajte', (string) modf_settlement_publish($sid, '2026-01-15'));
        modf_settlement_add_file($sid, $this->stored('bez-jednotky.pdf'));
        $this->assertStringContainsString('Nejdřív nahrajte', (string) modf_settlement_publish($sid, '2026-01-15'));
        $this->assertSame('Vyúčtování neexistuje.', modf_settlement_publish(999999, '2026-01-15'));
        // Nezveřejněné vyúčtování jde smazat i se soubory.
        $files = array_column(modf_items($sid), 'filename');
        $this->assertNull(modf_settlement_delete($sid));
        $this->assertNull(modf_settlement($sid));
        foreach ($files as $f) {
            $this->assertFileDoesNotExist(UPLOAD_DIR . '/' . $f);
        }
    }

    public function testObjectionWindowAnswerAndNotifications(): void
    {
        [$owner, $unit] = $this->owner();
        $sid = $this->settlement();
        $it = modf_settlement_add_file($sid, $this->stored($unit['entrance'] . '_' . $unit['number'] . '.pdf'));
        modf_settlement_publish($sid, '2026-03-01');
        $itemId = (int) $it['id'];

        $this->assertSame('Popište prosím, s čím nesouhlasíte (alespoň pár slov).', modf_objection_add($itemId, $owner, 'krátké', null, '2026-03-10'));
        $late = modf_objection_add($itemId, $owner, 'Nesouhlasím s odečtem vody v kuchyni.', null, '2026-04-01');
        $this->assertIsString($late);
        $this->assertStringContainsString('31. 3. 2026', $late, 'Lhůta je 30 dnů od doručení včetně posledního dne.');
        $this->assertTrue(modf_objection_open(modf_item($itemId), '2026-03-31'));
        $this->assertFalse(modf_objection_open(modf_item($itemId), '2026-04-01'));
        $stranger = ['id' => 0, 'name' => 'Cizí', 'kind' => 'owner', 'role' => 'resident'];
        $this->assertSame('Vyúčtování nenalezeno.', modf_objection_add($itemId, $stranger, 'Nesouhlasím s odečtem vody v kuchyni.', null, '2026-03-10'));

        $oid = modf_objection_add($itemId, $owner, 'Nesouhlasím s odečtem vody v kuchyni.', $this->stored('foto.pdf'), '2026-03-10');
        $this->assertIsInt($oid);
        $admins = array_column(modf_admins(), 'email');
        $toAdmins = array_filter($this->decodedMails(), fn($m) => str_starts_with($m['subject'], 'Námitka k vyúčtování') && in_array($m['to'], $admins, true));
        $this->assertNotEmpty($toAdmins, 'Správci dostali e-mail o námitce.');
        $this->assertStringContainsString('vyřídit do', array_values($toAdmins)[0]['body']);

        $ov = modf_settlement_overview($sid, date('Y-m-d'));
        $row = array_values(array_filter($ov['rows'], fn($r) => (int) $r['id'] === $itemId))[0];
        $this->assertSame('objection', $row['state']);
        $this->assertSame(1, $ov['sum']['objection']);
        $this->assertSame(modf_answer_deadline(date('Y-m-d')), $row['answer_until']);
        $this->assertSame('2026-03-31', $row['objection_until']);

        $admin = one("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
        $this->assertSame('Napište, jak byla námitka vyřízena.', modf_objection_answer($oid, $admin, ' '));
        $this->assertNull(modf_objection_answer($oid, $admin, 'Odečet jsme ověřili, opravené vyúčtování posíláme.'));
        $this->assertSame('Námitka už je vyřízená.', modf_objection_answer($oid, $admin, 'Znovu.'));
        $answered = array_filter($this->decodedMails(), fn($m) => str_starts_with($m['subject'], 'Vyřízení námitky') && $m['to'] === $owner['email']);
        $this->assertCount(1, $answered);
        $this->assertSame('resolved', modf_settlement_overview($sid)['rows'][0]['state']);
    }

    public function testRemindersOnceAndUpcoming(): void
    {
        $sid = $this->settlement(['period_from' => '2026-01-01', 'period_to' => '2026-06-30']);   // doručit do 30. 10. 2026
        $s = modf_settlement($sid);
        $before = count($this->mails());
        modf_settlement_reminders('2026-10-01');
        $this->assertSame($before, count($this->mails()), 'Měsíc předem se ještě nepřipomíná.');
        modf_settlement_reminders('2026-10-20');
        $sent = array_filter($this->decodedMails(), fn($m) => str_starts_with($m['subject'], 'Lhůta pro doručení vyúčtování') && str_contains($m['body'], $s['title']));
        $this->assertNotEmpty($sent);
        $n = count($this->mails());
        modf_settlement_reminders('2026-10-21');
        $this->assertSame($n, count($this->mails()), 'Připomínka jen jednou.');
        $up = modf_upcoming(db(), '2026-10-01');
        $this->assertContains('Doručit vyúčtování: ' . $s['title'], array_column($up, 'title'));
    }
}
