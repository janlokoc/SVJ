<?php
// Ověřování návodů: založí ve zvláštní složce (SVJ_STORAGE) ukázkové společenství se známými hesly.
// Spouští ho overit.js – skutečná data webu se nikdy nepoužijí.
declare(strict_types=1);
if (!getenv('SVJ_STORAGE')) {
    fwrite(STDERR, "Chybí SVJ_STORAGE – spusťte přes overit.js.\n");
    exit(1);
}
chdir(__DIR__ . '/../..');
require 'app/lib.php';
pset_save('mail_from', 'sluzba@example.cz');
tenant_create(['slug' => 'dum', 'name' => 'SVJ Zahradní 12', 'title' => 'Zahradní 12', 'full' => 'Společenství vlastníků jednotek Zahradní 12', 'address' => 'Zahradní 12, Brno',
    'unit_prefix' => '12', 'entrances' => 'A,B', 'look' => 'les', 'style' => 'klasik', 'seed' => 'demo']);
tenant_set('dum', 'mail_from', 'vybor@example.cz');
$pdo = tenant_pdo('dum');
$hash = password_hash('Navod-overeni-2027', PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0')->execute([$hash]);
$admin = $pdo->query("SELECT email FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$owner = $pdo->query("SELECT u.email FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE u.role <> 'admin' AND u.kind = 'owner' ORDER BY u.id LIMIT 1")->fetchColumn();
// Správcovská firma s přístupem k domu (partnerský portál – návod Propojení s účetnictvím).
pq("INSERT INTO partners (name, ico, email, created_at) VALUES ('Správa domů Brno s.r.o.', '27082440', 'kancelar@example.cz', ?)", [date('Y-m-d H:i:s')]);
$partnerId = (int) platform_db()->lastInsertId();
pq("INSERT INTO partner_users (partner_id, name, email, password_hash, role, created_at) VALUES (?, 'Karel Správce', 'karel@example.cz', ?, 'owner', ?)", [$partnerId, $hash, date('Y-m-d H:i:s')]);
partner_attach_created(partner_row($partnerId), 'dum');
pq("UPDATE tenants SET partner_billing = 0 WHERE slug = 'dum'");   // předplatné (a kredit služeb) si dům platí sám
echo json_encode(['admin' => $admin, 'owner' => $owner, 'password' => 'Navod-overeni-2027', 'partner' => 'karel@example.cz']), "\n";
