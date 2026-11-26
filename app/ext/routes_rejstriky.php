<?php
declare(strict_types=1);

// Modul E – katastr a rejstříky: přehled, změny v katastru, lustrace exekucí, hlídání insolvence fyzických osob.

$rejGate = function (string $feature, bool $write = false): array {
    $u = require_admin();
    if (!rej_visible($feature)) {
        not_found();
    }
    if ($write && !rej_premium()) {
        flash('Tato funkce je součástí edice Premium.', 'error');
        redirect('/admin/rejstriky');
    }
    return $u;
};

$route('GET', '/admin/rejstriky', function () {
    require_admin();
    if (!rej_nav_visible()) {
        not_found();
    }
    render('ext/rejstriky/summary', ['title' => 'Katastr a rejstříky', 'sum' => rej_summary(), 'premium' => rej_premium(), 'usage' => rej_usage_tenant(10)]);
});

// ---------- Změny v katastru ----------
$route('GET', '/admin/rejstriky/katastr', function () use ($rejGate) {
    $rejGate('katastr');
    rej_ready();
    $show = ($_GET['zobrazit'] ?? '') === 'vse' ? 'all' : 'new';
    render('ext/rejstriky/katastr', ['title' => 'Změny v katastru', 'premium' => rej_premium(), 'configured' => rej_katastr_configured(), 'target' => rej_katastr_target(),
        'on' => setting('rej_katastr_on'), 'last' => explode('|', setting('rej_katastr_last'), 3), 'units' => (int) val('SELECT COUNT(*) FROM units'),
        'lvCount' => rej_katastr_lv_count(), 'price' => rej_katastr_year_price(rej_katastr_lv_count()), 'billed' => rej_katastr_billed(), 'payment' => rej_katastr_payment(),
        'days' => max(1, rej_price('rej_katastr_days')), 'show' => $show,
        'changes' => all('SELECT * FROM rej_katastr_changes' . ($show === 'new' ? " WHERE status = 'new'" : '') . ' ORDER BY id DESC LIMIT 300'),
        'unitNames' => array_column(array_map(fn($u) => ['id' => $u['id'], 'n' => unit_name($u)], all_units()), 'n', 'id')]);
});
$route('POST', '/admin/rejstriky/katastr', function () use ($rejGate) {
    $u = $rejGate('katastr', true);
    $action = post('action');
    if ($action === 'target') {
        $err = rej_katastr_target_error(post('ku'), post('lv'));
        if ($err) {
            flash($err, 'error');
        } else {
            $lv = implode(',', array_map('trim', explode(',', post('lv'))));
            if ($lv !== setting('rej_katastr_lv') || post('ku') !== setting('rej_katastr_ku')) {
                rej_set('rej_katastr_base', '');   // jiný dům → nové výchozí porovnání s evidencí
                rej_set('rej_katastr_pending', '');
            }
            $before = rej_katastr_lv_count();
            rej_set('rej_katastr_ku', post('ku'));
            rej_set('rej_katastr_lv', $lv);
            audit('Katastr: budova', 'k.ú. ' . post('ku') . ', LV ' . $lv);
            if (setting('rej_katastr_on') !== '' && rej_katastr_lv_count() !== $before) {
                rej_katastr_target_changed();
                flash('Uloženo. Cena sledování je teď ' . money_czk(rej_katastr_year_price(rej_katastr_lv_count())) . ' ročně bez DPH' . (rej_katastr_lv_count() > $before && rej_katastr_payment() ? '; za další LV do konce zaplaceného období je výzva k platbě v části Předplatné.' : '.'));
            } else {
                flash('Uloženo.');
            }
        }
    } elseif ($action === 'activate') {
        if (post('confirm') !== '1') {
            flash('Pro zapnutí potvrďte cenu sledování.', 'error');
        } elseif (rej_katastr_target()['ku'] === '' || !rej_katastr_target()['lv']) {
            flash('Nejdřív doplňte kód katastrálního území a číslo LV budovy.', 'error');
        } else {
            $price = rej_katastr_activate();
            audit('Katastr: sledování zapnuto', money_czk($price) . ' ročně');
            $pay = rej_katastr_payment();
            flash('Sledování je zapnuté. První kontrola proběhne během dne – nebo ji spusťte tlačítkem „Zkontrolovat teď“.'
                . ($pay ? ' Za zbytek zaplaceného období (' . pay_money($pay) . ') je výzva k platbě v části Předplatné; dál se sledování platí s ročním předplatným.' : ''));
        }
    } elseif ($action === 'deactivate') {
        rej_katastr_deactivate();
        audit('Katastr: sledování vypnuto');
        flash('Sledování je vypnuté. Zaplacené období se nevrací; na další roční faktuře už nebude.', 'info');
    } elseif ($action === 'run') {
        if (setting('rej_katastr_on') === '') {
            flash('Nejdřív sledování zapněte.', 'error');
        } else {
            $r = rej_katastr_run();
            audit('Katastr: kontrola', $r['ok'] ? 'nových změn ' . $r['new'] : $r['error']);
            flash(!$r['ok'] ? 'Kontrola se nepovedla: ' . $r['error'] : ($r['pending'] ? 'Katastr výpis ještě připravuje – dokončí se při příští kontrole.' : ($r['new'] ? 'Nových změn: ' . $r['new'] . '.' : 'Bez nových změn.')),
                !$r['ok'] ? 'error' : ($r['new'] ? 'info' : 'ok'));
        }
    } elseif (in_array($action, ['apply', 'dismiss'], true)) {
        flash(rej_katastr_handle((int) post('id'), $action, $u['name']));
    }
    redirect('/admin/rejstriky/katastr');
});

// ---------- Lustrace exekucí ----------
$route('GET', '/admin/rejstriky/exekuce', function () use ($rejGate) {
    $rejGate('exekuce');
    render('ext/rejstriky/exekuce', ['title' => 'Lustrace exekucí', 'premium' => rej_premium(), 'configured' => rej_cee_configured(), 'subjects' => rej_premium() ? rej_exekuce_subjects() : [],
        'latest' => rej_exekuce_latest(), 'price' => rej_price('rej_price_exekuce'), 'creditOk' => rej_exekuce_credit_ok()]);
});
$route('POST', '/admin/rejstriky/exekuce', function () use ($rejGate) {
    $u = $rejGate('exekuce', true);
    $subject = rej_exekuce_subjects()[post('subject')] ?? null;
    if (!$subject) {
        flash('Vlastník nenalezen.', 'error');
    } elseif (post('confirm') !== '1') {
        flash('Lustrace je placená – potvrďte prosím cenu.', 'error');
    } elseif (!rej_cee_configured()) {
        flash('Evidence exekucí zatím není propojená. Napište prosím provozovateli.', 'error');
    } elseif (!rej_exekuce_credit_ok()) {
        flash('Kredit služeb nestačí na lustraci (' . money_czk(rej_price('rej_price_exekuce')) . '). Dobijte ho v části Kredit služeb.', 'error');
        redirect('/admin/kredit#dobit');
    } else {
        $row = rej_exekuce_check($subject, $u['name']);
        flash(($row['error'] ?? '') !== '' ? 'Lustrace se nepovedla: ' . $row['error'] : ((int) $row['found'] ? 'Nalezené exekuce: ' . (int) $row['found'] . '.' : 'V evidenci exekucí nic nenalezeno.'),
            ($row['error'] ?? '') !== '' ? 'error' : ((int) $row['found'] ? 'info' : 'ok'));
    }
    redirect('/admin/rejstriky/exekuce');
});

// ---------- Insolvence fyzických osob (část stránky /admin/insolvence) ----------
$route('POST', '/admin/insolvence/osoby', function () use ($rejGate) {
    $u = $rejGate('insolvence', true);
    $action = post('action');
    if ($action === 'add') {
        $err = rej_person_add((int) post('unit_id'), post('surname'), post('first_name'), post('birth_date'), (int) $u['id']);
        if ($err) {
            flash($err, 'error');
        } else {
            audit('Insolvence: hlídaná osoba přidána', post('surname') . ' (jednotka ' . (int) post('unit_id') . ')');
            flash('Osoba se bude hlídat. Ověří se při nejbližší týdenní kontrole, nebo hned tlačítkem „Zkontrolovat osoby teď“.');
        }
    } elseif ($action === 'delete') {
        if (rej_person_delete((int) post('id'))) {
            audit('Insolvence: hlídaná osoba odebrána', '#' . (int) post('id'));
            flash('Osoba se už nehlídá a její údaje jsou smazané.');
        }
    } elseif ($action === 'run') {
        [$checked, $hits, $error] = rej_isir_run();
        audit('Insolvence osob: kontrola', "ověřeno $checked, řízení $hits" . ($error !== '' ? ' · ' . $error : ''));
        flash($error !== '' ? 'Kontrola se nepovedla: ' . $error : "Zkontrolováno osob: $checked. Nalezená řízení: $hits.", $error !== '' ? 'error' : ($hits ? 'info' : 'ok'));
    }
    redirect('/admin/insolvence#osoby');
});
