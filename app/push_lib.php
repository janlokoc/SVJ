<?php
declare(strict_types=1);

/*
 * Upozornění do telefonu (Web Push) bez knihoven a bez placené služby: zprávu zašifruje server webu
 * (RFC 8291, aes128gcm), podepíše klíčem VAPID (RFC 8292) a předá push službě prohlížeče
 * (Google, Mozilla, Apple, Microsoft), která ji doručí do telefonu. Obsah push služba nevidí.
 * Na iPhonu fungují upozornění jen u webu přidaného na plochu (iOS 16.4 a novější).
 */

/** Klíč VAPID celé instalace (jeden pro všechny weby); vytvoří se při první potřebě. */
function push_vapid(): array
{
    static $k = null;
    if ($k !== null) {
        return $k;
    }
    $file = ROOT_STORAGE . '/vapid.pem';
    if (!is_file($file)) {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        file_put_contents($file, $pem, LOCK_EX);
        @chmod($file, 0600);
    }
    $priv = openssl_pkey_get_private((string) file_get_contents($file));
    $d = openssl_pkey_get_details($priv)['ec'];
    $k = ['key' => $priv, 'public' => "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)];
    return $k;
}
function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64url_decode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}
/** Veřejný klíč VAPID pro prohlížeč (applicationServerKey). */
function push_public_key(): string
{
    return b64url(push_vapid()['public']);
}
/** Je adresa push služby povolená? Jen známé push služby přes HTTPS – server webu tak nejde zneužít k dotazům jinam. */
function push_endpoint_ok(string $url): bool
{
    $p = parse_url($url);
    $host = strtolower((string) ($p['host'] ?? ''));
    if (getenv('SVJ_PUSH_TEST') === '1' && ($p['scheme'] ?? '') === 'http' && $host === '127.0.0.1') {
        return true;   // jen ověřovací běh návodů (tools/napoveda)
    }
    if (($p['scheme'] ?? '') !== 'https' || $host === '') {
        return false;
    }
    foreach (['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'] as $ok) {
        if ($host === $ok || str_ends_with($host, '.' . $ok)) {
            return true;
        }
    }
    return false;
}
/** Veřejný klíč z prohlížeče (65 bajtů) jako klíč OpenSSL. */
function push_point_key(string $point)
{
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point), 64, "\n") . "-----END PUBLIC KEY-----\n");
}
/** Zašifruje zprávu pro jedno zařízení (RFC 8291, aes128gcm). */
function push_encrypt(string $payload, string $p256dh, string $auth): ?string
{
    $ua = b64url_decode($p256dh);
    $secret = b64url_decode($auth);
    if (strlen($ua) !== 65 || strlen($secret) !== 16 || !($uaKey = push_point_key($ua))) {
        return null;
    }
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ed = openssl_pkey_get_details($eph)['ec'];
    $as = "\x04" . str_pad($ed['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ed['y'], 32, "\0", STR_PAD_LEFT);
    $shared = openssl_pkey_derive($uaKey, $eph, 32);
    if ($shared === false) {
        return null;
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua . $as, $secret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $as . $cipher . $tag;
}
/** Podpis VAPID (JWT ES256) pro danou push službu. */
function push_vapid_header(string $endpoint): string
{
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $contact = function_exists('pset') && filter_var(pset('contact_email'), FILTER_VALIDATE_EMAIL) ? 'mailto:' . pset('contact_email') : 'mailto:podpora@example.cz';
    $data = b64url('{"typ":"JWT","alg":"ES256"}') . '.' . b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $contact], JSON_UNESCAPED_SLASHES));
    openssl_sign($data, $der, push_vapid()['key'], OPENSSL_ALGO_SHA256);
    // DER (SEQUENCE r, s) → 64 bajtů r‖s
    $off = 3;
    $rl = ord($der[$off]);
    $r = substr($der, $off + 1, $rl);
    $off += 1 + $rl + 1;
    $sl = ord($der[$off]);
    $s = substr($der, $off + 1, $sl);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return 'vapid t=' . $data . '.' . b64url($raw) . ', k=' . push_public_key();
}
/** Pošle zprávu na jedno zařízení. Vrací HTTP stav push služby (0 = nepodařilo se spojit). */
function push_deliver(array $sub, array $msg): int
{
    if (!push_endpoint_ok($sub['endpoint']) || !($body = push_encrypt(json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sub['p256dh'], $sub['auth']))) {
        return 0;
    }
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: normal', 'Authorization: ' . push_vapid_header($sub['endpoint'])]]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}
/**
 * Upozorní uživatele na všech jejich zařízeních. Zařízení, která push služba už nezná (404, 410), se smažou.
 * Vrací počet doručených zpráv. Bez funkce „push“ v edici webu nic nedělá.
 */
function push_notify(array $userIds, string $title, string $body, string $path = '/'): int
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds || !function_exists('plan_has') || !plan_has('push')) {
        return 0;
    }
    $msg = ['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 200), 'url' => absolute_url($path), 'icon' => asset('icon-192.png')];
    $ok = 0;
    foreach (all('SELECT * FROM push_subs WHERE user_id IN (' . implode(',', $userIds) . ')') as $sub) {
        $code = push_deliver($sub, $msg);
        if ($code >= 200 && $code < 300) {
            $ok++;
            q("UPDATE push_subs SET last_ok = datetime('now','localtime'), fails = 0 WHERE id = ?", [$sub['id']]);
        } elseif (in_array($code, [404, 410], true) || (int) $sub['fails'] >= 9) {
            q('DELETE FROM push_subs WHERE id = ?', [$sub['id']]);
        } else {
            q('UPDATE push_subs SET fails = fails + 1 WHERE id = ?', [$sub['id']]);
        }
    }
    return $ok;
}
/** Všichni aktivní uživatelé s alespoň jedním zařízením pro upozornění. */
function push_all_user_ids(): array
{
    return array_map('intval', array_column(all('SELECT DISTINCT s.user_id FROM push_subs s JOIN users u ON u.id = s.user_id WHERE u.active = 1'), 'user_id'));
}

/**
 * Ikona webu na ploše telefonu (180 × 180 px, iPhone ji bere z apple-touch-icon): znak domečků v barvě webu,
 * nebo – od edice Standard – logo společenství na bílém podkladu.
 */
function app_icon_png(string $hex, string $logoFile = ''): string
{
    $s = 180;
    $im = imagecreatetruecolor($s, $s);
    $rgb = fn(string $h) => sscanf(ltrim($h, '#'), '%02x%02x%02x');
    if ($logoFile !== '' && is_file($logoFile) && ($logo = @imagecreatefromstring((string) file_get_contents($logoFile)))) {
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $w = imagesx($logo);
        $h = imagesy($logo);
        $k = min(136 / $w, 136 / $h);
        imagecopyresampled($im, $logo, (int) (($s - $w * $k) / 2), (int) (($s - $h * $k) / 2), 0, 0, (int) ($w * $k), (int) ($h * $k), $w, $h);
    } else {
        [$r, $g, $b] = $rgb($hex);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        $white = imagecolorallocate($im, 255, 255, 255);
        $warm = imagecolorallocate($im, 242, 155, 127);
        // čtyři „domy“ jako ve znaku webu (viewBox 44 × 32), vycentrované
        foreach ([[1, 9, 22, $white], [12, 3, 28, $warm], [23, 9, 22, $white], [34, 3, 28, $white]] as [$x, $y, $hgt, $c]) {
            $x0 = 24 + $x * 3;
            $y0 = 42 + $y * 3;
            imagefilledrectangle($im, (int) $x0, (int) $y0, (int) ($x0 + 9 * 3 - 1), (int) ($y0 + $hgt * 3 - 1), $c);
        }
    }
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
}
