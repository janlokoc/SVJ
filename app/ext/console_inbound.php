<?php
declare(strict_types=1);

// Konzole provozovatele: ruční kontrola schránky pro odpovědi e-mailem (stav je v Nastavení služby, app/views/ext/inbound/console.php).

if ($PATH === '/nastaveni/schranka' && $METHOD === 'POST') {
    $r = inbound_run(null, 30);
    if (function_exists('mailq_run')) {
        mailq_run(50, true);   // upozornění, která z odpovědí vznikla
    }
    platform_log('Kontrola schránky pro odpovědi', $r['skipped'] !== '' ? $r['skipped'] : 'zpráv ' . $r['checked'] . ', připojeno ' . ($r['attached'] + $r['queued']) . ', odmítnuto ' . ($r['rejected'] + $r['failed']));
    flash(inbound_run_summary($r), $r['error'] !== '' ? 'error' : 'ok');
    redirect('/nastaveni#odpovedi-emailem');
}
