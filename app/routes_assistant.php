<?php
declare(strict_types=1);

// AI asistent (edice Premium): otázky obyvatel a výboru, odpovědi podle podkladů domu s odkazy na zdroje.
// Rozhovor se drží jen v relaci prohlížeče (posledních 10 otázek); na serveru se neukládá.

$assistantPage = function () {
    require_login();
    if (!ai_available()) {
        render('error', ['title' => 'AI asistent', 'message' => 'AI asistent na tomto webu teď není zapnutý.']);
        return;
    }
    $history = $_SESSION['asistent'] ?? [];
    $error = null;
    $ajax = post('ajax') === '1';
    $json = function (array $data): never {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };
    if ($GLOBALS['METHOD'] === 'POST') {
        if (post('do') === 'nova') {
            unset($_SESSION['asistent']);
            $ajax ? $json(['ok' => true]) : redirect('/asistent');
        }
        $r = assistant_ask(post('q'), array_map(fn($h) => [$h['q'], $h['a']], $history));
        if (isset($r['error'])) {
            $error = $r['error'];
            if ($ajax) {
                $json(['ok' => false, 'error' => $error]);
            }
        } else {
            $entry = ['q' => mb_substr(trim(post('q')), 0, 1000), 'a' => $r['text'], 'sources' => array_map(fn($s) => [$s[0], $s[1], $s[2]], $r['sources']), 'at' => date('H:i')];
            $history[] = $entry;
            $_SESSION['asistent'] = array_slice($history, -10);
            if ($ajax) {
                $json(['ok' => true, 'html' => assistant_entry_html($entry)]);
            }
            redirect('/asistent#posledni');
        }
    }
    render('assistant', ['title' => 'AI asistent', 'history' => $history, 'error' => $error, 'q' => $error ? post('q') : '']);
};
$route('GET', '/asistent', $assistantPage);
$route('POST', '/asistent', $assistantPage);
