<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Hlídání insolvence osob: po výběru jednotky se nabídnou lidé, kteří k ní patří (vlastníci z katastru a uživatelé
 * webu přiřazení k jednotce) – jméno se nevypisuje ručně. Tlačítko kontroly je malé u informace o poslední kontrole.
 */
final class IsirPersonPickTest extends TestCase
{
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        rej_ready();
        $this->units = all('SELECT id, owner_note FROM units ORDER BY id LIMIT 2');
    }

    protected function tearDown(): void
    {
        foreach ($this->units as $u) {
            q('UPDATE units SET owner_note = ? WHERE id = ?', [$u['owner_note'], $u['id']]);
        }
        q("DELETE FROM rej_persons WHERE surname IN ('Bílá', 'Bílý')");
        q('DELETE FROM user_units WHERE unit_id = ? AND user_id = ?', [(int) $this->units[1]['id'], $this->uid ?? 0]);
        parent::tearDown();
    }

    private ?int $uid = null;

    public function testPeopleOfUnitFromCadastreAndUsers(): void
    {
        [$a, $b] = [(int) $this->units[0]['id'], (int) $this->units[1]['id']];
        q('UPDATE units SET owner_note = ? WHERE id = ?', ['SJM Bílý Jan a Ing. Bílá Anna (1/2); Stavby Novák s.r.o.', $a]);
        q('UPDATE units SET owner_note = ? WHERE id = ?', ['', $b]);
        $u = one('SELECT id, name FROM users WHERE active = 1 ORDER BY id LIMIT 1');
        $this->uid = (int) $u['id'];
        q('DELETE FROM user_units WHERE unit_id = ? AND user_id = ?', [$b, $this->uid]);
        q('INSERT INTO user_units (user_id, unit_id) VALUES (?, ?)', [$this->uid, $b]);
        $people = rej_unit_people();
        $this->assertSame([['surname' => 'Bílý', 'first' => 'Jan'], ['surname' => 'Bílá', 'first' => 'Anna']], array_slice($people[$a], 0, 2), 'bez titulů a bez firmy');
        $words = preg_split('/\s+/u', trim((string) $u['name']));
        $this->assertContains(['surname' => (string) end($words), 'first' => implode(' ', array_slice($words, 0, -1))], $people[$b], 'uživatel přiřazený k jednotce');
        rej_person_add($a, 'Bílý', 'Jan', '1970-05-01', 1);
        $this->assertNotContains(['surname' => 'Bílý', 'first' => 'Jan'], rej_unit_people()[$a], 'už hlídaný se znovu nenabízí');
    }

    public function testViewWiring(): void
    {
        $root = dirname(__DIR__, 2);
        $v = (string) file_get_contents($root . '/app/views/ext/rejstriky/isir_persons.php');
        $this->assertStringContainsString('data-people="<?= e(json_encode(rej_unit_people()', $v);
        $this->assertStringContainsString('data-person-manual', $v);
        $this->assertStringContainsString('class="table-foot"', $v);
        $this->assertStringContainsString('btn btn-small btn-ghost" type="submit" name="action" value="run">Zkontrolovat teď', $v);
        $this->assertStringContainsString('class="form form-sub"', $v, 'formulář oddělený od tabulky');
        $js = (string) file_get_contents($root . '/assets/app.js');
        $this->assertStringContainsString("document.querySelectorAll('[data-person-form]')", $js);
        $this->assertStringContainsString("'Jiná osoba…'", $js);
    }
}
