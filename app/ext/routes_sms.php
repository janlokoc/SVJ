<?php
declare(strict_types=1);

// SMS zprávy pro vlastníky (funkce v betě 'sms'): náhled, počet částí a cena, odeslání; platí se z kreditu služeb (/admin/kredit).

$smsAdmin = function () {
    $me = require_admin();
    if (!beta_visible('sms')) {
        not_found();
    }
    sms_ready();
    $preview = null;
    $text = (string) ($_POST['text'] ?? '');
    $plain = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['plain']) : true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        $text = trim(str_replace("\r\n", "\n", $text));
        $final = $plain ? sms_plain($text) : $text;
        if ($action === 'order') {
            redirect('/admin/kredit#dobit');   // SMS se platí z kreditu služeb (dřívější balíčky SMS se už neobjednávají)
        }
        if ($final === '') {
            flash('Napište text zprávy.', 'error');
            redirect('/admin/sms');
        }
        if (sms_segments($final)['segments'] > SMS_MAX_SEGMENTS) {
            flash('Zpráva je příliš dlouhá – nejvýš ' . SMS_MAX_SEGMENTS . ' SMS. Delší sdělení pošlete e-mailem a SMS jen upozorněte.', 'error');
            $action = 'preview';
        }
        if ($action === 'send') {
            [$sent, $failed, $err] = sms_send_owners($final);
            audit('SMS: zpráva vlastníkům', "odesláno $sent" . ($failed ? ", neodesláno $failed" : ''));
            if ($sent === 0 && $err !== null) {
                flash($err, 'error');
            } else {
                flash("SMS odeslána $sent vlastníkům" . ($failed ? ", neodesláno $failed ($err)" : '') . '.', $failed ? 'error' : 'ok');
            }
            redirect('/admin/sms');
        }
        [$rcpt] = sms_owner_recipients();
        [$seg, $total, $czk] = sms_cost($final, count($rcpt));
        $preview = ['text' => $final, 'info' => sms_segments($final), 'recipients' => count($rcpt), 'total' => $total, 'czk' => $czk];
    }
    [$rcpt, $bad] = sms_owner_recipients();
    $managed = sms_credit_managed();
    render('ext/sms/admin', ['title' => 'SMS zprávy', 'text' => $text, 'plain' => $plain, 'preview' => $preview, 'recipients' => $rcpt, 'bad' => $bad,
        'managed' => $managed, 'balance' => $managed ? sms_balance() : null, 'creditH' => $managed ? credit_balance_h(TENANT) : 0, 'freeLeft' => $managed ? sms_free_left(TENANT) : 0,
        'freeYear' => $managed ? sms_free_allowance(TENANT) : 0, 'price' => sms_price_h(),
        'log' => all('SELECT * FROM sms_log ORDER BY id DESC LIMIT 30'), 'staging' => sms_provider_name() === 'log']);
};
$route('GET', '/admin/sms', $smsAdmin);
$route('POST', '/admin/sms', $smsAdmin);
