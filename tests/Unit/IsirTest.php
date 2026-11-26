<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Hlídání insolvencí (app/isir_lib.php). Bez sítě: dotaz do rejstříku se volá jen s neplatným IČO
 * (vrátí chybu před spojením) a denní úloha jen v případech, kdy skončí dřív, než by se ptala.
 */
final class IsirTest extends TestCase
{
    private array $savedSettings = [];
    private array $savedPset = [];

    protected function tearDown(): void
    {
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
        $this->savedSettings = $this->savedPset = [];
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

    /** Nastaví hodnotu a zapamatuje si původní pro tearDown. */
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

    // ---------- ISIR_COMPANY ----------

    public function testCompanyRegexMatchesLegalForms(): void
    {
        $companies = [
            'Stavby Novák s.r.o.', 'Stavby Novák s. r. o.', 'STAVBY NOVÁK S.R.O.', 'Novák, spol. s r.o.', 'Novák spol.s r.o.',
            'ČEZ, a.s.', 'ČEZ, a. s.', 'Novák a spol., v.o.s.', 'Novák k.s.', 'Spolek přátel z.s.', 'Ústav z.ú.', 'Nemocnice o.p.s.',
            'Bytové družstvo Lipa', 'Stavební bytové družstvo', 'Akciová společnost Brno', 'Nadace Dům', 'Obec Kunštát', 'Město Tišnov',
            'Statutární město Brno', 'Jihomoravský kraj', 'Česká republika – Úřad pro zastupování státu', 'SVJ Lipová 5', 'Společenství vlastníků Lipová 5',
            'ABC s.r.o. v likvidaci', 'Firma (s.r.o.)',
        ];
        foreach ($companies as $name) {
            $this->assertSame(1, preg_match(ISIR_COMPANY, $name), "'$name' should be recognised as a company");
        }
    }

    public function testCompanyRegexDoesNotMatchPeopleOrWordParts(): void
    {
        $people = [
            'Novák Petr', 'Novák Petr a Nováková Marie (SJM)', 'Výborová Jana', 'Krajíček Jan', 'Obecný Petr', 'Městecký Karel', 'Svjatoslav Ivanov',
            'Družstevníková Eva', 'Společnostní Pavel', 'Nadacký Ivo', 'Lukas Josef', 'Vlasta Svobodová', 'Ing. Pavel Marek, CSc.', '',
        ];
        foreach ($people as $name) {
            $this->assertSame(0, preg_match(ISIR_COMPANY, $name), "'$name' must not be recognised as a company");
        }
    }

    // ---------- isir_ico_in ----------

    public function testIcoInFindsValidIcoOnly(): void
    {
        $this->assertSame('27082440', isir_ico_in('Stavby s.r.o., IČO: 27082440'));
        $this->assertSame('27082440', isir_ico_in('IČ 27 082 440, sídlo Brno'));
        $this->assertSame('27082440', isir_ico_in('ič.27082440'));
        $this->assertSame('27082440', isir_ico_in('IC:27082440'));
        $this->assertSame('00032824', isir_ico_in("Firma\nIČO 00032824"));
        $this->assertSame('', isir_ico_in('IČO: 27082441'), 'bad checksum');
        $this->assertSame('', isir_ico_in('27082440'), 'number without IČO label is ignored');
        $this->assertSame('', isir_ico_in('IČO: 2708244'), 'seven digits');
        $this->assertSame('', isir_ico_in('tel. 602 123 456'));
        $this->assertSame('', isir_ico_in(''));
        $this->assertSame('00032824', isir_ico_in('IČO: 27082441 (chybně), správně IČO 00032824'), 'first valid one wins');
    }

    public function testIcoInAcceptsUsualCzechGrouping(): void
    {
        $this->assertSame('27082440', isir_ico_in('IČ 270 82 440, sídlo Brno'));
        $this->assertSame('00064581', isir_ico_in('IČO: 000 64 581'));
    }

    // ---------- isir_company_units ----------

    public function testCompanyUnitsFromOwnerTextAndManualIco(): void
    {
        $u1 = one('SELECT * FROM units WHERE id = 1');
        $u2 = one('SELECT * FROM units WHERE id = 2');
        $u3 = one('SELECT * FROM units WHERE id = 3');
        try {
            q('UPDATE units SET owner_note = ?, owner_contact = ? WHERE id = 1', ['Stavby Novák s.r.o.', 'Brno, IČO 27082440']);
            q('UPDATE units SET owner_note = ? WHERE id = 2', ['Bytové družstvo Lipa']);
            q('UPDATE units SET owner_note = ?, owner_contact = ? WHERE id = 3', ['Dvořák Tomáš', 'IČ: 00032824']);
            $this->tenantSetting('isir_ico', json_encode(['2' => '45274649']));

            $list = isir_company_units();
            $this->assertArrayHasKey(1, $list);
            $this->assertSame('27082440', $list[1]['ico']);
            $this->assertSame('text', $list[1]['source']);
            $this->assertSame('Stavby Novák s.r.o.', $list[1]['name']);
            $this->assertSame(1, (int) $list[1]['unit']['id']);

            $this->assertSame('45274649', $list[2]['ico']);
            $this->assertSame('manual', $list[2]['source']);

            $this->assertArrayHasKey(3, $list, 'person-like name with an IČO (entrepreneur) is watched too');
            $this->assertSame('00032824', $list[3]['ico']);
            $this->assertSame('text', $list[3]['source']);

            foreach ($list as $id => $c) {
                $this->assertContains($id, [1, 2, 3], 'demo owners are people without IČO');
            }

            // firma bez IČO: v seznamu, ale bez IČO
            $this->tenantSetting('isir_ico', null);
            $list = isir_company_units();
            $this->assertSame('', $list[2]['ico']);
            $this->assertSame('', $list[2]['source']);
        } finally {
            foreach ([$u1, $u2, $u3] as $u) {
                q('UPDATE units SET owner_note = ?, owner_contact = ? WHERE id = ?', [$u['owner_note'], $u['owner_contact'], $u['id']]);
            }
        }
    }

    public function testCompanyUnitsEmptyForDemoHouse(): void
    {
        $this->tenantSetting('isir_ico', null);
        $this->assertSame([], isir_company_units());
    }

    // ---------- isir_enabled / beta ----------

    public function testEnabledFollowsBetaStage(): void
    {
        $this->platformSetting('isir_enabled', null);
        $this->platformSetting('beta_insolvence', 'off');
        $this->assertFalse(isir_enabled());
        $this->platformSetting('beta_insolvence', 'beta');
        $this->assertTrue(isir_enabled());
        $this->platformSetting('beta_insolvence', 'on');
        $this->assertTrue(isir_enabled());
        // bez nastavení rozhoduje starý přepínač
        $this->platformSetting('beta_insolvence', null);
        $this->assertFalse(isir_enabled());
        $this->platformSetting('isir_enabled', '1');
        $this->assertTrue(isir_enabled());
    }

    // ---------- isir_daily / isir_run ----------

    public function testDailyDoesNothingWhenDisabled(): void
    {
        $this->platformSetting('beta_insolvence', 'off');
        $this->tenantSetting('isir_last', null);
        $this->tenantSetting('isir_ico', json_encode(['1' => '27082440']));
        isir_daily();
        $this->assertNull(one('SELECT value FROM settings WHERE `key` = ?', ['isir_last']));
    }

    public function testDailySkipsWhenCheckedThisWeek(): void
    {
        $this->platformSetting('beta_insolvence', 'on');
        $recent = date('Y-m-d H:i', time() - 2 * 86400) . '|ok|ověřeno 1, řízení 0';
        $this->tenantSetting('isir_last', $recent);
        $this->tenantSetting('isir_ico', json_encode(['1' => '27082440']));
        isir_daily();   // poslední kontrola před 2 dny → bez dotazu do rejstříku
        $this->assertSame($recent, val('SELECT value FROM settings WHERE `key` = ?', ['isir_last']));
    }

    public function testDailySkipsWhenNoOwnerHasIco(): void
    {
        $this->platformSetting('beta_insolvence', 'on');
        $this->tenantSetting('isir_last', null);
        $this->tenantSetting('isir_ico', null);
        isir_daily();
        $this->assertNull(one('SELECT value FROM settings WHERE `key` = ?', ['isir_last']));
    }

    public function testRunWithoutIcoRecordsEmptyCheck(): void
    {
        $this->tenantSetting('isir_ico', null);
        $this->tenantSetting('isir_last', null);
        $this->tenantSetting('isir_results', '{"27082440":{"checked":"2026-01-01 10:00","found":[]}}');
        $this->assertSame([0, 0, ''], isir_run());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}\|ok\|ověřeno 0, řízení 0$/u', (string) val('SELECT value FROM settings WHERE `key` = ?', ['isir_last']));
        $this->assertSame(['27082440' => ['checked' => '2026-01-01 10:00', 'found' => []]], json_decode((string) val('SELECT value FROM settings WHERE `key` = ?', ['isir_results']), true),
            'earlier results are kept');
        $this->assertSame([], $this->mails());
    }

    // ---------- isir_check_ico ----------

    public function testCheckIcoValidatesInputBeforeNetwork(): void
    {
        foreach (['', '123', '1234567', '123456789', 'abcdefgh'] as $bad) {
            $r = isir_check_ico($bad);
            $this->assertSame(['ok' => false, 'found' => [], 'error' => 'IČO musí mít 8 číslic.', 'raw' => ''], $r, "'$bad'");
        }
    }
}
