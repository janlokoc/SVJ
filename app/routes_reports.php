<?php
declare(strict_types=1);

// Hlášení problémů a nápadů z webu společenství.

$reportForm = function (array $errors = []) {
    $mine = user() ? rq('SELECT * FROM reports WHERE tenant = ? AND user_id = ? ORDER BY id DESC LIMIT 100', [TENANT, user()['id']])->fetchAll() : [];
    if ($errors) {
        http_response_code(422);
    }
    render('reports/page', ['title' => 'Nahlásit problém nebo nápad', 'errors' => $errors, 'mine' => $mine, 'back' => report_back((string) ($_GET['z'] ?? post('z', '/')))]);
};
$route('GET', '/hlaseni', fn() => $reportForm());
$route('POST', '/hlaseni', function () use ($reportForm) {
    $errors = report_submit(report_who());
    if ($errors) {
        $reportForm($errors);
        return;
    }
    flash('Děkujeme, hlášení jsme přijali.' . (user() ? ' Jak ho řešíme, uvidíte v části Moje hlášení.' : ' Odpovíme Vám e-mailem.'));
    redirect(user() ? '/hlaseni' : report_back(post('z', '/')));
});
$route('GET', '/hlaseni/(\d+)/snimek', function (int $id) {
    $u = require_login();
    $r = rq('SELECT * FROM reports WHERE id = ? AND tenant = ? AND user_id = ?', [$id, TENANT, $u['id']])->fetch();
    $r ? report_image($r) : not_found();
});
// Web jednoho společenství (bez platformy): hlášení vyřizují jeho správci.
if (!reports_central()) {
    $adminReports = function (string $rest = '') {
        $u = require_admin();
        report_admin_page('/admin/hlaseni', $rest, $GLOBALS['METHOD'], $u['name']);
    };
    $route('GET', '/admin/hlaseni', fn() => $adminReports());
    $route('GET', '/admin/hlaseni(/\d+(?:/snimek)?)', $adminReports);
    $route('POST', '/admin/hlaseni(/\d+)', $adminReports);
}
