<?php
declare(strict_types=1);

// Hlášení problémů a nápadů z webu společenství.

$reportForm = function (array $errors = []) {
    $mine = user() ? rq('SELECT * FROM reports WHERE tenant = ? AND user_id = ? ORDER BY id DESC LIMIT 100', [TENANT, user()['id']])->fetchAll() : [];
    if ($errors) {
        http_response_code(422);
    }
    render('reports/page', ['title' => 'Nahlásit problém nebo nápad', 'errors' => $errors, 'mine' => $mine, 'msgs' => report_messages_for(array_column($mine, 'id')),
        'back' => report_back((string) ($_GET['z'] ?? post('z', '/')))]);
};
$route('GET', '/hlaseni', fn() => $reportForm());
$route('POST', '/hlaseni', function () use ($reportForm) {
    $errors = report_submit(report_who());
    $thanks = 'Děkujeme, hlášení jsme přijali.' . (user() ? ' Jak ho řešíme, uvidíte v části Moje hlášení.' : ' Odpovíme Vám e-mailem.');
    report_ajax_reply($errors, $thanks);
    if ($errors) {
        $reportForm($errors);
        return;
    }
    flash($thanks);
    // Zpět na stránku, odkud hlášení přišlo – ne na seznam hlášení (uživatel tam mohl mít rozdělanou práci).
    redirect(report_back(post('z', '/')));
});
$myReport = function (int $id): array {
    $u = require_login();
    $r = rq('SELECT * FROM reports WHERE id = ? AND tenant = ? AND user_id = ?', [$id, TENANT, $u['id']])->fetch();
    return $r ?: not_found();
};
$route('GET', '/hlaseni/(\d+)/snimek', fn(int $id) => report_image($myReport($id)));
$route('GET', '/hlaseni/(\d+)/snimek/(\d+)', fn(int $id, int $msg) => report_image($myReport($id), $msg));
// Autor doplní hlášení nebo odpoví na dotaz – zpráva přibude do vlákna a hlášení se vrátí mezi nová.
$route('POST', '/hlaseni/(\d+)/doplnit', function (int $id) use ($myReport) {
    $r = $myReport($id);
    $errors = report_user_followup($r, report_who());
    if ($errors) {
        flash(implode(' ', $errors), 'error');
        $_SESSION['report_draft'] = [$id, mb_substr(post('body'), 0, 5000)];
    } else {
        flash('Děkujeme, doplnění jsme přijali.');
    }
    redirect('/hlaseni#hlaseni-' . $id);
});
// Web jednoho společenství (bez platformy): hlášení vyřizují jeho správci.
if (!reports_central()) {
    $adminReports = function (string $rest = '') {
        $u = require_admin();
        report_admin_page('/admin/hlaseni', $rest, $GLOBALS['METHOD'], $u['name']);
    };
    $route('GET', '/admin/hlaseni', fn() => $adminReports());
    $route('GET', '/admin/hlaseni(/\d+(?:/snimek(?:/\d+)?)?)', $adminReports);
    $route('POST', '/admin/hlaseni(/\d+)', $adminReports);
}
