<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Inzeráty vlastníků (app/ext/listing_lib.php, app/ext/routes_listing.php): oprávnění, jeden inzerát na jednotku,
 * fotky bez EXIF, kdo inzerát vidí, zprávy zájemců, náhled při sdílení, prodloužení, skrytí výborem, nahlášení, PENB.
 */
final class ListingTest extends TestCase
{
    private array $units = [];
    private array $users = [];
    private int $now;
    private string $address = '';

    protected function setUp(): void
    {
        parent::setUp();
        listing_ready();
        $this->now = time();
        foreach (['penb_class', 'penb_valid_until', 'penb_reminded', 'listing_enabled', 'listing_locality', 'penb_doc_id', 'penb_file', 'penb_file_name', 'penb_file_size'] as $k) {
            q('DELETE FROM settings WHERE `key` = ?', [$k]);
        }
        $this->address = setting('address');
        q("UPDATE settings SET value = 'Zahradní 12, 602 00 Brno' WHERE `key` = 'address'");
        setting('__reset');
        $this->units['a'] = $this->unit('L1', '3', 54.3);
        $this->units['b'] = $this->unit('L2', '1', null);
        $this->users['owner'] = $this->user('owner', [$this->units['a']]);
        $this->users['tenant'] = $this->user('tenant', [$this->units['a']]);
        $this->users['other'] = $this->user('owner', [$this->units['b']]);
    }

    protected function tearDown(): void
    {
        foreach (all('SELECT id FROM listings') as $l) {
            listing_delete((int) $l['id']);
        }
        q('DELETE FROM listing_hits');
        q('DELETE FROM listing_reports');
        foreach ($this->users as $id) {
            q('DELETE FROM users WHERE id = ?', [$id]);
        }
        foreach ($this->units as $id) {
            q('DELETE FROM units WHERE id = ?', [$id]);
        }
        q("DELETE FROM settings WHERE `key` LIKE 'penb\\_%' ESCAPE '\\' OR `key` IN ('listing_enabled', 'listing_locality')");
        q("UPDATE settings SET value = ? WHERE `key` = 'address'", [$this->address]);
        setting('__reset');
        unset($GLOBALS['PAGE_META']);
    }

    private function unit(string $number, string $floor, ?float $area): int
    {
        q("INSERT INTO units (entrance, number, floor, area) VALUES ('A', ?, ?, ?)", [$number . bin2hex(random_bytes(2)), $floor, $area]);
        return (int) db()->lastInsertId();
    }
    private function user(string $kind, array $unitIds): int
    {
        q("INSERT INTO users (email, name, password_hash, role, kind) VALUES (?, ?, 'x', 'resident', ?)", ['lst-' . bin2hex(random_bytes(4)) . '@example.cz', 'Jana Prodejová', $kind]);
        $id = (int) db()->lastInsertId();
        foreach ($unitIds as $u) {
            q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$id, $u]);
        }
        return $id;
    }
    private function u(string $who): array
    {
        return one('SELECT * FROM users WHERE id = ?', [$this->users[$who]]);
    }
    private function unitRow(string $k): array
    {
        return one('SELECT * FROM units WHERE id = ?', [$this->units[$k]]);
    }
    private function post(array $over = []): array
    {
        return $over + ['type' => 'prodej', 'layout' => '2+kk', 'price' => '4 250 000', 'fees' => '', 'area' => '54,3', 'floor' => '3',
            'description' => 'Světlý byt po rekonstrukci, sklep, výtah. Volný ihned.', 'contact_name' => 'Jana P.', 'energy' => 'C'];
    }
    /** Koncept inzerátu s fotkami (bez zveřejnění). */
    private function draft(array $over = [], int $photos = 3, string $unit = 'a'): int
    {
        [$d, $errors] = listing_input($this->post($over), $this->unitRow($unit));
        $this->assertSame([], $errors);
        $id = listing_save($this->u($unit === 'a' ? 'owner' : 'other'), $this->units[$unit], $d);
        for ($i = 0; $i < $photos; $i++) {
            $this->assertNull(listing_photo_add($id, $this->jpeg()));
        }
        return $id;
    }
    private function published(array $over = []): array
    {
        $id = $this->draft($over);
        $this->assertNull(listing_publish($id, $this->now));
        return listing_get($id);
    }
    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body']), 'reply_to' => $m['reply_to']], $this->mails());
    }
    /** Přeposlané zprávy zájemců (bez upozornění výboru na zveřejnění). */
    private function relayed(): array
    {
        return array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], 'Zájemce o Váš inzerát')));
    }
    /** JPEG 800×600 s blokem EXIF (GPS poloha, model telefonu, natočení). */
    private function jpeg(int $orientation = 1, int $w = 800, int $h = 600): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, (int) ($w / 2), $h, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        // TIFF (little endian): IFD0 se dvěma položkami – Orientation a Model, za nimi „GPS“ text.
        $model = "TajnyTelefon\0";
        $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 2)
            . pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
            . pack('vvVV', 0x0110, 2, strlen($model), 38) . pack('V', 0) . $model . 'GPSLatitude 49.1951N 16.6068E';
        $app1 = "Exif\0\0" . $tiff;
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
        $f = tempnam(sys_get_temp_dir(), 'lst');
        file_put_contents($f, $jpeg);
        return $f;
    }

    // ---------- Oprávnění ----------

    public function testOnlyOwnerOfTheUnitMayCreate(): void
    {
        $this->assertTrue(listing_owns_unit($this->u('owner'), $this->units['a']));
        $this->assertFalse(listing_owns_unit($this->u('tenant'), $this->units['a']), 'nájemník ne');
        $this->assertFalse(listing_owns_unit($this->u('other'), $this->units['a']), 'vlastník jiné jednotky ne');
        $this->assertFalse(listing_owns_unit(null, $this->units['a']));
        $admin = one("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $this->assertFalse(listing_owns_unit($admin, $this->units['a']), 'správce bez jednotky ne');
        q('UPDATE users SET active = 0 WHERE id = ?', [$this->users['owner']]);
        $this->assertFalse(listing_owns_unit($this->u('owner'), $this->units['a']), 'vypnutý účet ne');
        q('UPDATE users SET active = 1 WHERE id = ?', [$this->users['owner']]);

        $l = listing_get($this->draft());
        $this->assertTrue(listing_can_manage($l, $this->u('owner')));
        $this->assertFalse(listing_can_manage($l, $this->u('tenant')));
        $this->assertFalse(listing_can_manage($l, $this->u('other')));
        // Spoluvlastník téže jednotky (manžel/ka s účtem vlastníka) inzerát spravovat smí.
        $this->users['co'] = $this->user('owner', [$this->units['a']]);
        $this->assertTrue(listing_can_manage($l, $this->u('co')));
    }

    public function testRoutesCheckOwnershipAndCsrfIsGlobal(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/ext/routes_listing.php');
        $this->assertStringContainsString("!listing_owns_unit(\$u, \$unitId)", $src);
        $this->assertStringContainsString("!listing_can_manage(\$l, \$u)", $src);
        $this->assertSame(substr_count($src, "\$route('POST', '/admin/"), substr_count($src, "require_admin();") - 2, 'každá adresa výboru ověřuje správce');
        // Všechny formuláře v šablonách mají token proti CSRF (index.php ho u POST ověřuje vždy).
        foreach (glob(dirname(__DIR__, 2) . '/app/views/ext/listing/*.php') as $f) {
            $s = (string) file_get_contents($f);
            $this->assertSame(substr_count($s, '<form method="post"'), substr_count($s, 'csrf_field()'), basename($f));
        }
    }

    public function testOneActiveListingPerUnit(): void
    {
        $this->assertNull(listing_active_for_unit($this->units['a']));
        $id = $this->draft();
        $this->assertSame($id, (int) listing_active_for_unit($this->units['a'])['id']);
        $this->assertNull(listing_active_for_unit($this->units['a'], $id), 'vlastní inzerát se při úpravě nepočítá');
        $this->assertNull(listing_active_for_unit($this->units['b']));
        listing_publish($id, $this->now);
        $this->assertNotNull(listing_active_for_unit($this->units['a']), 'zveřejněný je aktivní');
        listing_mark_sold($id);
        $this->assertNull(listing_active_for_unit($this->units['a']), 'po prodeji může vzniknout nový');
        // Vypršelý nejde obnovit, když jednotka mezitím má jiný inzerát.
        $id2 = $this->draft();
        q("UPDATE listings SET status = 'expired' WHERE id = ?", [$id]);
        $this->assertFalse(listing_extend($id));
        $this->assertSame('Jednotka už má jiný inzerát.', listing_publish($id));
        listing_delete($id2);
        $this->assertTrue(listing_extend($id));
    }

    public function testInputValidationAndPrefill(): void
    {
        [$d, $errors] = listing_input([], $this->unitRow('a'));
        $this->assertGreaterThanOrEqual(5, count($errors));
        [$d, $errors] = listing_input($this->post(['type' => 'pronajem', 'price' => '18 500 Kč', 'fees' => '3 500', 'area' => '']), $this->unitRow('a'));
        $this->assertSame([], $errors);
        $this->assertSame([18500, 3500, null], [$d['price'], $d['fees'], $d['area']]);
        [$d] = listing_input($this->post(['fees' => '3 500']), $this->unitRow('a'));
        $this->assertSame(0, $d['fees'], 'poplatky jen u pronájmu');
        [, $errors] = listing_input($this->post(['area' => 'velký', 'price' => '0', 'layout' => '7+7']), $this->unitRow('a'));
        $this->assertCount(3, $errors);
        $def = listing_defaults($this->unitRow('a'), $this->u('owner'));
        $this->assertSame(['3', 54.3, 'Jana Prodejová'], [$def['floor'], (float) $def['area'], $def['contact_name']]);
    }

    public function testEnergyClassFromBuildingOrOwner(): void
    {
        [, $errors] = listing_input($this->post(['energy' => '']), $this->unitRow('a'));
        $this->assertContains('Vyberte energetickou třídu bytu, nebo „Průkaz nemám“.', $errors);
        [$d] = listing_input($this->post(['energy' => 'nema']), $this->unitRow('a'));
        $this->assertSame(['', 'nema'], [$d['energy_class'], $d['energy_source']]);
        [$d] = listing_input($this->post(['energy' => 'E']), $this->unitRow('a'));
        $this->assertSame(['E', 'vlastni'], [$d['energy_class'], $d['energy_source']]);
        // Platný průkaz budovy má přednost a vlastník ho nepřepíše.
        $this->assertNull(listing_penb_save('C', date('Y-m-d', strtotime('+2 years'))));
        [$d, $errors] = listing_input($this->post(['energy' => 'A']), $this->unitRow('a'));
        $this->assertSame([], $errors);
        $this->assertSame(['C', 'budova'], [$d['energy_class'], $d['energy_source']]);
        // Prošlý průkaz už neplatí.
        $this->assertNull(listing_penb_save('C', date('Y-m-d', strtotime('-1 day'))));
        $this->assertFalse(listing_penb()['valid']);
        [$d] = listing_input($this->post(['energy' => 'D']), $this->unitRow('a'));
        $this->assertSame('vlastni', $d['energy_source']);
    }

    // ---------- PENB v nastavení ----------

    public function testPenbSettingsValidation(): void
    {
        $this->assertSame(['class' => '', 'until' => '', 'valid' => false], listing_penb());
        $this->assertNotNull(listing_penb_save('H', '2030-01-01'));
        $this->assertNotNull(listing_penb_save('B', ''), 'třída bez data platnosti');
        $this->assertNotNull(listing_penb_save('B', '2030-02-31'));
        $this->assertNull(listing_penb_save('B', '2030-01-01'));
        $this->assertSame(['class' => 'B', 'until' => '2030-01-01', 'valid' => true], listing_penb('2029-12-31'));
        $this->assertFalse(listing_penb('2030-01-02')['valid']);
        $this->assertNull(listing_penb_save('', '2030-01-01'));
        $this->assertSame('', setting('penb_valid_until'), '„nezadáno“ smaže i datum');
    }

    public function testSettingsSectionIsRenderedInHouseSettings(): void
    {
        $this->assertContains(dirname(__DIR__, 2) . '/app/ext/../views/ext/listing/_settings.php', $GLOBALS['EXT_SETTINGS']);
        $this->assertStringContainsString("\$GLOBALS['EXT_SETTINGS']", (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/admin/settings.php'));
        listing_penb_save('D', '2031-05-01');
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/listing/_settings.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('<option value="D" selected>D</option>', $html);
        $this->assertStringContainsString('value="2031-05-01"', $html);
        $this->assertStringContainsString('Inzeráty jsou zapnuté', $html, 'inzeráty jsou ve výchozím stavu povolené');
        $this->assertStringNotContainsString('name="listing_enabled"', $html, 'přepínač je jen v části Menu');
        $this->assertStringContainsString('href="#menu"', $html);
        $this->assertStringContainsString('name="penb_pdf"', $html);
        $this->assertStringContainsString('placeholder="Zahradní, Brno"', $html);
    }

    /** Chyba z 11/2026: inzeráty nešly vypnout v Nastavení → Menu, přepínač byl schovaný u PENB. */
    public function testListingsCanBeSwitchedOffInSettingsMenu(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertSame('Inzeráty', $GLOBALS['EXT_MENU']['inzeraty'][0]);
        $this->assertStringContainsString("\$GLOBALS['EXT_MENU']", (string) file_get_contents($root . '/app/views/admin/settings.php'));
        $this->assertStringContainsString('ext_menu_save(', (string) file_get_contents($root . '/app/routes_admin.php'));
        $this->assertTrue(($GLOBALS['EXT_MENU']['inzeraty'][1])(), 'výchozí stav: zapnuto');
        ext_menu_save([]);   // odškrtnuto
        $this->assertFalse(listing_enabled());
        $this->assertFalse(($GLOBALS['EXT_ADMIN_NAV']['/admin/inzeraty'][2])(), 'zmizí i z menu administrace');
        $this->assertFalse(($GLOBALS['EXT_RESIDENT_NAV']['/inzeraty'][1])(), 'zmizí z menu obyvatel');
        ext_menu_save(['inzeraty']);
        $this->assertTrue(listing_enabled());
        $this->assertTrue(($GLOBALS['EXT_ADMIN_NAV']['/admin/inzeraty'][2])());
    }

    public function testPenbPdfAsDocumentOrAdminOnly(): void
    {
        $file = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents(UPLOAD_DIR . '/' . $file, "%PDF-1.4\n%%EOF\n");
        listing_penb_file([$file, 'penb.pdf', 15], true);
        $doc = one('SELECT * FROM documents WHERE id = ?', [(int) setting('penb_doc_id')]);
        $this->assertSame(['residents', $file, 'penb.pdf'], [$doc['visibility'], $doc['filename'], $doc['original_name']], 'nabídnuto vlastníkům v Dokumentech');
        // Odškrtnutím zmizí z Dokumentů, soubor zůstane jen pro správce.
        listing_penb_file(null, false);
        $this->assertNull(one('SELECT id FROM documents WHERE id = ?', [(int) $doc['id']]));
        $this->assertSame($file, setting('penb_file'));
        $this->assertFileExists(UPLOAD_DIR . '/' . $file);
        listing_penb_file(null, true);
        $this->assertSame('', setting('penb_file'));
        $this->assertNotSame('', setting('penb_doc_id'));
        listing_penb_file(null, true, true);
        $this->assertFileDoesNotExist(UPLOAD_DIR . '/' . $file);
        $this->assertSame(['', ''], [setting('penb_doc_id'), setting('penb_file')]);
    }

    public function testPenbReminderOnceBeforeExpiry(): void
    {
        $until = date('Y-m-d', $this->now + 120 * 86400);
        listing_penb_save('C', $until);
        listing_daily($this->now);
        $this->assertSame([], array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], 'Průkaz')), '120 dní předem ještě ne');
        $later = $this->now + 31 * 86400;   // 89 dní před koncem
        listing_daily($later);
        $mails = array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], 'Průkaz energetické náročnosti budovy brzy končí')));
        $admins = (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");
        $this->assertCount($admins, $mails, 'každý správce jednou');
        $this->assertStringContainsString('třída C', $mails[0]['body']);
        listing_daily($later + 86400);
        listing_daily($later + 40 * 86400);
        $this->assertCount($admins, array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], 'Průkaz')), 'jen jednou');
        // Nový průkaz s jiným datem = nová připomínka (až zase 90 dní předem).
        listing_penb_save('B', date('Y-m-d', $later + 60 * 86400));
        listing_daily($later + 2 * 86400);
        $this->assertCount(2 * $admins, array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], 'Průkaz')));
    }

    // ---------- Fotky ----------

    public function testPhotoIsReencodedWithoutExifAndResized(): void
    {
        $src = $this->jpeg(1, 3000, 2000);
        $this->assertStringContainsString('Exif', (string) file_get_contents($src));
        $this->assertStringContainsString('GPSLatitude', (string) file_get_contents($src));
        $this->assertSame(1, listing_jpeg_orientation((string) file_get_contents($src)));
        $r = listing_photo_process($src);
        $this->assertIsArray($r);
        foreach (['large', 'thumb'] as $k) {
            $this->assertStringStartsWith("\xFF\xD8", $r[$k]);
            $this->assertStringNotContainsString('Exif', $r[$k], $k);
            $this->assertStringNotContainsString('GPS', $r[$k], $k);
            $this->assertStringNotContainsString('TajnyTelefon', $r[$k], $k);
            $this->assertStringNotContainsString("\xFF\xE1", substr($r[$k], 0, 64), "$k: bez bloku APP1");
        }
        $this->assertSame([1600, 1067], [$r['width'], $r['height']]);
        $t = getimagesizefromstring($r['thumb']);
        $this->assertSame(480, max($t[0], $t[1]));
        if (function_exists('exif_read_data')) {
            $tmp = tempnam(sys_get_temp_dir(), 'lst');
            file_put_contents($tmp, $r['large']);
            $this->assertFalse(@exif_read_data($tmp, 'GPS'));
            unlink($tmp);
        }
    }

    public function testPhotoOrientationIsApplied(): void
    {
        $src = $this->jpeg(6, 800, 600);   // telefon držený na výšku: uloženo naležato s příznakem otočení
        $this->assertSame(6, listing_jpeg_orientation((string) file_get_contents($src)));
        $r = listing_photo_process($src);
        $this->assertSame([600, 800], [$r['width'], $r['height']], 'fotka je otočená na výšku');
    }

    public function testPhotoTypeAndSizeChecks(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'lst');
        file_put_contents($f, "<?php echo 'x';");
        $this->assertSame('Fotka musí být JPG, PNG nebo WebP.', listing_photo_process($f));
        file_put_contents($f, "%PDF-1.4\n%%EOF");
        $this->assertSame('Fotka musí být JPG, PNG nebo WebP.', listing_photo_process($f));
        $this->assertSame('Fotka je příliš malá (aspoň 200 × 200 bodů).', listing_photo_process($this->jpeg(1, 150, 100)));
        $png = imagecreatetruecolor(400, 300);
        imagepng($png, $f);
        $this->assertIsArray(listing_photo_process($f), 'PNG projde a uloží se jako JPEG');
        imagewebp($png, $f);
        $this->assertIsArray(listing_photo_process($f), 'WebP projde');
        file_put_contents($f, '');
        $this->assertSame('Fotku se nepodařilo nahrát.', listing_photo_process($f));
        unlink($f);
    }

    public function testPhotoLimitAndFileNamesAreSafe(): void
    {
        $id = $this->draft([], LISTING_PHOTOS_MAX);
        $this->assertSame('K inzerátu lze nahrát nejvýše 10 fotek.', listing_photo_add($id, $this->jpeg()));
        $photos = listing_photos($id);
        $this->assertCount(10, $photos);
        foreach ($photos as $p) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $p['filename']);
            $this->assertNotNull(listing_photo_file($p, false));
            $this->assertNotNull(listing_photo_file($p, true));
        }
        // Podvržený název v databázi se neotevře.
        $this->assertNull(listing_photo_file(['filename' => '../../config.php', 'thumb' => '../x.jpg'], false));
        $this->assertNull(listing_photo_file(['filename' => '', 'thumb' => '/etc/passwd'], true));
        listing_photo_cover($id, (int) $photos[4]['id']);
        $this->assertSame((int) $photos[4]['id'], (int) listing_photos($id)[0]['id'], 'vybraná úvodní fotka je první');
        $paths = array_map(fn($p) => listing_photo_file($p, false), $photos);
        listing_delete($id);
        foreach ($paths as $p) {
            $this->assertFileDoesNotExist($p, 'smazáním inzerátu zmizí i fotky');
        }
    }

    // ---------- Kdo inzerát vidí ----------

    public function testPublishNeedsPhotosAndNotifiesAdmins(): void
    {
        $id = $this->draft([], 2);
        $this->assertSame('Ke zveřejnění jsou potřeba aspoň 3 fotky.', listing_publish($id));
        $this->assertSame('draft', listing_get($id)['status']);
        listing_photo_add($id, $this->jpeg());
        $this->assertNull(listing_publish($id, $this->now));
        $l = listing_get($id);
        $this->assertSame(['published', date('Y-m-d', $this->now + 60 * 86400)], [$l['status'], $l['expires_on']]);
        $mails = array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], 'Nový inzerát vlastníka')));
        $this->assertCount((int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1"), $mails);
        $this->assertStringContainsString('Prodej bytu 2+kk', $mails[0]['body']);
        $this->assertStringContainsString('/admin/inzeraty', $mails[0]['body']);
        $this->assertSame('Tento inzerát už zveřejněný je.', listing_publish($id));
    }

    public function testVisibilityRules(): void
    {
        $owner = $this->u('owner');
        $neighbour = $this->u('other');
        $admin = one("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $id = $this->draft();
        $l = listing_get($id);
        $this->assertSame('none', listing_visibility($l, $this->now), 'koncept');
        $this->assertFalse(listing_can_view($l, null));
        $this->assertFalse(listing_can_view($l, $neighbour));
        $this->assertTrue(listing_can_view($l, $owner), 'vlastník vidí náhled');
        $this->assertTrue(listing_can_view($l, $admin), 'výbor vidí náhled');

        listing_publish($id, $this->now);
        $l = listing_get($id);
        $this->assertSame('public', listing_visibility($l, $this->now));
        $this->assertTrue(listing_can_view($l, null));
        $this->assertSame('public', listing_visibility($l, $this->now + 59 * 86400));
        $this->assertSame('none', listing_visibility($l, $this->now + 61 * 86400), 'po 60 dnech vypršel (i bez denní úlohy)');

        // Skrytý výborem
        listing_set_hidden($id, true, 'Nevhodné fotky');
        $l = listing_get($id);
        $this->assertSame('none', listing_visibility($l, $this->now));
        $this->assertFalse(listing_can_view($l, $neighbour));
        $this->assertTrue(listing_can_view($l, $owner));
        listing_set_hidden($id, false);

        // Vypnuté inzeráty v nastavení domu
        listing_setting_save('listing_enabled', '0');
        $this->assertSame('none', listing_visibility(listing_get($id), $this->now));
        listing_setting_save('listing_enabled', '1');

        // Prodaný: se štítkem 14 dní, potom zmizí
        listing_mark_sold($id);
        $l = listing_get($id);
        $sold = strtotime($l['sold_at']);
        $this->assertSame('public', listing_visibility($l, $sold + 13 * 86400));
        $this->assertSame('none', listing_visibility($l, $sold + 15 * 86400));
        $this->assertFalse(listing_can_view($l, null, $sold + 15 * 86400));
    }

    public function testNeighboursFirst(): void
    {
        $id = $this->draft(['neighbours_first' => '1']);
        listing_publish($id, $this->now);
        $l = listing_get($id);
        $this->assertSame('residents', listing_visibility($l, $this->now));
        $this->assertSame('residents', listing_visibility($l, $this->now + 2 * 86400));
        $this->assertFalse(listing_can_view($l, null, $this->now), 'nepřihlášený zatím ne');
        $this->assertTrue(listing_can_view($l, $this->u('other'), $this->now), 'soused ano');
        $this->assertSame('public', listing_visibility($l, $this->now + 3 * 86400 + 1), 'po 3 dnech sám veřejný');
    }

    public function testLocalityNeverContainsUnitNumberOrEntrance(): void
    {
        $this->assertSame('Zahradní, Brno', listing_locality());
        $this->assertSame('Tábor, Brno', listing_locality_from('Tábor 48 a–d, Brno'));
        $this->assertSame('Náměstí Míru, Praha', listing_locality_from('Náměstí Míru 1234/56, 120 00 Praha 2'));
        $this->assertSame('Lipová', listing_locality_from('Lipová 7'));
        $this->assertSame('', listing_locality_from(''));
        listing_setting_save('listing_locality', 'Brno-Žabovřesky');
        $this->assertSame('Brno-Žabovřesky', listing_locality());
        listing_setting_save('listing_locality', '');

        $l = $this->published();
        $unit = $this->unitRow('a');
        $html = $this->renderPage($l);
        $share = listing_share_text($l);
        $meta = implode(' ', listing_meta($l, listing_photos((int) $l['id'])));
        foreach ([$html, $share, $meta, listing_path($l)] as $text) {
            $this->assertStringNotContainsString(unit_name($unit), $text);
            $this->assertStringNotContainsString($unit['number'], $text);
            $this->assertStringNotContainsString('602 00', $text, 'bez PSČ');
            $this->assertStringNotContainsString($this->u('owner')['email'], $text, 'e-mail vlastníka nikde');
        }
        $this->assertStringContainsString('Zahradní, Brno', $html);
        $this->assertStringStartsWith('Prodej bytu 2+kk, Zahradní, Brno', $share, 'lokalita bez čísla domu');
        $this->assertStringContainsString('https://mapy.cz/zakladni?q=Zahradn%C3%AD%2C%20Brno', $html);
    }

    private function renderPage(array $l, ?array $user = null, array $errors = [], array $in = []): string
    {
        $_SESSION = $user ? ['uid' => (int) $user['id'], 'pv' => pw_fingerprint($user['password_hash'])] : [];
        user(true);
        $photos = listing_photos((int) $l['id']);
        $vis = listing_visibility($l);
        $in += ['name' => '', 'contact' => '', 'message' => ''];
        $canManage = listing_can_manage($l, $user);
        $token = listing_form_token();
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/listing/page.php';
        return (string) ob_get_clean();
    }

    public function testPublicPageContentAndShareButtons(): void
    {
        $l = $this->published(['description' => 'Krásný byt <script>alert(1)</script> u parku, sklep a výtah.']);
        $html = $this->renderPage($l);
        $this->assertStringContainsString('<h1>Prodej bytu 2+kk</h1>', $html);
        $this->assertStringContainsString("4\u{a0}250\u{a0}000\u{a0}Kč", $html);
        $this->assertStringContainsString('class="penb penb-c">C</span>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode(absolute_url(listing_path($l))), $html);
        $this->assertStringContainsString('https://wa.me/?text=', $html);
        $this->assertStringContainsString('Text pro Bazoš a skupiny', $html);
        $this->assertStringContainsString('name="website"', $html, 'skryté pole proti robotům');
        $this->assertStringContainsString('/inzerat/' . $l['id'] . '/nahlasit', $html);
        $this->assertStringContainsString('Inzerát vlastníka jednotky ve společenství', $html);
        $this->assertStringContainsString('web domu provozuje', $html);
        $this->assertStringContainsString('/inzerat/foto/', $html);
        $this->assertStringNotContainsString('/inzeraty/' . $l['id'] . '/upravit', $html, 'cizí návštěvník nemá odkaz na úpravu');
        $this->assertStringContainsString('/inzeraty/' . $l['id'] . '/upravit', $this->renderPage($l, $this->u('owner')));

        $share = listing_share_text($l);
        $this->assertStringStartsWith("Prodej bytu 2+kk, Zahradní, Brno\n54,3 m², 3. patro, energetická třída C\nCena: 4 250 000 Kč", $share);
        $this->assertStringEndsWith('Fotky a kontakt: ' . absolute_url(listing_path($l)), $share);
        q("UPDATE listings SET share_text = 'Krátký text pro skupinu.' WHERE id = ?", [$l['id']]);
        $this->assertStringContainsString("\nKrátký text pro skupinu.\n", listing_share_text(listing_get((int) $l['id'])));

        // Prodaný: štítek a žádný formulář
        listing_mark_sold((int) $l['id']);
        $html = $this->renderPage(listing_get((int) $l['id']));
        $this->assertStringContainsString('<span>Prodáno</span>', $html);
        $this->assertStringContainsString('Byt je už prodaný.', $html);
        $this->assertStringNotContainsString('name="message"', $html);
    }

    public function testOpenGraphTagsPresentAndEscaped(): void
    {
        $l = $this->published(['type' => 'pronajem', 'price' => '18500', 'layout' => '3+1', 'description' => 'Byt "U lip" & <b>zahrada</b>, klidná ulice, sklep.']);
        $photos = listing_photos((int) $l['id']);
        $meta = listing_meta($l, $photos);
        $this->assertSame('Pronájem bytu 3+1, 18 500 Kč / měsíc', $meta['og:title']);
        $this->assertSame(absolute_url(listing_path($l)), $meta['og:url']);
        $this->assertSame(absolute_url('/inzerat/foto/' . $photos[0]['id'] . '.jpg'), $meta['og:image']);
        $this->assertMatchesRegularExpression('~^https?://~', $meta['og:image'], 'absolutní adresa obrázku');
        $this->assertSame('summary_large_image', $meta['twitter:card']);
        $this->assertSame('800', $meta['og:image:width']);
        $html = page_meta_html($meta);
        $this->assertStringContainsString('<meta property="og:title" content="Pronájem bytu 3+1, 18 500 Kč / měsíc">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="' . e($meta['og:image']) . '">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="' . e($meta['og:url']) . '">', $html);
        $this->assertStringContainsString('Byt &quot;U lip&quot; &amp; &lt;b&gt;zahrada&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('name="description"', $html, 'popis stránky vypisuje rozvržení zvlášť');
        // Neplatný klíč se nevypíše vůbec.
        $this->assertSame('', page_meta_html(['og:title" onload="x' => 'a', 'og:x' => '']));

        // Rozvržení stránky značky použije.
        $GLOBALS['PAGE_META'] = $meta;
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/layout.php');
        $this->assertStringContainsString("page_meta_html((array) (\$GLOBALS['PAGE_META'] ?? []))", $layout);
        $this->assertStringContainsString("\$GLOBALS['PAGE_META']['description'] ??", $layout);
    }

    public function testCanonicalPathAndSlug(): void
    {
        $l = $this->published();
        $this->assertSame('/inzerat/' . $l['id'] . '-prodej-bytu-2-kk-brno', listing_path($l));
        q("UPDATE listings SET layout = 'jine', type = 'pronajem' WHERE id = ?", [$l['id']]);
        $this->assertSame('/inzerat/' . $l['id'] . '-pronajem-bytu-brno', listing_path(listing_get((int) $l['id'])));
        $this->assertSame('Pronájem bytu', listing_title(listing_get((int) $l['id'])));
    }

    // ---------- Zprávy zájemců ----------

    public function testContactFormRelaysToOwnerWithoutExposingAddress(): void
    {
        $l = $this->published();
        $owner = $this->u('owner');
        $t = listing_form_token($this->now - 30);
        $r = listing_contact($l, ['name' => 'Petr Zájemce', 'contact' => 'petr@example.org', 'message' => 'Dobrý den, mohu na prohlídku v sobotu?', 't' => $t], '10.0.0.1', false, $this->now);
        $this->assertTrue($r['ok']);
        $this->assertGreaterThan(0, $r['inquiry'], 'zpráva je uložená jako zájemce');
        $mails = array_values(array_filter($this->decodedMails(), fn($m) => !str_contains($m['subject'], 'Nový inzerát')));
        $this->assertCount(1, $mails);
        $this->assertSame($owner['email'], $mails[0]['to']);
        $this->assertSame('petr@example.org', $mails[0]['reply_to'], 'odpověď jde rovnou zájemci');
        $this->assertStringContainsString('Zájemce o Váš inzerát: Prodej bytu 2+kk', $mails[0]['subject']);
        $this->assertStringContainsString('mohu na prohlídku v sobotu', $mails[0]['body']);
        $this->assertStringContainsString('Váš e-mail jsme zájemci neprozradili', $mails[0]['body']);
        foreach ($mails as $m) {
            $this->assertNotSame('petr@example.org', $m['to'], 'zájemce nedostane nic (ani adresu vlastníka)');
        }
        // Jen telefon: bez Reply-To na zájemce.
        listing_contact($l, ['name' => 'Eva', 'contact' => '+420 777 123 456', 'message' => 'Zavolejte prosím.', 't' => $t], '10.0.0.2', false, $this->now);
        $last = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $owner['email']))[1];
        $this->assertNotSame('+420 777 123 456', $last['reply_to']);
        $this->assertStringContainsString('Telefon: +420 777 123 456', $last['body']);
    }

    public function testContactFormAntiSpam(): void
    {
        $l = $this->published();
        $ok = ['name' => 'Petr', 'contact' => 'petr@example.org', 'message' => 'Mám zájem o byt.'];
        // Skryté pole vyplněné robotem: tváří se jako úspěch, nic se neodešle.
        $r = listing_contact($l, $ok + ['website' => 'http://spam.example', 't' => listing_form_token($this->now - 30)], '10.0.0.9', false, $this->now);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $this->relayed());
        // Odesláno hned po načtení / bez značky / podvržená značka / stará značka
        foreach ([listing_form_token($this->now - 1), '', ($this->now - 30) . '.0000000000aaaaaaaaaa', listing_form_token($this->now - 2 * 86400)] as $t) {
            $r = listing_contact($l, $ok + ['t' => $t], '10.0.0.9', false, $this->now);
            $this->assertArrayHasKey('errors', $r, $t);
        }
        $this->assertSame([], $this->relayed());
        // Neúplná zpráva
        $r = listing_contact($l, ['name' => '', 'contact' => 'x', 'message' => '', 't' => listing_form_token($this->now - 30)], '10.0.0.9', false, $this->now);
        $this->assertCount(3, $r['errors']);
        // Omezení počtu zpráv z jedné adresy za hodinu
        for ($i = 0; $i < LISTING_CONTACT_PER_HOUR; $i++) {
            $this->assertTrue(listing_contact($l, $ok + ['t' => listing_form_token($this->now - 30)], '10.0.0.7', false, $this->now)['ok'] ?? false);
        }
        $r = listing_contact($l, $ok + ['t' => listing_form_token($this->now - 30)], '10.0.0.7', false, $this->now);
        $this->assertStringContainsString('příliš mnoho zpráv', $r['errors'][0]);
        $this->assertTrue(listing_contact($l, $ok + ['t' => listing_form_token($this->now - 30)], '10.0.0.8', false, $this->now)['ok'] ?? false, 'jiná adresa může');
        $this->assertTrue(listing_contact($l, $ok + ['t' => listing_form_token($this->now + 3600)], '10.0.0.7', false, $this->now + 3700)['ok'] ?? false, 'po hodině znovu');
        $this->assertCount(LISTING_CONTACT_PER_HOUR + 2, $this->relayed());
    }

    public function testContactRefusedForInactiveOrNeighboursOnlyListing(): void
    {
        $ok = ['name' => 'Petr', 'contact' => 'petr@example.org', 'message' => 'Mám zájem o byt.', 't' => listing_form_token($this->now - 30)];
        $draft = listing_get($this->draft());
        $this->assertArrayHasKey('errors', listing_contact($draft, $ok, '10.1.0.1', false, $this->now));
        $id = $this->draft(['neighbours_first' => '1'], 3, 'b');
        listing_publish($id, $this->now);
        $l = listing_get($id);
        $this->assertArrayHasKey('errors', listing_contact($l, $ok, '10.1.0.1', false, $this->now), 'nepřihlášený u „Nejdřív sousedé“');
        $this->assertTrue(listing_contact($l, $ok, '10.1.0.1', true, $this->now)['ok'] ?? false, 'přihlášený soused');
    }

    // ---------- Životní cyklus ----------

    public function testExpiryReminderAndOneClickExtension(): void
    {
        $l = $this->published();
        $owner = $this->u('owner');
        listing_daily($this->now + 50 * 86400);
        $this->assertSame([], array_filter($this->decodedMails(), fn($m) => $m['to'] === $owner['email']), '10 dní před koncem ještě ne');
        listing_daily($this->now + 54 * 86400);
        $mine = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $owner['email']));
        $this->assertCount(1, $mine);
        $this->assertStringContainsString('Váš inzerát brzy skončí', $mine[0]['subject']);
        $link = listing_extend_url(listing_get((int) $l['id']));
        $this->assertStringContainsString($link, $mine[0]['body']);
        listing_daily($this->now + 55 * 86400);
        $this->assertCount(1, array_filter($this->decodedMails(), fn($m) => $m['to'] === $owner['email']), 'připomínka jen jednou');

        // Odkaz z e-mailu: podepsaný, platí jen pro aktuální konec zveřejnění.
        $token = substr($link, strrpos($link, '/') + 1);
        $this->assertTrue(hash_equals(listing_extend_token(listing_get((int) $l['id'])), $token));
        $this->assertTrue(listing_extend((int) $l['id'], $this->now + 55 * 86400));
        $after = listing_get((int) $l['id']);
        $this->assertSame(date('Y-m-d', $this->now + 115 * 86400), $after['expires_on']);
        $this->assertSame('', $after['reminded_on'], 'po prodloužení zase dostane připomínku');
        $this->assertFalse(hash_equals(listing_extend_token($after), $token), 'starý odkaz už neplatí');

        // Konec zveřejnění: stav vypršel, e-mail s odkazem na obnovení; obnovit jde jedním kliknutím.
        listing_daily($this->now + 116 * 86400);
        $this->assertSame('expired', listing_get((int) $l['id'])['status']);
        $this->assertNotEmpty(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], 'Váš inzerát skončil')));
        $this->assertTrue(listing_extend((int) $l['id']));
        $this->assertSame('published', listing_get((int) $l['id'])['status']);
    }

    public function testCommitteeHideNotifiesOwner(): void
    {
        $l = $this->published();
        $owner = $this->u('owner');
        listing_set_hidden((int) $l['id'], true, 'Inzerát nabízí jiný byt než v domě.');
        $this->assertSame(['1', 'Inzerát nabízí jiný byt než v domě.'], [(string) listing_get((int) $l['id'])['hidden'], listing_get((int) $l['id'])['hidden_reason']]);
        $mail = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $owner['email']))[0];
        $this->assertStringContainsString('Výbor skryl Váš inzerát', $mail['subject']);
        $this->assertStringContainsString('Důvod: Inzerát nabízí jiný byt než v domě.', $mail['body']);
        listing_set_hidden((int) $l['id'], false);
        $this->assertSame('0', (string) listing_get((int) $l['id'])['hidden']);
        $this->assertCount(2, array_filter($this->decodedMails(), fn($m) => $m['to'] === $owner['email']), 'i o znovuzobrazení');
        $this->assertSame('public', listing_visibility(listing_get((int) $l['id'])));
    }

    public function testReportGoesToCommitteeAndIsRateLimited(): void
    {
        $l = $this->published();
        $this->assertSame('Napište prosím, co je na inzerátu špatně.', listing_report($l, ' ', '10.2.0.1', $this->now));
        $this->assertNull(listing_report($l, 'Podvodný inzerát, byt neexistuje.', '10.2.0.1', $this->now));
        $mails = array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['subject'], 'Nahlášený inzerát')));
        $this->assertCount((int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1"), $mails);
        $this->assertStringContainsString('Podvodný inzerát', $mails[0]['body']);
        $this->assertSame(1, (int) val('SELECT COUNT(*) FROM listing_reports WHERE listing_id = ?', [$l['id']]));
        for ($i = 1; $i < LISTING_REPORT_PER_HOUR; $i++) {
            $this->assertNull(listing_report($l, 'Další hlášení ' . $i, '10.2.0.1', $this->now));
        }
        $this->assertStringContainsString('příliš mnoho hlášení', (string) listing_report($l, 'Ještě jedno', '10.2.0.1', $this->now));
        // Formulář hlášení má skryté pole, značku času a token proti CSRF.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/ext/listing/report.php');
        foreach (['name="website"', 'name="t"', 'csrf_field()'] as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
    }

    public function testAdminMenuResidentMenuAndDailyHook(): void
    {
        $this->assertSame(['Inzeráty vlastníků', 'Dům a lidé'], array_slice($GLOBALS['EXT_ADMIN_NAV']['/admin/inzeraty'], 0, 2));
        $this->assertTrue(($GLOBALS['EXT_ADMIN_NAV']['/admin/inzeraty'][2])(), 'podmínkou je jen zapnutí v Menu, ne beta program – ve všech edicích');
        $this->assertSame('', beta_menu_tag('/admin/inzeraty'));
        $this->assertContains('listing_daily', $GLOBALS['EXT_DAILY']);
        $this->assertTrue(($GLOBALS['EXT_RESIDENT_NAV']['/inzeraty'][1])());
        listing_setting_save('listing_enabled', '0');
        $this->assertFalse(($GLOBALS['EXT_RESIDENT_NAV']['/inzeraty'][1])());
        $this->assertNull(plan_feature_for('/inzeraty'), 'žádná edice ani beta');
        $this->assertNull(plan_feature_for('/inzerat/5-prodej'));
        $this->assertNull(plan_feature_for('/admin/inzeraty'));
        if (function_exists('beta_menu_tag')) {
            $this->assertSame('', beta_menu_tag('/admin/inzeraty'));
        }
    }

    public function testAdminListShowsUnitReportsAndHideForm(): void
    {
        $l = $this->published();
        listing_report($l, 'Podezřelá cena.', '10.3.0.1', $this->now);
        $rows = all('SELECT l.*, un.entrance, un.number, u.name owner_name, (SELECT COUNT(*) FROM listing_reports r WHERE r.listing_id = l.id) reports
            FROM listings l LEFT JOIN units un ON un.id = l.unit_id LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC');
        $reports = all('SELECT * FROM listing_reports');
        $penb = listing_penb();
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/listing/admin.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(e(unit_name($this->unitRow('a'))), $html, 'výbor jednotku vidí');
        $this->assertStringContainsString('/admin/inzeraty/' . $l['id'] . '/skryt', $html);
        $this->assertStringContainsString('Podezřelá cena.', $html);
        $this->assertStringContainsString('není zadaný', $html, 'upozornění na chybějící PENB');
    }

    public function testResidentsListShowsOnlyVisibleListings(): void
    {
        $pub = $this->published();
        $this->draft([], 3, 'b');
        $rows = array_values(array_filter(all("SELECT * FROM listings WHERE hidden = 0 AND status IN ('published','sold')"), fn($l) => listing_visibility($l) !== 'none'));
        $covers = [(int) $pub['id'] => listing_photos((int) $pub['id'])[0]];
        $canCreate = true;
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/listing/list.php';
        $html = (string) ob_get_clean();
        $this->assertSame(1, substr_count($html, 'class="card lst-card"'));
        $this->assertStringContainsString(listing_path($pub), $html);
        $this->assertStringNotContainsString(unit_name($this->unitRow('a')), $html);
    }

    /** Současný nájemník inzerované jednotky inzerát vidí (jako ostatní obyvatelé), ale nesmí ho upravovat ani zakládat. */
    public function testTenantOfTheUnitSeesButCannotEdit(): void
    {
        $l = $this->published();
        $this->assertTrue(listing_can_view($l, $this->u('tenant')));
        $this->assertFalse(listing_can_manage($l, $this->u('tenant')));
        $this->assertSame([], listing_owner_units($this->u('tenant')), 'no unit to create a listing for');
    }

    /** Popis fotky: uloží se (oříznutý, bez řídicích znaků), zobrazí se na stránce a jako alt; cizí fotky se nezmění. */
    public function testPhotoCaptions(): void
    {
        $l = $this->published();
        $photos = listing_photos((int) $l['id']);
        $other = listing_get($this->draft([], 3, 'b'));
        $foreign = listing_photos((int) $other['id'])[0];
        listing_photo_captions((int) $l['id'], [
            $photos[0]['id'] => "  Kuchyň <b>nová</b>\n ",
            $photos[1]['id'] => str_repeat('x', 300),
            $foreign['id'] => 'cizí',
        ]);
        $photos = listing_photos((int) $l['id']);
        $this->assertSame('Kuchyň <b>nová</b>', $photos[0]['caption']);
        $this->assertSame(LISTING_CAPTION_MAX, mb_strlen($photos[1]['caption']));
        $this->assertSame('', (string) listing_photos((int) $other['id'])[0]['caption'], 'photo of another listing untouched');
        $this->assertSame('Kuchyň <b>nová</b>', listing_photo_alt($photos[0], 0, 3));
        $this->assertSame('Fotka 3 z 3', listing_photo_alt($photos[2], 2, 3));
        $html = $this->renderPage(listing_get((int) $l['id']));
        $this->assertStringContainsString('<span class="lst-caption">Kuchyň &lt;b&gt;nová&lt;/b&gt;</span>', $html);
        $this->assertStringContainsString('alt="Kuchyň &lt;b&gt;nová&lt;/b&gt;"', $html);
        $this->assertStringNotContainsString('<b>nová</b>', $html);
    }

    /** Starší tabulka fotek bez sloupce caption dostane sloupec při prvním použití. */
    public function testCaptionColumnAddedToOldTable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE listing_photos (id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL DEFAULT 0, filename TEXT NOT NULL DEFAULT '', thumb TEXT NOT NULL DEFAULT '', width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0)");
        listing_ready($pdo);
        $this->assertContains('caption', array_column($pdo->query('PRAGMA table_info(listing_photos)')->fetchAll(\PDO::FETCH_ASSOC), 'name'));
    }

    /** Fotka v galerii se otevře v prohlížeči (předchozí/další, šipky, Esc) – bez skriptu odkaz na samotnou fotku. */
    public function testGalleryHasPhotoViewer(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/app.js');
        foreach (["querySelectorAll('.lst-gallery')", 'lst-v-prev', 'lst-v-next', 'lst-v-close', "'ArrowLeft'", "'ArrowRight'", 'touchend', 'showModal()'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $html = $this->renderPage($this->published());
        $this->assertMatchesRegularExpression('~<a class="lst-photo" href="[^"]*/inzerat/foto/\d+\.jpg">~', $html, 'fallback link to the photo');
        $this->assertStringContainsString('.lst-viewer', (string) file_get_contents(dirname(__DIR__, 2) . '/assets/style.css'));
    }

    // ---------- Balkon / lodžie zvlášť od plochy bytu ----------

    public function testOutdoorTypeAndAreaValidation(): void
    {
        [$d, $errors] = listing_input($this->post(), $this->unitRow('a'));
        $this->assertSame([], $errors, 'bez údaje = bez balkonu (starší formulář)');
        $this->assertSame(['none', null], [$d['outdoor_type'], $d['outdoor_area']]);
        [$d, $errors] = listing_input($this->post(['area' => '82', 'outdoor_type' => 'lodzie_zasklena', 'outdoor_area' => '9,7 m²']), $this->unitRow('a'));
        $this->assertSame([], $errors);
        $this->assertSame(['lodzie_zasklena', 9.7, 82.0], [$d['outdoor_type'], $d['outdoor_area'], $d['area']], 'plocha bytu zůstává zvlášť');
        foreach (['', '0', 'abc', '-3'] as $bad) {
            [, $errors] = listing_input($this->post(['outdoor_type' => 'balkon', 'outdoor_area' => $bad]), $this->unitRow('a'));
            $this->assertContains('Zadejte plochu balkonu, lodžie, terasy nebo předzahrádky v m², např. 9,7.', $errors, "plocha „{$bad}“");
        }
        [$d, $errors] = listing_input($this->post(['outdoor_type' => 'none', 'outdoor_area' => '12']), $this->unitRow('a'));
        $this->assertSame([], $errors);
        $this->assertNull($d['outdoor_area'], 'u „bez balkonu“ se plocha neukládá');
        [$d] = listing_input($this->post(['outdoor_type' => 'bazen']), $this->unitRow('a'));
        $this->assertSame('none', $d['outdoor_type'], 'neznámý druh');
        $this->assertSame(['none', 'balkon', 'lodzie', 'lodzie_zasklena', 'lodzie_zazdena', 'terasa', 'predzahradka'], array_keys(LISTING_OUTDOOR));
        // Uloží se a formulář ho zobrazí (plocha balkonu skrytá u „bez“ – app.js, data-lst-outdoor).
        $id = $this->draft(['area' => '82', 'outdoor_type' => 'terasa', 'outdoor_area' => '15,5']);
        $l = listing_get($id);
        $this->assertSame(['terasa', 15.5], [$l['outdoor_type'], (float) $l['outdoor_area']]);
        $unit = $this->unitRow('a');
        $errors = [];
        $photos = [];
        $penb = listing_penb();
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/listing/form.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('<option value="terasa" selected>Terasa</option>', $html);
        $this->assertStringContainsString('name="outdoor_area" inputmode="decimal" value="15,5"', $html);
        $this->assertStringContainsString('data-lst-outdoor', $html);
        $this->assertSame(['Balkon / lodžie', 'Plocha balkonu / lodžie (m²)'], [listing_ai_fields()['outdoor_type'], listing_ai_fields()['outdoor_area']],
            'AI pomocník dostane druh i plochu balkonu');
        $this->assertStringContainsString("[data-lst-outdoor]", (string) file_get_contents(dirname(__DIR__, 2) . '/assets/app.js'));
    }

    public function testOutdoorShownOnPageAndInShareTexts(): void
    {
        $l = $this->published(['area' => '82', 'outdoor_type' => 'lodzie_zasklena', 'outdoor_area' => '9,7']);
        $html = $this->renderPage($l);
        $this->assertStringContainsString("<div><dt>Plocha</dt><dd>82\u{a0}m²</dd></div>", $html);
        $this->assertStringContainsString("<div class=\"lst-outdoor\"><dt>Zasklená lodžie</dt><dd>9,7\u{a0}m²</dd></div>", $html);
        $this->assertSame("82\u{a0}m² + zasklená lodžie 9,7\u{a0}m²", listing_area_text($l));
        $this->assertStringContainsString("\n82 m² + zasklená lodžie 9,7 m², 3. patro", listing_share_text($l));
        $this->assertStringStartsWith("82\u{a0}m² + zasklená lodžie 9,7\u{a0}m² · 3. patro", listing_meta($l, [])['og:description']);
        // Bez balkonu beze změny.
        q("UPDATE listings SET outdoor_type = 'none', outdoor_area = NULL WHERE id = ?", [$l['id']]);
        $l = listing_get((int) $l['id']);
        $this->assertSame("82\u{a0}m²", listing_area_text($l));
        $this->assertStringNotContainsString('lst-outdoor', $this->renderPage($l));
        // Starší inzerát bez údaje ('').
        $this->assertSame('', listing_outdoor_text(['outdoor_type' => '', 'outdoor_area' => null]));
        $this->assertSame("balkon 4\u{a0}m²", listing_area_text(['area' => null, 'outdoor_type' => 'balkon', 'outdoor_area' => 4]));
        // AI úkol „Napsat inzerát“ ví, že plochy se nesčítají.
        $this->assertStringContainsString('balkon / lodžie', AI_TASKS['listing'][1]);
    }

    /** Starší tabulka inzerátů bez sloupců pro balkon dostane sloupce při prvním použití; údaje zůstanou. */
    public function testOutdoorColumnsAddedToOldTable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE listings (id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER NOT NULL DEFAULT 0, area REAL, status TEXT NOT NULL DEFAULT '')");
        $pdo->exec("INSERT INTO listings (unit_id, area, status) VALUES (3, 54.3, 'published')");
        listing_ready($pdo);
        $cols = array_column($pdo->query('PRAGMA table_info(listings)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $this->assertContains('outdoor_type', $cols);
        $this->assertContains('outdoor_area', $cols);
        $row = $pdo->query('SELECT * FROM listings')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['', null, 54.3], [$row['outdoor_type'], $row['outdoor_area'], (float) $row['area']]);
        $this->assertSame('none', listing_outdoor_type($row));
    }

    /** Přehled Inzeráty ukáže vlastníkovi i jeho koncept („Vaše inzeráty“), ostatním ne. */
    public function testOverviewShowsOwnersOwnListings(): void
    {
        $draft = listing_get($this->draft());
        $mine = listing_mine($this->u('owner'));
        $this->assertSame([(int) $draft['id']], array_map(fn($l) => (int) $l['id'], $mine));
        $this->assertSame('koncept', listing_owner_state($draft));
        $this->assertSame([], listing_mine($this->u('tenant')));
        $this->assertSame([], listing_mine($this->u('other')));
        $rows = []; $covers = []; $canCreate = true;
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/listing/list.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Vaše inzeráty', $html);
        $this->assertStringContainsString('/inzeraty/' . $draft['id'] . '/upravit', $html);
        $this->assertStringContainsString('Koncept vidíte jen Vy', $html);
        $this->assertStringNotContainsString(listing_path($draft), $html, 'no public link for a draft');
        $pub = listing_get($this->published()['id']);
        $this->assertSame('zveřejněný', listing_owner_state($pub));
    }

    // ---------- Vybavení a příslušenství (sloupec features, JSON podle LISTING_FEATURES) ----------

    private function rentPost(array $features, array $over = []): array
    {
        return $this->post($over + ['type' => 'pronajem', 'price' => '18 500', 'fees' => '3 500', 'features' => $features]);
    }

    public function testFeaturesWhitelistAndTypeSpecificFields(): void
    {
        $raw = ['sklep' => '1', 'sklep_plocha' => '2,5 m²', 'parkovani' => 'garaz', 'parkovani_cena' => '1 500', 'komora' => '1', 'kolarna' => '',
            'vytah' => 'ano', 'bezbarierovy' => '1', 'stav' => 'po_kompletni_renovaci', 'zarizeni' => 'castecne', 'spotrebice' => ['pracka', 'lednice', 'lednice', 'bazen', ['x']],
            'vytapeni' => 'ustredni', 'internet' => 'optika', 'vlastnictvi' => 'osobni', 'kauce' => '37 000', 'min_doba' => '12', 'zvirata' => 'dohodou', 'nastehovani' => '2027-02-01',
            'sauna' => '1', 'evil' => '<script>'];
        // Prodej: údaje pro pronájem se ignorují (bez chyby), neznámé klíče a volby se zahodí.
        [$d, $errors] = listing_input($this->post(['features' => $raw]), $this->unitRow('a'));
        $this->assertSame([], $errors);
        $this->assertSame(['sklep' => true, 'sklep_plocha' => 2.5, 'parkovani' => 'garaz', 'parkovani_cena' => 1500, 'komora' => true, 'vytah' => 'ano', 'bezbarierovy' => true,
            'stav' => 'po_kompletni_renovaci', 'zarizeni' => 'castecne', 'spotrebice' => ['lednice', 'pracka'], 'vytapeni' => 'ustredni', 'internet' => 'optika', 'vlastnictvi' => 'osobni'],
            json_decode($d['features'], true));
        // Pronájem: vlastnictví se ignoruje, kauce, doba, zvířata a datum nastěhování se uloží.
        [$d, $errors] = listing_input($this->rentPost($raw), $this->unitRow('a'));
        $this->assertSame([], $errors);
        $f = json_decode($d['features'], true);
        $this->assertArrayNotHasKey('vlastnictvi', $f);
        $this->assertSame([37000, 12, 'dohodou', '2027-02-01'], [$f['kauce'], $f['min_doba'], $f['zvirata'], $f['nastehovani']]);
        // „ihned“ má přednost před datem; neplatné volby se nevyberou.
        [$d] = listing_input($this->rentPost(['nastehovani' => '2027-02-01', 'ihned' => '1', 'zvirata' => 'krokodyl', 'parkovani' => 'hangar', 'vytah' => 'mozna']), $this->unitRow('a'));
        $this->assertSame(['nastehovani' => 'ihned'], json_decode($d['features'], true));
        // Závislé údaje bez hlavního se neuloží (plocha bez sklepa, příplatek bez parkování).
        [$d] = listing_input($this->post(['features' => ['sklep_plocha' => '3', 'parkovani_cena' => '500']]), $this->unitRow('a'));
        $this->assertSame('{}', $d['features']);
        // Bez údaje (starší formulář) i s nesmyslným tvarem = prázdné.
        foreach ([null, 'text', ['spotrebice' => 'lednice']] as $bad) {
            [$d, $errors] = listing_input($this->post(['features' => $bad]), $this->unitRow('a'));
            $this->assertSame([[], '{}'], [$errors, $d['features']]);
        }
        $this->assertSame(['prislusenstvi', 'dum', 'stav', 'prodej', 'pronajem'], array_keys(LISTING_FEATURE_GROUPS));
        foreach (LISTING_FEATURES as $k => $def) {
            $this->assertArrayHasKey($def['group'], LISTING_FEATURE_GROUPS, $k);
        }
    }

    public function testFeatureNumbersAndDatesAreChecked(): void
    {
        $cases = [
            ['kauce', 'abc', 'Kauci zadejte v Kč (jen číslo), nebo pole nechte prázdné.'],
            ['kauce', '0', 'Kauci zadejte v Kč (jen číslo), nebo pole nechte prázdné.'],
            ['kauce', '12345678', 'Kauci zadejte v Kč (jen číslo), nebo pole nechte prázdné.'],
            ['min_doba', '0', 'Minimální dobu nájmu zadejte v měsících (1 až 120), nebo pole nechte prázdné.'],
            ['min_doba', '121', 'Minimální dobu nájmu zadejte v měsících (1 až 120), nebo pole nechte prázdné.'],
            ['min_doba', '6,5', 'Minimální dobu nájmu zadejte v měsících (1 až 120), nebo pole nechte prázdné.'],
            ['nastehovani', '2027-02-30', 'Datum nastěhování zadejte jako den v kalendáři, nebo zaškrtněte „ihned“.'],
            ['nastehovani', 'brzy', 'Datum nastěhování zadejte jako den v kalendáři, nebo zaškrtněte „ihned“.'],
        ];
        foreach ($cases as [$k, $v, $msg]) {
            [, $errors] = listing_input($this->rentPost([$k => $v]), $this->unitRow('a'));
            $this->assertSame([$msg], $errors, "$k = $v");
            // U prodeje se pole pro pronájem vůbec nečtou.
            [, $errors] = listing_input($this->post(['features' => [$k => $v]]), $this->unitRow('a'));
            $this->assertSame([], $errors, "prodej: $k = $v");
        }
        [, $errors] = listing_input($this->post(['features' => ['sklep' => '1', 'sklep_plocha' => '500']]), $this->unitRow('a'));
        $this->assertSame(['Plochu sklepa zadejte v m² jako číslo, např. 2,5.'], $errors);
        [, $errors] = listing_input($this->post(['features' => ['parkovani' => 'garaz', 'parkovani_cena' => 'zdarma']]), $this->unitRow('a'));
        $this->assertSame(['Příplatek za parkování zadejte v Kč (jen číslo), nebo pole nechte prázdné.'], $errors);
        [$d, $errors] = listing_input($this->rentPost(['kauce' => '37 000 Kč', 'min_doba' => '120']), $this->unitRow('a'));
        $this->assertSame([], $errors);
        $this->assertSame(['kauce' => 37000, 'min_doba' => 120], json_decode($d['features'], true));
    }

    /** Uložené údaje se při čtení znovu ověří podle schématu (ruční zásah do databáze, změna typu inzerátu). */
    public function testStoredFeaturesAreCleanedOnRead(): void
    {
        $id = $this->draft(['features' => ['sklep' => '1']]);
        $this->assertSame(['sklep' => true], listing_features(listing_get($id)));
        q('UPDATE listings SET features = ? WHERE id = ?', ['{"sklep":true,"evil":"<script>alert(1)</script>","stav":"<b>zruina</b>","kauce":5000,"spotrebice":["lednice","<i>"]}', $id]);
        $this->assertSame(['sklep' => true], listing_features(listing_get($id)), 'prodej: kauce se neukáže, neplatné hodnoty pryč');
        $this->assertNull(listing_publish($id, $this->now));
        $html = $this->renderPage(listing_get($id));
        $this->assertStringContainsString('<span class="lst-fl">Sklep</span>', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('zruina', $html);
        $this->assertStringNotContainsString('Kauce', $html);
        foreach (['', 'null', '[1,2]', '{nejde', '"sklep"'] as $bad) {
            $this->assertSame([], listing_features(['type' => 'prodej', 'features' => $bad]), $bad);
        }
        $this->assertSame([], listing_features(['type' => 'prodej']), 'starší řádek bez sloupce');
    }

    /** Starší tabulka inzerátů (s balkonem, bez vybavení) dostane sloupec features při prvním použití. */
    public function testFeaturesColumnAddedToOldTable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE listings (id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER NOT NULL DEFAULT 0, type TEXT NOT NULL DEFAULT '', outdoor_type TEXT NOT NULL DEFAULT '', outdoor_area REAL, status TEXT NOT NULL DEFAULT '')");
        $pdo->exec("INSERT INTO listings (unit_id, type, outdoor_type, status) VALUES (3, 'prodej', 'balkon', 'published')");
        listing_ready($pdo);
        $cols = array_column($pdo->query('PRAGMA table_info(listings)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $this->assertContains('features', $cols);
        $row = $pdo->query('SELECT * FROM listings')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['{}', 'balkon'], [$row['features'], $row['outdoor_type']]);
        $this->assertSame([], listing_features($row));
        $this->assertSame([], listing_feature_items($row + ['price' => 0]));
        // Nová tabulka má sloupec rovnou.
        $fresh = new \PDO('sqlite::memory:');
        listing_ready($fresh);
        $this->assertContains('features', array_column($fresh->query('PRAGMA table_info(listings)')->fetchAll(\PDO::FETCH_ASSOC), 'name'));
    }

    public function testFeaturesChecklistOnPublicPage(): void
    {
        $l = $this->published($this->rentPost(['sklep' => '1', 'sklep_plocha' => '2', 'parkovani' => 'stani_kryte', 'parkovani_cena' => '800', 'kolarna' => '1', 'vytah' => 'ne',
            'stav' => 'dobry', 'spotrebice' => ['lednice', 'mycka'], 'internet' => 'dsl', 'kauce' => '37000', 'min_doba' => '12', 'zvirata' => 'dohodou', 'ihned' => '1']));
        $html = $this->renderPage($l);
        $this->assertStringContainsString('<h2 id="lst-features-h">Vybavení a příslušenství</h2>', $html);
        $this->assertStringContainsString("<span class=\"lst-fl\">Sklep</span><span class=\"lst-fsep\"> · </span><span class=\"lst-fv\">2\u{a0}m²</span>", $html);
        $this->assertStringContainsString("<span class=\"lst-fl\">Kryté parkovací stání</span><span class=\"lst-fsep\"> · </span><span>příplatek</span> <span class=\"lst-fv\">800\u{a0}Kč / měsíc</span>", $html);
        $this->assertStringContainsString("<span class=\"lst-fl\">Kauce</span><span class=\"lst-fsep\">: </span><span class=\"lst-fv\">37\u{a0}000\u{a0}Kč</span> <small class=\"lst-fnote\">2 nájmy</small>", $html);
        $this->assertStringContainsString('<span class="lst-fl">Minimální doba nájmu</span><span class="lst-fsep">: </span><span class="lst-fv">12 měsíců</span>', $html);
        $this->assertStringContainsString('<span class="lst-fl">Domácí zvířata</span><span class="lst-fsep">: </span><span class="lst-fv">dohodou</span>', $html);
        $this->assertStringContainsString('<span class="lst-fl">K nastěhování</span><span class="lst-fsep">: </span><span class="lst-fv">ihned</span>', $html);
        $this->assertMatchesRegularExpression('~<li class="lst-no"><span class="lst-mark" aria-hidden="true">–</span>\s*<span class="lst-ftext"><span class="lst-fl">Bez výtahu</span>~u', $html);
        foreach (['Kolárna', 'V dobrém stavu', 'Lednice', 'Myčka', 'Internet DSL'] as $item) {
            $this->assertMatchesRegularExpression('~<li><span class="lst-mark" aria-hidden="true">✓</span>\s*<span class="lst-ftext"><span class="lst-fl">' . preg_quote($item, '~') . '</span></span></li>~u', $html, $item);
        }
        // Vynechané: co vlastník nevyplnil, se neukáže.
        foreach (['Komora', 'Trouba', 'Vytápění', 'topení', 'Vlastnictví', 'Bezbariérový'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        // Pořadí podle schématu: příslušenství, dům, stav, pronájem.
        $this->assertLessThan(strpos($html, '>Kauce<'), strpos($html, 'Bez výtahu'));
        $this->assertLessThan(strpos($html, 'Bez výtahu'), strpos($html, '>Sklep<'));
        // Kauce, která není násobkem nájmu, je bez počtu nájmů; datum nastěhování jako datum.
        q('UPDATE listings SET features = ? WHERE id = ?', ['{"kauce":40000,"nastehovani":"2027-02-01","zvirata":"ne","vytah":"ano"}', $l['id']]);
        $html = $this->renderPage(listing_get((int) $l['id']));
        $this->assertStringContainsString("<span class=\"lst-fv\">40\u{a0}000\u{a0}Kč</span></span>", $html);
        $this->assertStringContainsString('<span class="lst-fv">1. 2. 2027</span>', $html);
        $this->assertMatchesRegularExpression('~<li class="lst-no"><span class="lst-mark" aria-hidden="true">–</span>\s*<span class="lst-ftext"><span class="lst-fl">Domácí zvířata</span><span class="lst-fsep">: </span><span class="lst-fv">ne</span>~u', $html);
        $this->assertMatchesRegularExpression('~<li><span class="lst-mark" aria-hidden="true">✓</span>\s*<span class="lst-ftext"><span class="lst-fl">Výtah</span>~u', $html);
        // Bez údajů žádný oddíl.
        q("UPDATE listings SET features = '{}' WHERE id = ?", [$l['id']]);
        $this->assertStringNotContainsString('lst-features', $this->renderPage(listing_get((int) $l['id'])));
        $this->assertSame(['1 nájem', '3 nájmy', '5 nájmů', '1 měsíc', '4 měsíce', '24 měsíců'], [listing_plural(1, 'nájem', 'nájmy', 'nájmů'), listing_plural(3, 'nájem', 'nájmy', 'nájmů'),
            listing_plural(5, 'nájem', 'nájmy', 'nájmů'), listing_plural(1, 'měsíc', 'měsíce', 'měsíců'), listing_plural(4, 'měsíc', 'měsíce', 'měsíců'), listing_plural(24, 'měsíc', 'měsíce', 'měsíců')]);
        // Na telefonu jeden sloupec (žádné vodorovné posouvání).
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/style.css');
        $this->assertStringContainsString('@media (max-width: 520px) { .lst-checklist { grid-template-columns: minmax(0, 1fr); } }', $css);
    }

    public function testFeaturesInShareTextAndLinkPreview(): void
    {
        $l = $this->published($this->rentPost(['sklep' => '1', 'sklep_plocha' => '2,5', 'parkovani' => 'garaz', 'parkovani_cena' => '1500', 'vytah' => 'ano',
            'spotrebice' => ['mycka', 'lednice'], 'internet' => 'dsl', 'kauce' => '37000', 'zvirata' => 'dohodou', 'ihned' => '1'], ['description' => 'Světlý byt u parku, klidná ulice, dobrá dostupnost.']));
        $share = listing_share_text($l);
        $this->assertStringContainsString("\nVybavení: sklep 2,5 m², garáž – příplatek 1 500 Kč / měsíc, výtah, lednice, myčka, internet DSL, kauce 37 000 Kč (2 nájmy), domácí zvířata dohodou, k nastěhování ihned\nCena: 18 500 Kč / měsíc", $share);
        $this->assertStringNotContainsString("\u{a0}", $share);
        $og = listing_meta($l, listing_photos((int) $l['id']))['og:description'];
        $this->assertStringContainsString('sklep, garáž, výtah, lednice, myčka, internet DSL', $og);
        $this->assertStringNotContainsString('kauce', $og, 'náhled odkazu jen klíčová slova');
        $this->assertSame('sklep, garáž, výtah, lednice, myčka, internet DSL', listing_features_text($l, true));
        // V náhledu nejvýše LISTING_FEATURES_SHORT_MAX klíčových slov (Bazoš dostane všechno).
        $many = ['type' => 'pronajem', 'price' => 18500, 'features' => '{"sklep":true,"komora":true,"kolarna":true,"vytah":"ano","bezbarierovy":true,"stav":"novostavba","zarizeni":"zarizeny","spotrebice":["lednice","mycka","trouba"]}'];
        $this->assertSame('sklep, komora / půda, kolárna, výtah, bezbariérový přístup, novostavba, zařízený, lednice', listing_features_text($many, true));
        $this->assertStringEndsWith('lednice, myčka, trouba', listing_features_text($many));
        // Bez vybavení zůstává text jako dřív.
        q("UPDATE listings SET features = '{}' WHERE id = ?", [$l['id']]);
        $l = listing_get((int) $l['id']);
        $this->assertStringNotContainsString('Vybavení:', listing_share_text($l));
        $this->assertSame('', listing_features_text($l));
    }

    public function testAiHelperGetsFeatures(): void
    {
        $f = listing_ai_fields();
        $this->assertSame('Sklep', $f['features[sklep]']);
        $this->assertSame('Spotřebiče', $f['features[spotrebice][]']);
        $this->assertSame('Kauce v Kč', $f['features[kauce]']);
        $this->assertSame('K nastěhování ihned', $f['features[ihned]']);
        $this->assertSame('Energetická třída', $f['energy']);
        foreach (LISTING_FEATURES as $k => $def) {
            $this->assertTrue(isset($f["features[$k]"]) || isset($f["features[$k][]"]), $k);
        }
        // Formulář je předá AI pomocníkovi (obě políčka: text inzerátu i krátký text pro sdílení).
        $this->assertSame(1, substr_count((string) file_get_contents(dirname(__DIR__, 2) . '/app/views/ext/listing/form.php'), '$aiFieldsListing = listing_ai_fields();'));
        $aiShow = true; $aiUrl = '/ai-text'; $aiTarget = 'textarea[name=description]'; $aiTasks = ['listing']; $aiContext = listing_ai_context(); $aiFields = $f;
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/_ai_mini.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('&quot;features[spotrebice][]&quot;:&quot;Spotřebiče&quot;', $html);
        // app.js: u zaškrtávacích polí „ano“ nebo popisky zaškrtnutých, nevybraná volba se neposílá.
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/app.js');
        $this->assertStringContainsString("name.endsWith('[]')", $js);
        $this->assertStringContainsString("el.value !== '' && el.selectedIndex >= 0", $js);
        $this->assertStringContainsString('vybavení a příslušenství – sklep, parkování, výtah', AI_TASKS['listing'][1]);
    }
    private function renderForm(array $l, array $errors = []): string
    {
        $unit = $this->unitRow('a');
        $photos = [];
        $penb = listing_penb();
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/listing/form.php';
        return (string) ob_get_clean();
    }

    public function testFormShowsFeatureGroups(): void
    {
        $l = listing_get($this->draft($this->rentPost(['sklep' => '1', 'sklep_plocha' => '2,5', 'parkovani' => 'garaz', 'spotrebice' => ['trouba'], 'zvirata' => 'ne', 'nastehovani' => '2027-03-01'])));
        $html = $this->renderForm($l);
        $this->assertStringContainsString('<input type="checkbox" name="features[sklep]" value="1" checked>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="features[komora]" value="1">', $html);
        $this->assertStringContainsString('name="features[sklep_plocha]" inputmode="decimal" value="2,5"', $html);
        $this->assertStringContainsString('<option value="garaz" selected>Garáž</option>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="features[spotrebice][]" value="trouba" checked>', $html);
        $this->assertStringContainsString('<option value="ne" selected>ne</option>', $html);
        $this->assertStringContainsString('name="features[nastehovani]" value="2027-03-01"', $html);
        $this->assertStringContainsString('<fieldset class="lst-fgroup" data-lst-for="prodej">', $html);
        $this->assertStringContainsString('<fieldset class="lst-fgroup" data-lst-for="pronajem">', $html);
        $this->assertStringContainsString('<label data-lst-needs="features[sklep]">', $html);
        $this->assertStringNotContainsString(' onchange=', $html, 'bez inline JS (CSP)');
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/app.js');
        foreach (['[data-lst-for]', '[data-lst-needs]', '[data-lst-move-now]'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        // Po chybě zůstane v poli, co vlastník napsal.
        $html = $this->renderForm(array_merge($l, ['features_post' => ['kauce' => 'hodně']]));
        $this->assertStringContainsString('name="features[kauce]" inputmode="numeric" value="hodně"', $html);
    }

    /** Výtah se do nového inzerátu předvyplní z posledního jiného inzerátu v domě, který ho uvádí. */
    public function testLiftPrefilledFromOtherListingInHouse(): void
    {
        $this->assertSame('', listing_house_lift());
        $this->assertSame('{}', listing_defaults($this->unitRow('a'), $this->u('owner'))['features']);
        $this->draft(['features' => ['vytah' => 'ano']], 0, 'b');
        $this->assertSame('ano', listing_house_lift());
        $newer = $this->draft(['features' => ['vytah' => 'ne', 'sklep' => '1']], 0, 'b');
        $this->draft(['features' => ['sklep' => '1']], 0, 'b');   // ještě novější bez údaje o výtahu nic nemění
        $this->assertSame('ne', listing_house_lift());
        $this->assertSame('ano', listing_house_lift($newer), 'vlastní inzerát se nepočítá');
        $def = listing_defaults($this->unitRow('a'), $this->u('owner'));
        $this->assertSame(['vytah' => 'ne'], listing_features($def), 'jen výtah, ne sklep');
        $html = $this->renderForm($def);
        $this->assertStringContainsString('<select name="features[vytah]"><option value="">– neuvedeno –</option><option value="ano">Výtah</option><option value="ne" selected>Bez výtahu</option></select>', $html);
    }

    /** Popisky a hodnoty vybavení (skládané v PHP) jsou přeložené ve všech jazycích webu. */
    public function testFeatureTextsAreTranslated(): void
    {
        $texts = ['– neuvedeno –', 'Kauce', 'Minimální doba nájmu', 'K nastěhování', 'ihned', '2 nájmy', '1 nájem', '5 nájmů', '1 měsíc', '3 měsíce', '12 měsíců', '/ měsíc',
            "800\u{a0}Kč / měsíc", 'Kauci zadejte v Kč (jen číslo), nebo pole nechte prázdné.', 'Minimální dobu nájmu zadejte v měsících (1 až 120), nebo pole nechte prázdné.',
            'Datum nastěhování zadejte jako den v kalendáři, nebo zaškrtněte „ihned“.', 'Plochu sklepa zadejte v m² jako číslo, např. 2,5.',
            'Příplatek za parkování zadejte v Kč (jen číslo), nebo pole nechte prázdné.'];
        foreach (LISTING_FEATURES as $def) {
            $texts[] = $def['label'];
            foreach ($def['options'] ?? [] as $o) {
                $texts[] = $o;
            }
        }
        foreach (LISTING_FEATURE_GROUPS as $g) {
            $texts[] = $g;
        }
        foreach (array_unique($texts) as $text) {
            foreach (['en', 'sk', 'uk', 'vi'] as $lang) {
                // Slovensky může text vyjít stejně (Garáž, 2 nájmy) – pak musí být aspoň ve slovníku (i jako vzor s #).
                $inSk = isset(lang_dictionary('sk')[$text]) || isset(lang_dictionary('sk')[(string) preg_replace('/\d+(?:[.,]\d+)*/', '#', $text)]);
                $this->assertTrue(t($text, $lang) !== $text || ($lang === 'sk' && $inSk), "$lang: $text");
            }
        }
    }
}
