<?php
// Inzeráty vlastníků (prodej / pronájem bytu). Logika je v app/ext/listing_lib.php.

// ---------- Vlastník: moje inzeráty, formulář, akce ----------

$listingOwnerGuard = function (): array {
    $u = require_login();
    if (!listing_enabled()) {
        flash('Výbor inzeráty na webu domu teď nepovolil.', 'info');
        redirect('/muj-ucet');
    }
    return $u;
};

$route('GET', '/inzeraty/moje', function () use ($listingOwnerGuard) {
    $u = $listingOwnerGuard();
    listing_ready();
    $units = listing_owner_units($u);
    $rows = [];
    foreach ($units as $un) {
        $rows[] = ['unit' => $un, 'listings' => all('SELECT * FROM listings WHERE unit_id = ? ORDER BY id DESC', [(int) $un['id']])];
    }
    render('ext/listing/mine', ['title' => 'Moje inzeráty', 'rows' => $rows, 'u' => $u]);
});

/** Formulář inzerátu: nový pro jednotku ($unitId), nebo úprava ($id). */
$listingForm = function (int $unitId, int $id) use ($listingOwnerGuard) {
    $u = $listingOwnerGuard();
    listing_ready();
    $l = $id ? (listing_get($id) ?? not_found()) : null;
    $unitId = $l ? (int) $l['unit_id'] : $unitId;
    $unit = one('SELECT * FROM units WHERE id = ?', [$unitId]);
    if (!$unit || !listing_owns_unit($u, $unitId)) {
        http_response_code(403);
        render('error', ['title' => 'Přístup odepřen', 'message' => 'Inzerát může podat jen vlastník jednotky, jehož účet je k ní ve webu přiřazený.']);
        exit;
    }
    if (!$l && ($active = listing_active_for_unit($unitId))) {
        flash('Jednotka už inzerát má – můžete ho upravit.', 'info');
        redirect('/inzeraty/' . $active['id'] . '/upravit');
    }
    $form = $l ?? listing_defaults($unit, $u);
    $errors = $warnings = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        [$d, $errors] = listing_input($_POST, $unit);
        $form = array_merge($form, $d, ['area' => $d['area'] ?? post('area'), 'outdoor_area' => $d['outdoor_area'] ?? post('outdoor_area'),
            'features_post' => is_array($_POST['features'] ?? null) ? $_POST['features'] : []]);
        if (!$errors) {
            $id = listing_save($u, $unitId, $d, $id);
            foreach (array_map('intval', (array) ($_POST['photo_delete'] ?? [])) as $pid) {
                if ($p = one('SELECT * FROM listing_photos WHERE id = ? AND listing_id = ?', [$pid, $id])) {
                    listing_photo_unlink($p);
                    q('DELETE FROM listing_photos WHERE id = ?', [$pid]);
                }
            }
            foreach (uploaded_list('photos') as $f) {
                $err = $f['error'] !== UPLOAD_ERR_OK ? 'Fotku se nepodařilo nahrát (možná je příliš velká).' : (is_uploaded_file($f['tmp_name']) ? listing_photo_add($id, $f['tmp_name']) : 'Fotku se nepodařilo nahrát.');
                if ($err !== null) {
                    $warnings[] = '„' . basename((string) $f['name']) . '“: ' . $err;
                }
            }
            listing_photo_captions($id, (array) ($_POST['photo_caption'] ?? []));
            if ((int) post('cover')) {
                listing_photo_cover($id, (int) post('cover'));
            }
            $l = listing_get($id);
            if (post('action') === 'publish' && in_array($l['status'], ['draft', 'expired'], true)) {
                if ($err = listing_publish($id)) {
                    flash($err, 'error');   // inzerát zůstal uložený jako koncept (stránka to ukazuje)
                    redirect('/inzeraty/' . $id . '/upravit');
                }
                flash('Inzerát je zveřejněný. Teď ho můžete sdílet.' . ($warnings ? ' ' . implode(' ', $warnings) : ''));
                redirect(listing_path(listing_get($id)) . '#sdilet');
            }
            flash($warnings ? 'Inzerát je uložený, ale některé fotky se nenahrály: ' . implode(' ', $warnings) : 'Inzerát je uložený.', $warnings ? 'error' : 'ok');
            redirect('/inzeraty/' . $id . '/upravit');
        }
        http_response_code(422);
    }
    render('ext/listing/form', ['title' => $id ? 'Upravit inzerát' : 'Nový inzerát', 'l' => $form, 'unit' => $unit, 'errors' => $errors,
        'photos' => $id ? listing_photos($id) : [], 'penb' => listing_penb()]);
};
$route('GET', '/inzeraty/novy/(\d+)', fn(int $unitId) => $listingForm($unitId, 0));
$route('POST', '/inzeraty/novy/(\d+)', fn(int $unitId) => $listingForm($unitId, 0));
$route('GET', '/inzeraty/(\d+)/upravit', fn(int $id) => $listingForm(0, $id));
$route('POST', '/inzeraty/(\d+)/upravit', fn(int $id) => $listingForm(0, $id));

$route('POST', '/inzeraty/(\d+)/(zverejnit|prodano|prodlouzit|smazat)', function (int $id, string $action) use ($listingOwnerGuard) {
    $u = $listingOwnerGuard();
    $l = listing_get($id) ?? not_found();
    if (!listing_can_manage($l, $u)) {
        not_found();
    }
    switch ($action) {
        case 'zverejnit':
            if ($err = listing_publish($id)) {
                flash($err, 'error');
                redirect('/inzeraty/moje');
            }
            flash('Inzerát je zveřejněný. Teď ho můžete sdílet.');
            redirect(listing_path(listing_get($id)) . '#sdilet');
        case 'prodano':
            listing_mark_sold($id);
            flash(($l['type'] === 'pronajem' ? 'Inzerát je označený jako pronajatý.' : 'Inzerát je označený jako prodaný.') . ' Ještě ' . LISTING_SOLD_DAYS . ' dní bude vidět se štítkem, potom zmizí.');
            break;
        case 'prodlouzit':
            $ok = listing_extend($id);
            flash($ok ? 'Inzerát je prodloužený do ' . cz_date(listing_get($id)['expires_on']) . '.' : 'Inzerát nejde prodloužit.', $ok ? 'ok' : 'error');
            break;
        case 'smazat':
            listing_delete($id);
            flash('Inzerát je smazaný i s fotkami.');
            break;
    }
    redirect('/inzeraty/moje');
});

// ---------- Vlastník: zájemci o inzerát (obsah vidí jen vlastník jednotky, ne výbor ani správci) ----------

/** Inzerát, který přihlášený uživatel spravuje; jinak 404 (cizí inzerát se ani neprozradí). */
$listingManaged = function (int $id): array {
    $u = require_login();
    $l = listing_get($id);
    if (!$l || !listing_can_manage($l, $u)) {
        not_found();
    }
    return [$u, $l];
};
$route('GET', '/inzeraty/(\d+)/zajemci', function (int $id) use ($listingManaged) {
    [$u, $l] = $listingManaged($id);
    render('ext/listing/inquiries', ['title' => 'Zájemci o inzerát', 'l' => $l, 'rows' => listing_inquiries_for($l, $u) ?? [], 'archive' => ($_GET['archiv'] ?? '') === '1']);
});
$listingInquiry = function (int $id, int $inqId, ?string $error = null, string $draft = '') use ($listingManaged) {
    [$u, $l] = $listingManaged($id);
    $inq = listing_inquiry_for($l, $inqId, $u) ?? not_found();
    if ($inq['status'] === 'new') {
        listing_inquiry_set_status($inqId, 'read');
        $inq['status'] = 'read';
    }
    render('ext/listing/inquiry', ['title' => 'Zpráva zájemce', 'l' => $l, 'inq' => $inq, 'msgs' => listing_inquiry_messages($inqId), 'error' => $error, 'draft' => $draft]);
};
$route('GET', '/inzeraty/(\d+)/zajemci/(\d+)', fn(int $id, int $inqId) => $listingInquiry($id, $inqId));
$route('POST', '/inzeraty/(\d+)/zajemci/(\d+)', function (int $id, int $inqId) use ($listingManaged, $listingInquiry) {
    [$u, $l] = $listingManaged($id);
    $inq = listing_inquiry_for($l, $inqId, $u) ?? not_found();
    if ($err = listing_inquiry_reply($l, $inq, (string) ($_POST['body'] ?? ''))) {
        http_response_code(422);
        $listingInquiry($id, $inqId, $err, is_string($_POST['body'] ?? null) ? $_POST['body'] : '');
        return;
    }
    flash('Odpověď jsme zájemci poslali e-mailem. Vaši adresu neuvidí.');
    redirect(listing_inquiry_path($l, $inq));
});
$route('POST', '/inzeraty/(\d+)/zajemci/(\d+)/(archivovat|obnovit)', function (int $id, int $inqId, string $action) use ($listingManaged) {
    [$u, $l] = $listingManaged($id);
    $inq = listing_inquiry_for($l, $inqId, $u) ?? not_found();
    listing_inquiry_set_status((int) $inq['id'], $action === 'archivovat' ? 'archived' : 'read');
    flash($action === 'archivovat' ? 'Zpráva je v archivu.' : 'Zpráva je zpět mezi zájemci.');
    redirect('/inzeraty/' . $id . '/zajemci');
});

// Prodloužení odkazem z e-mailu (bez přihlášení, podepsaný odkaz platí jen pro aktuální datum konce).
$listingExtend = function (int $id, string $token) {
    $l = listing_get($id);
    if (!$l || !hash_equals(listing_extend_token($l), $token) || !in_array($l['status'], ['published', 'expired'], true)) {
        http_response_code(404);
        render('error', ['title' => 'Odkaz neplatí', 'message' => 'Tento odkaz už neplatí – inzerát byl možná mezitím prodloužen, prodán nebo smazán. Své inzeráty najdete po přihlášení v části Můj účet.']);
        return;
    }
    if ($GLOBALS['METHOD'] === 'POST') {
        listing_extend($id);
        flash('Inzerát je prodloužený do ' . cz_date(listing_get($id)['expires_on']) . '.');
        redirect(listing_path($l));
    }
    render('ext/listing/extend', ['title' => 'Prodloužit inzerát', 'l' => $l]);
};
$route('GET', '/inzerat/prodlouzit/(\d+)/([a-f0-9]{64})', $listingExtend);
$route('POST', '/inzerat/prodlouzit/(\d+)/([a-f0-9]{64})', $listingExtend);

// ---------- Veřejná stránka inzerátu ----------

$listingPage = function (int $id, array $errors = [], array $in = []) {
    $l = listing_get($id);
    $u = user();
    if (!$l || !listing_can_view($l, $u)) {
        not_found();
    }
    $photos = listing_photos($id);
    $vis = listing_visibility($l);
    if ($vis === 'public') {
        $GLOBALS['PAGE_META'] = listing_meta($l, $photos);
    } else {
        $GLOBALS['PAGE_META'] = ['robots' => 'noindex'];
    }
    render('ext/listing/page', ['title' => listing_title($l), 'l' => $l, 'photos' => $photos, 'vis' => $vis, 'errors' => $errors, 'in' => $in + ['name' => '', 'contact' => '', 'message' => ''],
        'canManage' => listing_can_manage($l, $u), 'token' => listing_form_token()]);
};
$route('GET', '/inzerat/(\d+)(?:-([a-z0-9-]*))?', function (int $id, string $slug = '') use ($listingPage) {
    $l = listing_get($id);
    if ($l && listing_path($l) !== '/inzerat/' . $id . '-' . $slug && listing_can_view($l, user())) {
        header('Location: ' . url(listing_path($l)), true, 301);   // stará nebo zkrácená adresa → stálá adresa inzerátu
        exit;
    }
    $listingPage($id);
});
$route('POST', '/inzerat/(\d+)/kontakt', function (int $id) use ($listingPage) {
    $l = listing_get($id) ?? not_found();
    if (!listing_can_view($l, user())) {
        not_found();
    }
    $r = listing_contact($l, $_POST, client_ip(), (bool) user());
    if (!empty($r['ok'])) {
        flash('Děkujeme, zprávu jsme předali vlastníkovi bytu. Ozve se Vám sám.');
        redirect(listing_path($l) . '#kontakt');
    }
    http_response_code(422);
    $listingPage($id, $r['errors'], $r['in']);
});

$listingReport = function (int $id) {
    $l = listing_get($id);
    if (!$l || !listing_can_view($l, user())) {
        not_found();
    }
    $error = null;
    if ($GLOBALS['METHOD'] === 'POST') {
        if (post('website') !== '') {
            redirect(listing_path($l));   // robot
        }
        $error = !listing_form_token_ok(post('t')) ? 'Formulář vypršel nebo byl odeslán příliš rychle. Zkuste to prosím znovu.' : listing_report($l, post('reason'), client_ip());
        if ($error === null) {
            flash('Děkujeme, výbor společenství hlášení dostal a inzerát posoudí.');
            redirect(listing_path($l));
        }
        http_response_code(422);
    }
    $GLOBALS['PAGE_META'] = ['robots' => 'noindex'];
    render('ext/listing/report', ['title' => 'Nahlásit inzerát', 'l' => $l, 'error' => $error, 'reason' => post('reason'), 'token' => listing_form_token()]);
};
$route('GET', '/inzerat/(\d+)/nahlasit', $listingReport);
$route('POST', '/inzerat/(\d+)/nahlasit', $listingReport);

// Fotka inzerátu: jen u inzerátu, který návštěvník smí vidět; název souboru je z databáze a ověřený (žádné ../).
$route('GET', '/inzerat/foto/(\d+)(-t)?\.jpg', function (int $photoId, string $thumb = '') {
    listing_ready();
    $p = one('SELECT * FROM listing_photos WHERE id = ?', [$photoId]);
    $l = $p ? listing_get((int) $p['listing_id']) : null;
    if (!$p || !$l || !listing_can_view($l, user()) || !($path = listing_photo_file($p, $thumb !== ''))) {
        not_found();
    }
    header_remove('Content-Security-Policy');
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: ' . (listing_visibility($l) === 'public' ? 'public, max-age=3600' : 'private, no-store'));
    readfile($path);
    exit;
});

// ---------- Část pro obyvatele: inzeráty v domě ----------

$route('GET', '/inzeraty', function () {
    $u = require_login();
    if (!listing_enabled()) {
        not_found();
    }
    listing_ready();
    $rows = array_values(array_filter(all("SELECT * FROM listings WHERE hidden = 0 AND status IN ('published','sold') ORDER BY published_at DESC"), fn($l) => listing_visibility($l) !== 'none'));
    $covers = [];
    foreach ($rows as $l) {
        $covers[$l['id']] = one('SELECT * FROM listing_photos WHERE listing_id = ? ORDER BY sort, id LIMIT 1', [(int) $l['id']]);
    }
    render('ext/listing/list', ['title' => 'Inzeráty', 'rows' => $rows, 'covers' => $covers, 'canCreate' => (bool) listing_owner_units($u), 'mine' => listing_mine($u)]);
});

// ---------- Výbor ----------

$route('GET', '/admin/inzeraty', function () {
    require_admin();
    listing_ready();
    $rows = all('SELECT l.*, un.entrance, un.number, u.name owner_name, (SELECT COUNT(*) FROM listing_reports r WHERE r.listing_id = l.id) reports,
        (SELECT COUNT(*) FROM listing_inquiries i WHERE i.listing_id = l.id) inquiries
        FROM listings l LEFT JOIN units un ON un.id = l.unit_id LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC');
    render('ext/listing/admin', ['title' => 'Inzeráty vlastníků', 'rows' => $rows,
        'reports' => all('SELECT * FROM listing_reports ORDER BY id DESC LIMIT 50'), 'penb' => listing_penb()]);
});
$route('POST', '/admin/inzeraty/(\d+)/(skryt|odkryt)', function (int $id, string $action) {
    require_admin();
    $l = listing_get($id) ?? not_found();
    if ($action === 'skryt' && mb_strlen(post('reason')) < 3) {
        flash('Napište vlastníkovi důvod, proč inzerát skrýváte.', 'error');
        redirect('/admin/inzeraty');
    }
    listing_set_hidden($id, $action === 'skryt', post('reason'));
    audit($action === 'skryt' ? 'Skrytí inzerátu' : 'Zobrazení inzerátu', listing_title($l) . ($action === 'skryt' ? ' · ' . post('reason') : ''));
    flash($action === 'skryt' ? 'Inzerát je skrytý, vlastník dostal e-mail s důvodem.' : 'Inzerát je znovu vidět, vlastník dostal e-mail.');
    redirect('/admin/inzeraty');
});
$route('POST', '/admin/nastaveni/inzeraty', function () {
    require_admin();
    if ($err = listing_penb_save(post('penb_class'), post('penb_valid_until'))) {
        $_SESSION['penb_form'] = ['class' => post('penb_class'), 'until' => post('penb_valid_until')];   // vyplněné se po chybě neztratí
        flash($err . ' Nastavení nebylo uloženo.', 'error');
        redirect('/admin/nastaveni#penb');
    }
    $stored = null;
    if (($_FILES['penb_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $stored = strtolower(pathinfo((string) $_FILES['penb_pdf']['name'], PATHINFO_EXTENSION)) === 'pdf' ? store_upload($_FILES['penb_pdf']) : 'Průkaz nahrajte jako PDF.';
        if (is_string($stored)) {
            flash($stored . ' Soubor průkazu nebyl uložen.', 'error');
            redirect('/admin/nastaveni#penb');
        }
    }
    listing_penb_file($stored, isset($_POST['penb_offer']), isset($_POST['penb_remove']));
    listing_setting_save('listing_locality', mb_substr(post('listing_locality'), 0, 100));
    flash('Nastavení průkazu a inzerátů je uloženo.');
    redirect('/admin/nastaveni#penb');
});
$route('GET', '/admin/nastaveni/penb', function () {
    require_admin();
    $f = setting('penb_file');
    if (!preg_match('/^[a-f0-9]{32}\.pdf$/', $f) || !is_file(UPLOAD_DIR . '/' . $f)) {
        not_found();
    }
    header_remove('Content-Security-Policy');
    header('Content-Type: application/pdf');
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode(setting('penb_file_name') !== '' ? setting('penb_file_name') : 'prukaz.pdf'));
    header('Content-Length: ' . filesize(UPLOAD_DIR . '/' . $f));
    readfile(UPLOAD_DIR . '/' . $f);
    exit;
});
