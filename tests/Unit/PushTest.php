<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * app/push_lib.php. Žádné skutečné HTTP: odběry v testech mají adresu mimo povolené push služby,
 * takže push_deliver() skončí dřív, než by se spojil (vrátí 0) – to se projeví v počítadle `fails`.
 */
final class PushTest extends TestCase
{
    private const BLOCKED = 'https://push.example.invalid/sub/';

    /** Klíče „prohlížeče“: [soukromý klíč OpenSSL, veřejný bod 65 B, auth 16 B]. */
    private static function browserKeys(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($k)['ec'];
        return [$k, "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT), random_bytes(16)];
    }

    private static function addSub(int $userId, int $fails = 0): int
    {
        [, $pub, $auth] = self::browserKeys();
        q("INSERT INTO push_subs (user_id, endpoint, p256dh, auth, device, created_at, last_ok, fails) VALUES (?,?,?,?,'test',?,'',?)",
            [$userId, self::BLOCKED . bin2hex(random_bytes(6)), b64url($pub), b64url($auth), date('Y-m-d H:i:s'), $fails]);
        return (int) db()->lastInsertId();
    }

    private static function fails(int $subId): ?int
    {
        $v = val('SELECT fails FROM push_subs WHERE id = ?', [$subId]);
        return $v === null || $v === false ? null : (int) $v;
    }

    public function testBase64Url(): void
    {
        foreach ([0, 1, 2, 3, 16, 65, 100] as $len) {
            $raw = $len ? random_bytes($len) : '';
            $enc = b64url($raw);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]*$/', $enc, 'no padding, URL-safe alphabet');
            $this->assertSame($raw, b64url_decode($enc));
        }
        $this->assertSame("\xfb\xff", b64url_decode('-_8'));
        $this->assertSame('', b64url_decode('***'), 'invalid input decodes to empty string');
    }

    public function testVapidKeyIsStableP256Point(): void
    {
        $k = push_vapid();
        $this->assertSame(65, strlen($k['public']));
        $this->assertSame("\x04", $k['public'][0], 'uncompressed EC point');
        $this->assertSame($k, push_vapid());
        $this->assertFileExists(ROOT_STORAGE . '/vapid.pem');
        $this->assertSame(0600, fileperms(ROOT_STORAGE . '/vapid.pem') & 0777);
        $pub = push_public_key();
        $this->assertSame(87, strlen($pub));
        $this->assertSame($k['public'], b64url_decode($pub));
        $d = openssl_pkey_get_details(openssl_pkey_get_private((string) file_get_contents(ROOT_STORAGE . '/vapid.pem')));
        $this->assertSame('prime256v1', $d['ec']['curve_name']);
    }

    public function testEndpointAllowList(): void
    {
        $ok = ['https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/Q', 'https://wns2-par02p.notify.windows.com/w/?token=1',
            'https://android.googleapis.com/gcm/send/1'];
        foreach ($ok as $u) {
            $this->assertTrue(push_endpoint_ok($u), $u);
        }
        $bad = ['http://fcm.googleapis.com/fcm/send/abc', 'https://fcm.googleapis.com.evil.test/x', 'https://evilfcm.googleapis.com/x', 'https://googleapis.com/x',
            'https://127.0.0.1/x', 'http://127.0.0.1:8080/x', 'ftp://push.apple.com/x', 'notaurl', '', 'https:///x'];
        foreach ($bad as $u) {
            $this->assertFalse(push_endpoint_ok($u), $u);
        }
        $prev = getenv('SVJ_PUSH_TEST');
        putenv('SVJ_PUSH_TEST=1');
        try {
            $this->assertTrue(push_endpoint_ok('http://127.0.0.1:8123/push'), 'local endpoint only for the help-verification run');
            $this->assertFalse(push_endpoint_ok('http://localhost:8123/push'));
            $this->assertFalse(push_endpoint_ok('https://127.0.0.1/push'));
        } finally {
            putenv($prev === false ? 'SVJ_PUSH_TEST' : 'SVJ_PUSH_TEST=' . $prev);
        }
    }

    public function testEncryptFollowsRfc8291AndDecrypts(): void
    {
        [$priv, $pub, $auth] = self::browserKeys();
        $payload = json_encode(['title' => 'Havárie vody', 'body' => 'Uzavřeno do 14:00'], JSON_UNESCAPED_UNICODE);
        $msg = push_encrypt($payload, b64url($pub), b64url($auth));
        $this->assertIsString($msg);

        // Hlavička aes128gcm: salt(16) | rs(4) | idlen(1) | keyid(65)
        $salt = substr($msg, 0, 16);
        $this->assertSame(4096, unpack('N', substr($msg, 16, 4))[1]);
        $this->assertSame(65, ord($msg[20]));
        $as = substr($msg, 21, 65);
        $this->assertSame("\x04", $as[0]);
        $body = substr($msg, 86);
        $tag = substr($body, -16);
        $cipher = substr($body, 0, -16);

        $asKey = push_point_key($as);
        $shared = openssl_pkey_derive($asKey, $priv, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $pub . $as, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        $this->assertSame($payload . "\x02", $plain, 'last-record delimiter 0x02');

        $this->assertNotSame($msg, push_encrypt($payload, b64url($pub), b64url($auth)), 'fresh salt and ephemeral key');
    }

    public function testEncryptRejectsBadKeys(): void
    {
        [, $pub, $auth] = self::browserKeys();
        $this->assertNull(push_encrypt('x', b64url(substr($pub, 1)), b64url($auth)), 'point must be 65 bytes');
        $this->assertNull(push_encrypt('x', b64url($pub), b64url(substr($auth, 1))), 'auth must be 16 bytes');
        $this->assertNull(push_encrypt('x', b64url("\x04" . str_repeat("\x01", 64)), b64url($auth)), 'point not on curve');
    }

    public function testVapidHeaderIsValidEs256Jwt(): void
    {
        $h = push_vapid_header('https://fcm.googleapis.com:443/fcm/send/abc?x=1');
        $this->assertMatchesRegularExpression('/^vapid t=([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/', $h);
        preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/', $h, $m);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(b64url_decode($m[1]), true));
        $claims = json_decode(b64url_decode($m[2]), true);
        $this->assertSame('https://fcm.googleapis.com:443', $claims['aud'], 'origin only, no path');
        $this->assertEqualsWithDelta(time() + 12 * 3600, $claims['exp'], 5);
        $this->assertStringStartsWith('mailto:', $claims['sub']);
        $this->assertSame(push_public_key(), $m[4]);

        $raw = b64url_decode($m[3]);
        $this->assertSame(64, strlen($raw));
        $int = function (string $b): string {
            $b = ltrim($b, "\0");
            if ($b === '' || ord($b[0]) & 0x80) {
                $b = "\0" . $b;
            }
            return "\x02" . chr(strlen($b)) . $b;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($seq)) . $seq;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . push_vapid()['public']), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $this->assertSame(1, openssl_verify($m[1] . '.' . $m[2], $der, $pem, OPENSSL_ALGO_SHA256), 'signature verifies with the VAPID public key');
    }

    public function testNotifyRespectsUserSettingAndCountsFailures(): void
    {
        $this->assertTrue(plan_has('push'), 'precondition: demo house is Premium');
        $users = array_map('intval', array_column(all("SELECT id FROM users WHERE role = 'resident' AND active = 1 ORDER BY id LIMIT 2"), 'id'));
        [$on, $off] = $users;
        $subOn = self::addSub($on);
        $subOff = self::addSub($off);
        $subDying = self::addSub($on, 9);
        $origOff = (int) val('SELECT push_notify FROM users WHERE id = ?', [$off]);
        q('UPDATE users SET push_notify = 0 WHERE id = ?', [$off]);
        try {
            $this->assertSame(0, push_notify([], 'x', 'y'));
            $this->assertSame(0, push_notify([0, -1], 'x', 'y'));
            $this->assertEqualsCanonicalizing([$on, $off], array_values(array_intersect(push_all_user_ids(), [$on, $off])));

            $this->assertSame(0, push_notify([$on, $off, $on], 'Titulek', 'Text'), 'nothing delivered (blocked endpoint)');
            $this->assertSame(1, self::fails($subOn), 'delivery attempted → failure counted');
            $this->assertSame(0, self::fails($subOff), 'user turned notifications off → not attempted');
            $this->assertNull(self::fails($subDying), 'device failing 10× is removed');

            $this->assertSame(0, push_notify([$off], 'Zkouška', 'Text', '/', true));
            $this->assertSame(1, self::fails($subOff), 'forced test notification ignores the user setting');
        } finally {
            q('UPDATE users SET push_notify = ? WHERE id = ?', [$origOff, $off]);
            q('DELETE FROM push_subs WHERE id IN (?,?,?)', [$subOn, $subOff, $subDying]);
        }
    }

    public function testNotifySkipsInactiveUsers(): void
    {
        $u = (int) val("SELECT id FROM users WHERE role = 'resident' AND active = 1 ORDER BY id DESC LIMIT 1");
        $sub = self::addSub($u);
        q('UPDATE users SET active = 0 WHERE id = ?', [$u]);
        try {
            push_notify([$u], 'x', 'y');
            $this->assertSame(0, self::fails($sub));
            $this->assertNotContains($u, push_all_user_ids());
        } finally {
            q('UPDATE users SET active = 1 WHERE id = ?', [$u]);
            q('DELETE FROM push_subs WHERE id = ?', [$sub]);
        }
    }

    public function testAdminIds(): void
    {
        $expected = array_map('intval', array_column(all("SELECT id FROM users WHERE role = 'admin' AND active = 1"), 'id'));
        $this->assertNotEmpty($expected);
        $this->assertSame($expected, push_admin_ids());
    }

    /** Bez funkce „push“ v edici (Zdarma) se nic neposílá. Edice se čte jednou za požadavek, proto samostatný proces. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNotifyDoesNothingWithoutPushInPlan(): void
    {
        pq("UPDATE tenants SET billing = 1, plan = 'free', paid_until = '', trial_ends = '' WHERE slug = ?", [TENANT]);
        $this->assertSame('free', plan_edition());
        $this->assertFalse(plan_has('push'));
        $u = (int) val("SELECT id FROM users WHERE role = 'resident' AND active = 1 ORDER BY id LIMIT 1");
        $sub = self::addSub($u);
        $this->assertSame(0, push_notify([$u], 'x', 'y', '/', true));
        $this->assertSame(0, self::fails($sub), 'no delivery attempted');
    }

    public function testAppIconPng(): void
    {
        $png = app_icon_png('#1d5446');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $info = getimagesizefromstring($png);
        $this->assertSame([180, 180], [$info[0], $info[1]]);
        $im = imagecreatefromstring($png);
        $this->assertSame([0x1d, 0x54, 0x46], array_values(array_slice(imagecolorsforindex($im, imagecolorat($im, 2, 2)), 0, 3)), 'background in site colour');

        $logo = tempnam(sys_get_temp_dir(), 'logo');
        $src = imagecreatetruecolor(40, 20);
        imagefill($src, 0, 0, imagecolorallocate($src, 200, 0, 0));
        imagepng($src, $logo);
        try {
            $im = imagecreatefromstring(app_icon_png('#1d5446', $logo));
            $this->assertSame([255, 255, 255], array_values(array_slice(imagecolorsforindex($im, imagecolorat($im, 2, 2)), 0, 3)), 'logo on white');
            $this->assertSame(200, imagecolorsforindex($im, imagecolorat($im, 90, 90))['red'], 'logo in the centre');
        } finally {
            unlink($logo);
        }
    }
}
