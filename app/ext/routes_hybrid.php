<?php
// Modul H: shromáždění online a hybridně – adresy webu společenství. Logika je v app/ext/hybrid_lib.php.

/** Odpověď JSON pro průběžnou aktualizaci (polling, bez websocketů). */
function hybrid_json(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Správce: nastavení domu ----------

$hybridSettings = function () {
    require_admin();
    if (!hybrid_available()) {
        not_found();
    }
    if ($GLOBALS['METHOD'] === 'POST') {
        hybrid_setting_save('hybrid_on', isset($_POST['hybrid_on']) ? '1' : '0');
        hybrid_setting_save('hybrid_email_code', isset($_POST['hybrid_email_code']) ? '1' : '0');
        audit('Shromáždění online', (setting('hybrid_on') === '1' ? 'povoleno' : 'vypnuto') . (setting('hybrid_email_code') === '1' ? ', ověření kódem z e-mailu' : ''));
        flash(setting('hybrid_on') === '1' ? 'Uloženo. U termínu shromáždění teď můžete zvolit hybridní nebo online formu.' : 'Uloženo. Shromáždění online je vypnuté.');
        redirect('/admin/shromazdeni-online');
    }
    hybrid_ready();
    render('ext/hybrid/settings', ['title' => 'Shromáždění online', 'on' => setting('hybrid_on') === '1', 'code' => hybrid_code_required(),
        'events' => all("SELECT e.id, e.title, e.event_date, COALESCE(h.form, 'prezencni') AS form FROM events e LEFT JOIN hybrid_events h ON h.event_id = e.id WHERE e.event_date >= ? ORDER BY e.event_date LIMIT 5", [date('Y-m-d')])]);
};
$route('GET', '/admin/shromazdeni-online', $hybridSettings);
$route('POST', '/admin/shromazdeni-online', $hybridSettings);

// ---------- Správce: řízení hlasování online a průběžný stav ----------

$route('POST', '/admin/terminy/(\d+)/online', function (int $id) {
    $admin = require_admin();
    one('SELECT id FROM events WHERE id = ?', [$id]) ?? not_found();
    if (!hybrid_remote($id)) {
        not_found();
    }
    $motionId = (int) post('motion_id');
    $on = post('do') === 'live-on';
    if (hybrid_live_set($id, $motionId, $on, (int) $admin['id'])) {
        audit('presence', ($on ? 'Hlasování online spuštěno: ' : 'Hlasování online zastaveno: ') . (string) val('SELECT title FROM meeting_motions WHERE id = ?', [$motionId]));
        flash($on ? 'Návrh je puštěný k hlasování online. Připojení vlastníci ho uvidí do několika sekund.' : 'Hlasování online o návrhu je zastavené.');
    }
    redirect('/admin/terminy/' . $id . '/prezence-na-miste#m' . $motionId);
});
$route('GET', '/admin/terminy/(\d+)/online/stav', function (int $id) {
    require_admin();
    one('SELECT id FROM events WHERE id = ?', [$id]) ?? not_found();
    if (!hybrid_remote($id)) {
        not_found();
    }
    hybrid_json(hybrid_admin_state($id));
});

// ---------- Vlastník: připojení, hlasování, odchod ----------

$route('GET', '/terminy/(\d+)/online', function (int $id) {
    $u = require_login();
    $ev = one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found();
    $h = hybrid_remote($id) ?? not_found();
    $online = hybrid_is_online($id, $u);
    $myUnits = array_map(fn($un) => (int) $un['id'], hybrid_user_units($u));
    $since = $online && $myUnits ? (string) val("SELECT MIN(joined_at) FROM hybrid_attendance WHERE event_id = ? AND left_at = '' AND unit_id IN (" . implode(',', $myUnits) . ')', [$id]) : '';
    render('ext/hybrid/owner', ['title' => 'Shromáždění online', 'ev' => $ev, 'h' => $h, 'online' => $online, 'error' => $online ? null : hybrid_join_error($id, $u),
        'units' => hybrid_user_units($u), 'modes' => hybrid_user_modes($id, $u), 'since' => $since, 'motions' => $online ? hybrid_live_motions($id) : [],
        'myVotes' => hybrid_my_votes($id, $u), 'codeStep' => hybrid_code_pending($id, $u), 'sig' => $online ? hybrid_owner_signature($id, $u) : '']);
});
$route('POST', '/terminy/(\d+)/online', function (int $id) {
    $u = require_login();
    one('SELECT id FROM events WHERE id = ?', [$id]) ?? not_found();
    hybrid_remote($id) ?? not_found();
    $back = '/terminy/' . $id . '/online';
    $do = post('do');
    if ($do === 'join' || $do === 'code') {
        if ($err = hybrid_join_error($id, $u)) {
            flash($err, 'error');
            redirect($back);
        }
        $verified = 'login';
        if (hybrid_code_required()) {
            if ($do === 'join') {
                hybrid_code_send($id, $u);
                flash('Poslali jsme Vám kód na e-mail ' . $u['email'] . '.', 'info');
                redirect($back);
            }
            if (!hybrid_code_check($id, $u, post('code'))) {
                flash(hybrid_code_pending($id, $u) ? 'Kód nesouhlasí. Zkontrolujte ho a zkuste to znovu.' : 'Kód vypršel nebo byl zadán příliš mnohokrát špatně. Nechte si poslat nový.', 'error');
                redirect($back);
            }
            $verified = 'email';
        }
        $units = hybrid_join($id, $u, $verified);
        audit('Připojení ke shromáždění online', 'jednotek ' . count($units), (int) $u['id']);
        flash('Jste přítomen(a) online. Videohovor se otevřel v nové kartě – pokud ne, použijte tlačítko „Otevřít videohovor“.');
        redirect($back);
    }
    if ($do === 'leave') {
        $n = hybrid_leave($id, $u);
        audit('Odchod ze shromáždění online', 'jednotek ' . $n, (int) $u['id']);
        flash('Odešel(a) jste ze shromáždění. Děkujeme za účast.');
        redirect('/terminy/' . $id);
    }
    if ($do === 'vote') {
        $err = hybrid_vote($id, (int) post('motion_id'), $u, post('choice'));
        $err ? flash($err, 'error') : flash('Hlas je zaznamenaný: ' . mb_strtolower(MOTION_CHOICES[post('choice')]) . '.');
        redirect($back . '#m' . (int) post('motion_id'));
    }
    redirect($back);
});
$route('GET', '/terminy/(\d+)/online/stav', function (int $id) {
    $u = require_login();
    if (!hybrid_remote($id)) {
        not_found();
    }
    hybrid_json(['sig' => hybrid_owner_signature($id, $u)]);
});
