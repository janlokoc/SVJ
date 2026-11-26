<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul F – smlouvy a pojištění (app/ext/modf_contract_lib.php): výpovědní lhůty, připomínky, prodloužení. */
final class ModFContractTest extends TestCase
{
    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body'])], $this->mails());
    }
    private function input(array $over = []): array
    {
        return $over + ['kind' => 'contract', 'supplier_id' => '0', 'party' => 'Čistota s.r.o.', 'subject' => 'Úklid ' . bin2hex(random_bytes(3)), 'number' => '', 'start_date' => '2025-01-01',
            'end_date' => '2026-12-31', 'notice_value' => '3', 'notice_unit' => 'm', 'auto_renew' => '1', 'renew_months' => '12', 'price' => '2 500', 'price_period' => 'month', 'remind_days' => '30', 'note' => ''];
    }
    private function create(array $over = []): array
    {
        [$d, $err] = modf_contract_validate($this->input($over));
        $this->assertSame([], $err);
        return modf_contract(modf_contract_save($d));
    }

    public function testValidation(): void
    {
        [, $err] = modf_contract_validate(['kind' => 'x', 'subject' => '', 'party' => '', 'start_date' => '2026-02-30', 'end_date' => 'zítra', 'notice_value' => '-1', 'auto_renew' => '1',
            'renew_months' => '0', 'price' => 'drahé', 'remind_days' => '0']);
        $this->assertGreaterThanOrEqual(8, count($err));
        [, $err] = modf_contract_validate($this->input(['end_date' => '2024-01-01']));
        $this->assertSame(['Konec smlouvy musí být po jejím začátku.'], $err);
        [, $err] = modf_contract_validate($this->input(['kind' => 'insurance', 'party' => '']));
        $this->assertSame(['Vyplňte pojišťovnu.'], $err);
        [, $err] = modf_contract_validate($this->input(['supplier_id' => '999999']));
        $this->assertSame(['Vybraný dodavatel už neexistuje.'], $err);
        [$d, $err] = modf_contract_validate($this->input(['price' => '1 234,50']));
        $this->assertSame([], $err);
        $this->assertSame(1234.5, $d['price']);
    }

    public function testSupplierLink(): void
    {
        q("INSERT INTO suppliers (name, field, phone, email, note) VALUES ('Výtahy Brno a.s.', 'výtahy', '', '', '')");
        $sid = (int) db()->lastInsertId();
        $this->assertSame('Výtahy Brno a.s.', modf_suppliers()[$sid]);
        $c = $this->create(['supplier_id' => (string) $sid, 'party' => '', 'subject' => 'Servis výtahu']);
        $this->assertSame('Servis výtahu – Výtahy Brno a.s.', modf_contract_title($c));
    }

    public function testNoticeDeadlineAndEvents(): void
    {
        $c = ['auto_renew' => 1, 'end_date' => '2026-12-31', 'notice_value' => 3, 'notice_unit' => 'm', 'kind' => 'contract'];
        $this->assertSame('2026-09-30', modf_notice_deadline($c), '31. 12. minus 3 měsíce; 31. 9. neexistuje → 30. 9.');
        $this->assertSame('2026-11-19', modf_notice_deadline(['notice_unit' => 'w', 'notice_value' => 6] + $c), 'Pojištění: 6 týdnů.');
        $this->assertSame('2026-12-01', modf_notice_deadline(['notice_unit' => 'd', 'notice_value' => 30] + $c));
        $this->assertNull(modf_notice_deadline(['auto_renew' => 0] + $c), 'Bez automatického prodloužení není co vypovídat.');
        $this->assertNull(modf_notice_deadline(['end_date' => ''] + $c));
        $ev = modf_contract_events($c);
        $this->assertSame(['notice', 'end'], array_column($ev, 'kind'));
        $this->assertSame('Automatické prodloužení', $ev[1]['label']);
        $this->assertSame('Konec pojištění', modf_contract_events(['auto_renew' => 0, 'kind' => 'insurance'] + $c)[0]['label']);
        $this->assertSame([], modf_contract_events(['end_date' => ''] + $c), 'Smlouva na dobu neurčitou.');
    }

    public function testRemindersFirstAndSecondOnlyOnce(): void
    {
        $c = $this->create(['end_date' => '2027-03-31', 'notice_value' => '2', 'remind_days' => '20']);   // výpověď do 31. 1. 2027
        $mine = fn() => array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $c['subject'])));
        modf_contract_reminders('2027-01-01');
        $this->assertSame([], $mine(), '31 dní předem se ještě nepřipomíná.');
        modf_contract_reminders('2027-01-15');
        $first = $mine();
        $this->assertNotEmpty($first);
        $this->assertStringContainsString('Poslední den pro výpověď za 16 dní', $first[0]['subject']);
        $this->assertStringContainsString('31. 1. 2027', $first[0]['body']);
        $n = count($first);
        modf_contract_reminders('2027-01-16');
        $this->assertCount($n, $mine(), 'Stejná připomínka podruhé neodejde.');
        modf_contract_reminders('2027-01-25');
        $this->assertCount(2 * $n, $mine(), 'Týden předem přijde druhá připomínka.');
        modf_contract_reminders('2027-01-31');
        $this->assertCount(2 * $n, $mine());
        // Kdo smlouvu zadá až týden před termínem, dostane jen jednu připomínku.
        $late = $this->create(['end_date' => '2027-02-05', 'auto_renew' => '0', 'notice_value' => '0']);
        modf_contract_reminders('2027-02-01');
        modf_contract_reminders('2027-02-02');
        $this->assertCount($n, array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], $late['subject'])));
    }

    public function testAutoRenewRollsEndDate(): void
    {
        $c = $this->create(['end_date' => '2024-06-30', 'start_date' => '2023-07-01', 'renew_months' => '12']);
        $fixed = $this->create(['end_date' => '2024-06-30', 'start_date' => '2023-07-01', 'auto_renew' => '0']);
        $this->assertGreaterThanOrEqual(1, modf_contracts_roll('2026-11-26'));
        $this->assertSame('2027-06-30', modf_contract((int) $c['id'])['end_date']);
        $this->assertSame('2024-06-30', modf_contract((int) $fixed['id'])['end_date'], 'Smlouva bez prodloužení zůstane.');
    }

    public function testDeleteRemovesFileAndListSorting(): void
    {
        $name = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents(UPLOAD_DIR . '/' . $name, '%PDF');
        [$d] = modf_contract_validate($this->input(['end_date' => '2099-01-31']));
        $id = modf_contract_save($d, 0, [$name, 'smlouva.pdf', 4]);
        $this->assertSame('smlouva.pdf', modf_contract($id)['original_name']);
        $kinds = array_unique(array_column(modf_contracts('insurance'), 'kind'));
        $this->assertNotContains('contract', $kinds);
        $all = modf_contracts();
        $this->assertSame(array_column($all, 'next'), (function ($a) { sort($a); return $a; })(array_column($all, 'next')), 'Seřazeno podle nejbližšího termínu.');
        modf_contract_delete($id);
        $this->assertNull(modf_contract($id));
        $this->assertFileDoesNotExist(UPLOAD_DIR . '/' . $name);
    }
}
