<?php
declare(strict_types=1);

// Přiřazení k jednotce přes odkaz: výbor rozešle (nebo vyvěsí) odkaz, obyvatel vyplní e-mail a jednotku
// a správce žádost schválí, až ověří, že e-mail opravdu patří vlastníkovi nebo nájemníkovi té jednotky.

const JOIN_STATUS = ['pending' => 'čeká na schválení', 'approved' => 'schváleno', 'rejected' => 'zamítnuto'];

/** Odkaz, přes který se obyvatelé hlásí k jednotce; '' když je vypnutý. */
function join_link(): string
{
    return setting('join_on') === '1' && setting('join_token') !== '' ? absolute_url('/pripojit/' . setting('join_token')) : '';
}
function join_pending_count(): int
{
    return (int) val("SELECT COUNT(*) FROM unit_requests WHERE status = 'pending'");
}
function join_setting(string $key, string $value): void
{
    q('DELETE FROM settings WHERE `key` = ?', [$key]);
    q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$key, $value]);
    setting('__reset');
}
/** Vyřízené žádosti se po 30 dnech mažou – po schválení už jsou údaje v účtu, zamítnuté nejsou potřeba. */
function join_cleanup(): void
{
    q("DELETE FROM unit_requests WHERE status <> 'pending' AND decided_at < ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]);
}

// ---------- Veřejný formulář ----------

$joinForm = function (string $token) {
    if (setting('join_on') !== '1' || setting('join_token') === '' || !hash_equals(setting('join_token'), $token)) {
        http_response_code(404);
        render('error', ['title' => 'Odkaz neplatí', 'message' => 'Tento odkaz pro přiřazení k jednotce už neplatí. Požádejte výbor o nový.']);
        return;
    }
    $units = all('SELECT * FROM units ORDER BY entrance, number');
    $in = ['name' => '', 'email' => '', 'phone' => '', 'kind' => 'owner', 'unit_id' => 0, 'note' => ''];
    $errors = [];
    $done = false;
    if ($GLOBALS['METHOD'] === 'POST') {
        $in = ['name' => mb_substr(post('name'), 0, 120), 'email' => mb_substr(post('email'), 0, 190), 'phone' => mb_substr(post('phone'), 0, 40),
            'kind' => post('kind') === 'tenant' ? 'tenant' : 'owner', 'unit_id' => (int) post('unit_id'), 'note' => mb_substr(post('note'), 0, 500)];
        q('DELETE FROM login_attempts WHERE at < ?', [time() - 3600]);
        if (post('website') !== '') {
            $done = true;   // robot vyplnil skryté pole – tváříme se, že je hotovo
        } elseif ((int) val("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = '#join'", [client_ip()]) >= 5) {
            $errors[] = 'Z tohoto připojení přišlo v poslední hodině příliš mnoho žádostí. Zkuste to prosím později.';
        } else {
            if ($in['name'] === '') {
                $errors[] = 'Vyplňte jméno a příjmení.';
            }
            if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Zadejte platný e-mail.';
            }
            if (!$in['unit_id'] || !val('SELECT 1 FROM units WHERE id = ?', [$in['unit_id']])) {
                $errors[] = 'Vyberte svou jednotku.';
            }
            if (!isset($_POST['agree'])) {
                $errors[] = 'Potvrďte prosím souhlas se zpracováním údajů.';
            }
        }
        if (!$errors && !$done) {
            q("INSERT INTO login_attempts (ip, at, email) VALUES (?,?,'#join')", [client_ip(), time()]);
            // Stejná žádost podruhé jen přepíše tu první; jestli e-mail už na webu účet má, se tu neprozrazuje.
            q("DELETE FROM unit_requests WHERE status = 'pending' AND email = ? AND unit_id = ?", [$in['email'], $in['unit_id']]);
            q("INSERT INTO unit_requests (name, email, phone, kind, unit_id, note, status, created_at, ip) VALUES (?,?,?,?,?,?,'pending',?,?)",
                [$in['name'], $in['email'], $in['phone'], $in['kind'], $in['unit_id'], $in['note'], date('Y-m-d H:i:s'), client_ip()]);
            $unit = unit_name(one('SELECT * FROM units WHERE id = ?', [$in['unit_id']]));
            audit('join_requested', $in['email'] . ' · ' . $unit . ' · ' . KINDS[$in['kind']]);
            send_mail($in['email'], 'Žádost o přiřazení k jednotce – ' . site('name'), "Dobrý den,\n\nna webu " . site('name') . " jsme přijali Vaši žádost o přiřazení k jednotce $unit.\n"
                . "Výbor teď ověří, že jste vlastníkem nebo nájemníkem této jednotky. Po schválení Vám přijde e-mail s odkazem, přes který si nastavíte heslo.\n\nPokud jste o nic nežádali, zprávu ignorujte.\n");
            $adminText = "Dobrý den,\n\n" . $in['name'] . ' (' . $in['email'] . ") žádá o přiřazení k jednotce $unit jako " . mb_strtolower(KINDS[$in['kind']]) . ".\n"
                . "Než žádost schválíte, ověřte prosím, že e-mail opravdu patří této osobě a že k jednotce patří.\n\n" . absolute_url('/admin/uzivatele/zadosti') . "\n";
            foreach (all("SELECT email FROM users WHERE role = 'admin' AND active = 1") as $a) {
                send_mail($a['email'], 'Nová žádost o přiřazení k jednotce – ' . site('name'), $adminText);
            }
            push_notify(push_admin_ids(), site('name'), 'Nová žádost o přiřazení k jednotce ' . $unit . ': ' . $in['name'], '/admin/uzivatele/zadosti');
            $done = true;
        }
        if ($errors) {
            http_response_code(422);
        }
    }
    render('join', ['title' => 'Přiřazení k jednotce', 'units' => $units, 'in' => $in, 'errors' => $errors, 'done' => $done, 'token' => $token]);
};
$route('GET', '/pripojit/([A-Za-z0-9]{16,64})', $joinForm);
$route('POST', '/pripojit/([A-Za-z0-9]{16,64})', $joinForm);

// ---------- Správce: odkaz a schvalování ----------

$route('GET', '/admin/uzivatele/zadosti', function () {
    require_admin();
    join_cleanup();
    $rows = all('SELECT r.*, un.entrance, un.number, un.owner_note, d.name decided_name FROM unit_requests r LEFT JOIN units un ON un.id = r.unit_id LEFT JOIN users d ON d.id = r.decided_by ORDER BY r.status = \'pending\' DESC, r.created_at DESC');
    foreach ($rows as &$r) {
        // Pomůcky k ověření: kdo už je k jednotce přiřazen a jestli e-mail na webu účet má.
        $r['current'] = all("SELECT u.name, u.email, u.kind FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE uu.unit_id = ? AND u.active = 1 ORDER BY u.kind, u.name", [$r['unit_id']]);
        $r['account'] = one('SELECT id, name, active, role FROM users WHERE email = ?', [$r['email']]);
    }
    unset($r);
    render('admin/join_requests', ['title' => 'Žádosti o přiřazení k jednotce', 'rows' => $rows, 'link' => join_link(),
        'qr' => join_link() !== '' ? qr_svg(join_link(), 'QR kód odkazu pro přiřazení k jednotce') : '']);
});

$route('POST', '/admin/uzivatele/zadosti/odkaz', function () {
    require_admin();
    $do = post('do');
    if ($do === 'on' || $do === 'new') {
        if ($do === 'new' || setting('join_token') === '') {
            join_setting('join_token', bin2hex(random_bytes(12)));
        }
        join_setting('join_on', '1');
        audit('join_link', $do === 'new' ? 'vytvořen nový odkaz, starý přestal platit' : 'odkaz zapnut');
        flash($do === 'new' ? 'Vytvořili jsme nový odkaz. Ten dosavadní už nefunguje.' : 'Odkaz je zapnutý. Pošlete ho obyvatelům nebo vyvěste QR kód.');
    } elseif ($do === 'off') {
        join_setting('join_on', '0');
        audit('join_link', 'odkaz vypnut');
        flash('Odkaz je vypnutý; nové žádosti přes něj nepřijdou.', 'info');
    }
    redirect('/admin/uzivatele/zadosti');
});

$route('POST', '/admin/uzivatele/zadosti/(\d+)', function (int $id) {
    $me = require_admin();
    $r = one("SELECT * FROM unit_requests WHERE id = ? AND status = 'pending'", [$id]);
    $unit = $r ? one('SELECT * FROM units WHERE id = ?', [$r['unit_id']]) : null;
    if (!$r || !$unit) {
        flash('Žádost už je vyřízená nebo jednotka mezitím zanikla.', 'error');
        redirect('/admin/uzivatele/zadosti');
    }
    $unitName = unit_name($unit);
    if (post('do') === 'reject') {
        $reason = mb_substr(trim(post('reason')), 0, 300);
        q("UPDATE unit_requests SET status = 'rejected', decided_at = ?, decided_by = ?, decision_note = ? WHERE id = ?", [date('Y-m-d H:i:s'), $me['id'], $reason, $id]);
        audit('join_rejected', $r['email'] . ' · ' . $unitName . ($reason !== '' ? ' · ' . $reason : ''));
        send_mail($r['email'], 'Žádost o přiřazení k jednotce – ' . site('name'), "Dobrý den,\n\nvýbor " . site('name') . " Vaši žádost o přiřazení k jednotce $unitName neschválil."
            . ($reason !== '' ? "\n\nDůvod: $reason" : '') . "\n\nPokud jde o omyl, obraťte se prosím přímo na výbor.\n");
        flash('Žádost je zamítnutá a žadatel o tom dostal e-mail.', 'info');
        redirect('/admin/uzivatele/zadosti');
    }
    if ($r['kind'] === 'tenant' && ($tenantError = tenant_units_error([(int) $unit['id']]))) {
        flash($tenantError . ' Žádost zatím nechte čekat.', 'error');
        redirect('/admin/uzivatele/zadosti#z' . $id);
    }
    if (!isset($_POST['verified'])) {
        flash('Před schválením potvrďte, že jste ověřili, že e-mail patří uvedené osobě a ta k jednotce patří.', 'error');
        redirect('/admin/uzivatele/zadosti#z' . $id);
    }
    $existing = one('SELECT * FROM users WHERE email = ?', [$r['email']]);
    if ($existing) {
        if (!(int) $existing['active']) {
            flash('Účet s e-mailem ' . $r['email'] . ' je vypnutý. Zapněte ho nejdřív v Uživatelích.', 'error');
            redirect('/admin/uzivatele/zadosti#z' . $id);
        }
        q('DELETE FROM user_units WHERE user_id = ? AND unit_id = ?', [$existing['id'], $unit['id']]);
        q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$existing['id'], $unit['id']]);
        $userId = (int) $existing['id'];
        send_mail($r['email'], 'Přiřazení k jednotce schváleno – ' . site('name'), "Dobrý den,\n\nvýbor " . site('name') . " schválil Vaše přiřazení k jednotce $unitName.\n"
            . "Přihlaste se jako obvykle: " . absolute_url('/prihlaseni') . "\n");
        $msg = 'Jednotka ' . $unitName . ' je přidaná k existujícímu účtu ' . $r['email'] . '.';
    } else {
        if ($limitError = plan_limit_error('users')) {
            flash($limitError, 'error');
            redirect('/admin/uzivatele/zadosti#z' . $id);
        }
        q("INSERT INTO users (name, email, role, kind, phone, password_hash) VALUES (?,?,'resident',?,?,?)", [$r['name'], $r['email'], $r['kind'], $r['phone'], NO_PASSWORD]);
        $userId = (int) db()->lastInsertId();
        q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$userId, $unit['id']]);
        [$sent, $link] = send_account_invite(one('SELECT * FROM users WHERE id = ?', [$userId]));
        if (!$sent) {
            $_SESSION['admin_invite_link'] = ['name' => $r['name'], 'link' => $link];
        }
        $msg = 'Účet pro ' . $r['email'] . ' je založený a přiřazený k jednotce ' . $unitName . '.' . ($sent ? ' Přišel mu e-mail s odkazem na nastavení hesla.' : '');
    }
    q("UPDATE unit_requests SET status = 'approved', decided_at = ?, decided_by = ?, user_id = ? WHERE id = ?", [date('Y-m-d H:i:s'), $me['id'], $userId, $id]);
    audit('join_approved', $r['email'] . ' · ' . $unitName . ' · ' . KINDS[$r['kind']]);
    flash($msg);
    redirect(!empty($_SESSION['admin_invite_link']) ? '/admin/uzivatele' : '/admin/uzivatele/zadosti');
});
