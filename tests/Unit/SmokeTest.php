<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function testTenantIsBooted(): void
    {
        $this->assertSame('dum', TENANT);
        $this->assertTrue(platform_enabled());
        $this->assertGreaterThan(0, (int) val('SELECT COUNT(*) FROM units'));
    }
}
