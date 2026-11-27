<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Příliš velký požadavek (nad post_max_size): PHP zahodí celý formulář včetně _csrf. Odeslání na pozadí (okno hlášení)
 * dostane srozumitelnou chybu v JSON místo stránky – dřív okno hlásilo „zkontrolujte připojení“.
 */
final class PostLimitTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_ACCEPT']);
        parent::tearDown();
    }

    public function testIniSizes(): void
    {
        $this->assertSame(8 << 20, ini_size_bytes('8M'));
        $this->assertSame(512 << 10, ini_size_bytes('512k'));
        $this->assertSame(1 << 30, ini_size_bytes('1G'));
        $this->assertSame(1000, ini_size_bytes('1000'));
    }

    public function testDetectsOversizedPost(): void
    {
        $_POST = $_FILES = [];
        $_SERVER['CONTENT_LENGTH'] = (string) (ini_size_bytes((string) ini_get('post_max_size')) + 1);
        $this->assertTrue(post_too_large());
        $_SERVER['CONTENT_LENGTH'] = '100';
        $this->assertFalse(post_too_large());
        $_POST = ['body' => 'x'];
        $_SERVER['CONTENT_LENGTH'] = (string) (ini_size_bytes((string) ini_get('post_max_size')) + 1);
        $this->assertFalse(post_too_large(), 'form data arrived');
    }

    public function testCsrfErrorExplainsOversizedUpload(): void
    {
        $_POST = $_FILES = [];
        $_SERVER['CONTENT_LENGTH'] = (string) (ini_size_bytes((string) ini_get('post_max_size')) + 1);
        [$code, , $msg] = csrf_error();
        $this->assertSame(413, $code);
        $this->assertStringContainsString('příliš velký', $msg);
        $_SERVER['CONTENT_LENGTH'] = '100';
        $this->assertSame(400, csrf_error()[0], 'ordinary expired form');
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertTrue(wants_json());
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $this->assertFalse(wants_json());
    }

    public function testReportScriptWaitsForShrink(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/app.js');
        $this->assertStringContainsString('await shrinkDone;', $js, 'odeslání počká na zmenšení snímku');
        $this->assertStringContainsString("r.status === 413", $js, 'příliš velký soubor má vlastní hlášku');
    }
}
