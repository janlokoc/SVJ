<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Termíny v administraci: výměna adresy odběru kalendáře je v rozbalovacím oddílu dole, ne tlačítko uprostřed věty nahoře. */
final class EventsFeedResetTest extends TestCase
{
    public function testFeedResetIsInCollapsibleSection(): void
    {
        $v = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/admin/events.php');
        $this->assertStringContainsString('<details class="cal-feed">', $v);
        $this->assertGreaterThan(strpos($v, 'Žádný termín'), strpos($v, 'cal-feed'), 'až pod seznamem termínů');
        $this->assertStringNotContainsString('můžete ji vyměnit: <button', $v);
    }
}
