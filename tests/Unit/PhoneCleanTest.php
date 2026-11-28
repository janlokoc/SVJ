<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Telefon s předvolbou: číslo bez ní se doplní (+420), uloží se jednotně, nesmysl se odmítne. */
final class PhoneCleanTest extends TestCase
{
    public function testNormalizes(): void
    {
        $this->assertSame('+420 777 123 456', phone_clean('777123456'), 'devět číslic = české číslo');
        $this->assertSame('+420 777 123 456', phone_clean('777 123 456'));
        $this->assertSame('+420 777 123 456', phone_clean('00420 777-123-456'));
        $this->assertSame('+420 777 123 456', phone_clean('420777123456'));
        $this->assertSame('+421 905 123 456', phone_clean('+421 905 123 456'));
        $this->assertSame('+4915123456789', phone_clean('+49 151 2345 6789'), 'cizí číslo bez mezer');
        $this->assertSame('', phone_clean('  '));
        $this->assertNull(phone_clean('731 47'));
        $this->assertNull(phone_clean('volejte večer'));
        $this->assertSame('+420777123456', sms_normalize('777123456'), 'SMS používá stejné čtení');
        $this->assertNull(sms_normalize(''));
    }

    public function testFormsUseIt(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['app/routes_join.php', 'app/routes_member.php', 'app/routes_admin.php'] as $f) {
            $this->assertStringContainsString('phone_clean(', (string) file_get_contents($root . '/' . $f), $f);
        }
        foreach (['app/views/join.php', 'app/views/account.php', 'app/views/admin/user_form.php'] as $f) {
            $this->assertStringContainsString('placeholder="+420 777 123 456"', (string) file_get_contents($root . '/' . $f), $f);
        }
    }
}
