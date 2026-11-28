<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** API pro převod obsahu (app/ext/api_lib.php): jednotky, termíny, kontakty, aktuality, dokumenty, lidé – idempotentně podle ext_id. */
final class ApiImportTest extends TestCase
{
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        api_ready();
        $this->tag = bin2hex(random_bytes(3));
    }

    public function testUnitsUpsertByEntranceAndNumber(): void
    {
        [$n, $u, $e] = api_units_save([['entrance' => 'Z' . $this->tag, 'number' => '1', 'floor' => '2', 'share' => '1/10', 'area' => 54.3], ['number' => ''], ['number' => '9', 'area' => 'velká']]);
        $this->assertSame([1, 0], [$n, $u]);
        $this->assertSame([2, 3], array_column($e, 'row'));
        [$n, $u] = api_units_save([['entrance' => 'Z' . $this->tag, 'number' => '1', 'share' => '2/10']]);
        $this->assertSame([0, 1], [$n, $u], 'podruhé jen úprava');
        $row = one('SELECT * FROM units WHERE entrance = ? AND number = ?', ['Z' . $this->tag, '1']);
        $this->assertSame('2/10', $row['share']);
        $this->assertSame('2', $row['floor'], 'nezaslané pole zůstane');
        $this->assertSame((int) $row['id'], (int) api_unit_find('Z' . $this->tag . '/1')['id']);
        q('DELETE FROM units WHERE id = ?', [$row['id']]);
    }

    public function testEventsContactsAndPostsAreIdempotent(): void
    {
        $ev = [['ext_id' => 'e-' . $this->tag, 'title' => 'Shromáždění', 'date' => '2027-03-10', 'time' => '18:00', 'place' => 'Sklep']];
        $this->assertSame(1, api_events_save($ev)[0]);
        $ev[0]['time'] = '19:00';
        $this->assertSame([0, 1], array_slice(api_events_save($ev), 0, 2));
        $this->assertSame(1, (int) val('SELECT COUNT(*) FROM events WHERE title = ? AND event_time = ?', ['Shromáždění', '19:00']));
        $this->assertSame('Neplatný čas (time, HH:MM)', api_events_save([['title' => 'x', 'date' => '2027-01-01', 'time' => '25:00']])[2][0]['error']);

        $c = [['group' => 'havarie', 'role' => 'Výtahy', 'name' => 'Výtahy ' . $this->tag, 'phone' => '+420 777 123 456']];
        $this->assertSame(1, api_contacts_save($c)[0]);
        $this->assertSame(1, api_contacts_save($c)[1], 'bez ext_id podle skupiny a jména');
        $this->assertNotEmpty(api_contacts_save([['group' => 'kamaradi', 'name' => 'X']])[2]);

        $before = count($this->mails());
        $p = [['ext_id' => 'p-' . $this->tag, 'title' => 'Stará zpráva', 'body' => 'Text', 'published_at' => '2019-05-01']];
        $this->assertSame(1, api_posts_import($p)[0]);
        $this->assertSame(1, api_posts_import($p)[1]);
        $this->assertSame('2019-05-01', (string) val('SELECT published_at FROM posts WHERE id = (SELECT local_id FROM api_refs WHERE kind = ? AND ext_id = ?)', ['post', 'p-' . $this->tag]));
        $this->assertCount($before, $this->mails(), 'převod nikomu nic neposílá');
    }

    public function testDocumentUploadFromData(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $file = store_file_data('stanovy.pdf', $pdf);
        $this->assertIsArray($file);
        [$id, $created] = api_document_save(['ext_id' => 'd-' . $this->tag, 'title' => 'Stanovy', 'category' => 'zakladni', 'date' => '2015-06-01', 'visibility' => 'residents'], $file);
        $this->assertTrue($created);
        $d = one('SELECT * FROM documents WHERE id = ?', [$id]);
        $this->assertSame(['zakladni', 'residents', '2015-06-01'], [$d['category'], $d['visibility'], $d['doc_date']]);
        [$id2, $created2] = api_document_save(['ext_id' => 'd-' . $this->tag, 'title' => 'Stanovy (úplné znění)'], null);
        $this->assertSame([$id, false], [$id2, $created2], 'stejné ext_id = úprava bez nového souboru');
        $this->assertSame('Chybí soubor', api_document_save(['title' => 'x'], null));
        $this->assertStringContainsString('neexistuje', (string) api_document_save(['unit' => 'NENI/999'], $file));
        $this->assertIsString(store_file_data('virus.pdf', 'MZ not a pdf'), 'obsah musí odpovídat příponě');
        @unlink(UPLOAD_DIR . '/' . $file[0]);
        q('DELETE FROM documents WHERE id = ?', [$id]);
    }

    public function testPeopleWithoutInvitesAndTenantRule(): void
    {
        $unit = all_units()[0];
        $label = $unit['entrance'] . '/' . $unit['number'];
        $owner = 'vlastnik.' . $this->tag . '@example.org';
        $before = count($this->mails());
        [$n, $l, $e] = api_people_save([
            ['name' => 'Nájemník', 'email' => 'najemnik.' . $this->tag . '@example.org', 'kind' => 'tenant', 'units' => [$label]],
            ['name' => 'Vlastník', 'email' => $owner, 'phone' => '777123456', 'units' => [$label]],
            ['name' => 'Bez jednotky', 'email' => 'x.' . $this->tag . '@example.org', 'units' => ['NENI/1']],
            ['name' => 'Špatný telefon', 'email' => 'y.' . $this->tag . '@example.org', 'phone' => 'večer'],
        ]);
        $this->assertSame(2, $n, 'vlastník se zpracuje před nájemníkem');
        $this->assertCount(2, $e);
        $u = one('SELECT * FROM users WHERE email = ?', [$owner]);
        $this->assertSame('+420 777 123 456', $u['phone']);
        $this->assertSame(NO_PASSWORD, $u['password_hash'], 'účet bez hesla – pozvánku pošle výbor');
        $this->assertCount($before, $this->mails(), 'žádné pozvánky');
        [$n2, $l2] = api_people_save([['name' => 'Vlastník', 'email' => strtoupper($owner), 'units' => [$label]]]);
        $this->assertSame([0, 1], [$n2, $l2], 'existující e-mail jen doplní jednotky');
        q('DELETE FROM users WHERE email LIKE ?', ['%.' . $this->tag . '@example.org']);
    }

    public function testScopesToolsAndRoutes(): void
    {
        foreach (['units:write', 'documents:write', 'events:write', 'contacts:write', 'people:write'] as $s) {
            $this->assertArrayHasKey($s, API_SCOPES);
        }
        $tools = array_column(api_mcp_tools(['scope_list' => ['events:write', 'documents:write']]), 'name');
        $this->assertSame(['import_events', 'upload_document'], $tools);
        $o = api_openapi();
        $this->assertArrayHasKey('put', $o['paths']['/units']);
        $this->assertArrayHasKey('put', $o['paths']['/posts']);
        foreach (['/events', '/contacts', '/people', '/documents'] as $p) {
            $this->assertArrayHasKey($p, $o['paths']);
        }
        $r = (string) file_get_contents(dirname(__DIR__, 2) . '/app/ext/routes_api.php');
        foreach (["'PUT', '/api/v1/units'", "'PUT', '/api/v1/events'", "'PUT', '/api/v1/contacts'", "'POST', '/api/v1/people'", "'POST', '/api/v1/documents'", "'PUT', '/api/v1/posts'"] as $route) {
            $this->assertStringContainsString($route, $r);
        }
    }
}
