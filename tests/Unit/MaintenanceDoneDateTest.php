<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Revize: provedení se zapisuje se skutečným datem (výchozí dnes, ne v budoucnu); příští termín se počítá od něj. */
final class MaintenanceDoneDateTest extends TestCase
{
    public function testDoneDateIsChosen(): void
    {
        $root = dirname(__DIR__, 2);
        $v = (string) file_get_contents($root . '/app/views/admin/crud_list.php');
        $this->assertStringContainsString('name="done_date" value="<?= date(\'Y-m-d\') ?>" max="<?= date(\'Y-m-d\') ?>"', $v);
        $this->assertStringNotContainsString('Provedeno dnes', $v);
        $r = (string) file_get_contents($root . '/app/routes_extra.php');
        $this->assertStringContainsString("strtotime(\$done . ' +' . (int) \$m['interval_months'] . ' months')", $r, 'příští termín od skutečného data');
        $this->assertStringContainsString("\$done > date('Y-m-d')", $r, 'budoucí datum se odmítne');
    }
}
