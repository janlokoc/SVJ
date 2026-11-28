<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Upozornění z importu z katastru se po uložení neztratí: připomínají se, dokud je správce neodškrtne. */
final class CadastreTodoTest extends TestCase
{
    protected function tearDown(): void
    {
        cadastre_todo_save([]);
        parent::tearDown();
    }

    public function testListIsStoredAndResolvedOneByOne(): void
    {
        cadastre_todo_save(['SJ Novák Jan a Nováková Eva: jeden podíl 331/5000 pro 12/6, 12/9', '', 'Dvořáková Marie: 12/4, 12/10', 42]);
        $this->assertSame(['SJ Novák Jan a Nováková Eva: jeden podíl 331/5000 pro 12/6, 12/9', 'Dvořáková Marie: 12/4, 12/10'], cadastre_todo(), 'prázdné a nesmyslné položky pryč');
        $list = cadastre_todo();
        unset($list[0]);
        cadastre_todo_save($list);
        $this->assertSame(['Dvořáková Marie: 12/4, 12/10'], cadastre_todo());
        cadastre_todo_save([]);
        $this->assertSame([], cadastre_todo());
        $this->assertSame('', setting('cadastre_todo'));
    }

    public function testWiring(): void
    {
        $root = dirname(__DIR__, 2);
        $r = (string) file_get_contents($root . '/app/routes_admin.php');
        $this->assertStringContainsString("cadastre_todo_save(\$parsed['warnings']);", $r, 'uloží se při importu');
        $this->assertStringContainsString("\$route('POST', '/admin/jednotky/k-overeni'", $r);
        $this->assertStringContainsString('id="k-overeni"', (string) file_get_contents($root . '/app/views/admin/units.php'));
        $this->assertStringContainsString('count(cadastre_todo())', (string) file_get_contents($root . '/app/views/admin/dashboard.php'), 'připomínka na Přehledu');
    }

    public function testPageShowsTodo(): void
    {
        cadastre_todo_save(['Horák Petr: 11/101, 12/3']);
        $this->loginAs('admin');
        $units = all_units();
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/admin/units.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('K ověření po importu z katastru (1)', $html);
        $this->assertStringContainsString('Horák Petr: 11/101, 12/3', $html);
    }
}
