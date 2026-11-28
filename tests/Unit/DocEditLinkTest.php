<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Veřejný seznam dokumentů: správce má u každého dokumentu odkaz na úpravu (viditelnost, kategorie); obyvatel ne. */
final class DocEditLinkTest extends TestCase
{
    private function render(): string
    {
        $docs = [['id' => 7, 'title' => 'Zápis', 'original_name' => 'zapis.pdf', 'doc_date' => '2025-01-01', 'size' => 1000, 'unit_id' => null, 'visibility' => 'public']];
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/_doclist.php';
        return (string) ob_get_clean();
    }

    public function testAdminSeesEditLinkResidentDoesNot(): void
    {
        $this->loginAs('admin');
        $this->assertStringContainsString('href="' . url('/admin/dokumenty/7') . '"', $this->render());
        $this->loginAs('resident');
        $this->assertStringNotContainsString('/admin/dokumenty/7', $this->render());
    }
}
