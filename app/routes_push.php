<?php
declare(strict_types=1);

// Upozornění do telefonu: service worker, přihlášení a odhlášení zařízení, zkušební upozornění.

// Service worker musí ležet v kořeni webu (i u webu ve složce), aby směl obsluhovat celý web.
$route('GET', '/sw.js', function () {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Service-Worker-Allowed: ' . url('/'));
    echo <<<'JS'
// Upozornění do telefonu (Web Push). Zpráva přichází zašifrovaná z webu přes push službu prohlížeče.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Nové upozornění', { body: d.body || '', icon: d.icon, badge: d.icon, data: { url: d.url || self.registration.scope }, tag: d.tag }));
});
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || self.registration.scope;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) { if (c.url === url && 'focus' in c) return c.focus(); }
    return self.clients.openWindow(url);
  }));
});
JS;
    exit;
});

$pushJson = function (array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

$route('POST', '/push/prihlasit', function () use ($pushJson) {
    $u = require_login();
    if (!plan_has('push')) {
        $pushJson(['ok' => false, 'error' => 'Upozornění do telefonu nejsou v edici tohoto webu.'], 402);
    }
    $sub = json_decode(post('subscription'), true);
    $endpoint = (string) ($sub['endpoint'] ?? '');
    $p256dh = (string) ($sub['keys']['p256dh'] ?? '');
    $auth = (string) ($sub['keys']['auth'] ?? '');
    if (!push_endpoint_ok($endpoint) || strlen(b64url_decode($p256dh)) !== 65 || strlen(b64url_decode($auth)) !== 16) {
        $pushJson(['ok' => false, 'error' => 'Prohlížeč poslal neplatné údaje pro upozornění.'], 422);
    }
    q('DELETE FROM push_subs WHERE endpoint = ?', [$endpoint]);
    q("INSERT INTO push_subs (user_id, endpoint, p256dh, auth, device, created_at, last_ok, fails) VALUES (?,?,?,?,?,?,'',0)",
        [$u['id'], $endpoint, $p256dh, $auth, mb_substr(push_device_name((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 120), date('Y-m-d H:i:s')]);
    $pushJson(['ok' => true, 'devices' => (int) val('SELECT COUNT(*) FROM push_subs WHERE user_id = ?', [$u['id']])]);
});
$route('POST', '/push/odhlasit', function () use ($pushJson) {
    $u = require_login();
    $endpoint = post('endpoint');
    $endpoint !== '' ? q('DELETE FROM push_subs WHERE user_id = ? AND endpoint = ?', [$u['id'], $endpoint]) : q('DELETE FROM push_subs WHERE user_id = ?', [$u['id']]);
    if (post('ajax') === '1') {
        $pushJson(['ok' => true]);
    }
    flash($endpoint !== '' ? 'Upozornění na tomto zařízení jsou vypnutá.' : 'Upozornění jsou vypnutá na všech zařízeních.');
    redirect('/muj-ucet#upozorneni');
});
$route('POST', '/push/zkouska', function () {
    $u = require_login();
    $n = push_notify([(int) $u['id']], site('name'), 'Zkušební upozornění – funguje to.', '/muj-ucet', true);
    flash($n ? "Zkušební upozornění odešlo na $n zařízení. Mělo by se za chvíli objevit na displeji." : 'Upozornění se nepodařilo doručit. Zkuste je na zařízení vypnout a znovu zapnout.', $n ? 'ok' : 'error');
    redirect('/muj-ucet#upozorneni');
});

// Ikona pro plochu iPhonu: barva webu, nebo logo (od edice Standard).
$route('GET', '/apple-touch-icon.png', function () {
    $logo = site_logo_used();
    $file = preg_match('/^[a-f0-9]{32}\.(png|jpe?g)$/', $logo) ? UPLOAD_DIR . '/' . $logo : '';
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    echo app_icon_png(LOOK_COLORS[site_look_used()] ?? LOOK_COLORS['les'], $file);
    exit;
});

// Uvítání po přijetí pozvánky: první věc po nastavení hesla je zapnout upozornění a zvolit, jak dávat vědět.
$route('GET', '/vitejte', function () {
    $u = require_login();
    render('welcome', ['title' => 'Vítejte', 'u' => $u]);
});
$route('POST', '/vitejte', function () {
    $u = require_login();
    q('UPDATE users SET email_notify = ?, push_notify = ? WHERE id = ?', [isset($_POST['email_notify']) ? 1 : 0, plan_has('push') ? (isset($_POST['push_notify']) ? 1 : 0) : (int) ($u['push_notify'] ?? 1), $u['id']]);
    flash('Vítejte na webu. Nastavení upozornění najdete kdykoli v části Můj účet.');
    redirect('/');
});

/** Srozumitelný název zařízení z identifikace prohlížeče (pro seznam v účtu). */
function push_device_name(string $ua): string
{
    $os = match (true) {
        str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Linux') => 'Linux', default => 'zařízení',
    };
    $br = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Firefox') => 'Firefox', str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Safari') => 'Safari', default => '',
    };
    return trim($os . ($br !== '' ? ' · ' . $br : ''));
}
