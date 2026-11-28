<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Vzorová zadání pro AI asistenta: platná oprávnění, jen existující nástroje MCP, adresa MCP domu, bezpečnostní pravidla. */
final class ApiPromptsTest extends TestCase
{
    public function testPromptsAreConsistentWithMcp(): void
    {
        $all = array_column(api_mcp_tools(['scope_list' => array_keys(API_SCOPES)]), 'name');
        $prompts = api_prompts();
        $this->assertGreaterThanOrEqual(5, count($prompts));
        foreach ($prompts as [$title, $scopes, $text]) {
            $this->assertSame([], array_diff($scopes, array_keys(API_SCOPES)), $title . ': neznámé oprávnění');
            $this->assertStringContainsString(absolute_url('/mcp'), $text, $title);
            preg_match_all('/\b(import_[a-z]+|upload_document|house_overview|list_[a-z]+|create_post)\b/', $text, $m);
            if ($title === 'Jednotky a obyvatelé z tabulky') {
                $m[1] = array_diff($m[1], ['import_maintenance']);   // společná pravidla zmiňují revize
            }
            $this->assertSame([], array_diff($m[1], $all), $title . ': nástroj, který MCP nemá');
            $tools = api_mcp_tools(['scope_list' => $scopes]);
            $this->assertSame([], array_diff(array_unique($m[1]), array_column($tools, 'name')), $title . ': nástroj mimo uvedená oprávnění');
        }
        $this->assertStringContainsString('obyvatelům nic neposílej', $prompts[0][2]);
        $this->assertStringContainsString('na nic se mě neptej', $prompts[0][2], 'asistent rozhoduje podle pravidel, neptá se');
        $this->assertStringContainsString('Dokumenty: všechny nahraj jen pro přihlášené', $prompts[0][2]);
        foreach (array_keys(CATEGORIES) as $cat) {
            $this->assertStringContainsString('(' . $cat . ')', $prompts[0][2], 'pravidla znají kategorii ' . $cat);
        }
        $this->assertStringContainsString('až ti ho výslovně schválím', $prompts[4][2], 'zveřejnění jen po schválení');
        foreach ($prompts as [$title, , $text]) {
            $rest = api_prompt_rest($text);
            $this->assertStringStartsWith('Máš přístup k REST API', $rest, $title . ': varianta bez MCP');
            $this->assertStringNotContainsString('/mcp', $rest, $title);
            $this->assertDoesNotMatchRegularExpression('/\b(import_[a-z]+|upload_document|house_overview|list_tickets|list_debtors|create_post)\b/', $rest, $title . ': v API variantě nesmí zůstat nástroj MCP');
            $this->assertStringContainsString('SVJ_API_KEY', $rest, 'klíč z proměnné prostředí, ne v konverzaci');
        }
        $this->assertStringContainsString('Zkopírovat zadání pro API', (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/ext/api/admin.php'));
        $this->assertStringContainsString('id="zadani"', (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/ext/api/admin.php'));
    }
}
