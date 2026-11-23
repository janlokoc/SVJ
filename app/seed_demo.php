<?php
declare(strict_types=1);

/** Jednoduché jednostránkové PDF s nadpisem – ukázkové dokumenty, aby šly v ukázce opravdu otevřít. */
function demo_pdf(string $title): string
{
    $text = strtr($title, ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        'Á' => 'A', 'Č' => 'C', 'É' => 'E', 'Í' => 'I', 'Ř' => 'R', 'Š' => 'S', 'Ú' => 'U', 'Ž' => 'Z', '–' => '-', '(' => '', ')' => '', '\\' => '']);
    $stream = "BT /F1 20 Tf 60 760 Td ($text) Tj ET\nBT /F1 12 Tf 60 730 Td (Ukazkovy dokument - SVJ Zahradni 12) Tj ET";
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $o) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

/** Naplní ukázkový web smyšleným společenstvím: lidé, jednotky, dokumenty, shromáždění, hlasování, závady… */
function seed_demo(PDO $pdo, string $uploads): void
{
    $day = fn(int $n) => date('Y-m-d', time() + $n * 86400);
    $at = fn(int $n, string $time = '18:30:00') => $day($n) . ' ' . $time;
    $ins = function (string $table, array $row) use ($pdo): int {
        $pdo->prepare("INSERT INTO $table (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        return (int) $pdo->lastInsertId();
    };
    $nologin = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);   // do ukázky se vstupuje tlačítkem, ne heslem

    // Lidé (smyšlení) a jednotky
    $people = [['Jana Výborová', 'jana.vyborova@example.cz', 'admin', 'owner', '602 111 222'], ['Petr Novák', 'petr.novak@example.cz', 'resident', 'owner', '603 222 333'],
        ['Marie Nováková', 'marie.novakova@example.cz', 'resident', 'owner', ''], ['Tomáš Dvořák', 'tomas.dvorak@example.cz', 'resident', 'owner', '604 333 444'],
        ['Eva Svobodová', 'eva.svobodova@example.cz', 'resident', 'owner', ''], ['Lukáš Černý', 'lukas.cerny@example.cz', 'resident', 'tenant', '605 444 555'],
        ['Hana Procházková', 'hana.prochazkova@example.cz', 'resident', 'owner', ''], ['Martin Kučera', 'martin.kucera@example.cz', 'resident', 'owner', '606 555 666']];
    $uid = [];
    foreach ($people as $i => [$name, $email, $role, $kind, $phone]) {
        $uid[] = $ins('users', ['name' => $name, 'email' => $email, 'role' => $role, 'kind' => $kind, 'phone' => $phone, 'password_hash' => $nologin, 'last_login_at' => $at(-$i - 1, '19:1' . $i . ':00')]);
    }
    $pdo->exec("UPDATE users SET invited_by = {$uid[1]} WHERE id = {$uid[2]}");
    $owners = ['Výborová Jana', 'Novák Petr a Nováková Marie (SJM)', 'Dvořák Tomáš', 'Svobodová Eva', 'Horák Jiří', 'Procházková Hana', 'Kučera Martin', 'Veselá Alena', 'Marek Pavel', 'Pokorná Lenka', 'Král Zdeněk', 'Němcová Ivana'];
    $shares = [812, 954, 640, 812, 954, 640, 890, 1010, 705, 890, 1010, 683];
    $unit = [];
    foreach ($owners as $i => $owner) {
        $unit[] = $ins('units', ['entrance' => $i < 6 ? 'A' : 'B', 'number' => (string) ($i % 6 + 1), 'floor' => (string) (intdiv($i % 6, 2) + 1), 'share' => $shares[$i] . '/10000', 'owner_note' => $owner,
            'owner_contact' => 'Zahradní 12, 602 00 Brno']);
    }
    foreach ([[0, 0], [1, 1], [2, 1], [3, 2], [4, 3], [5, 4], [6, 5], [7, 6]] as [$u, $j]) {
        $ins('user_units', ['user_id' => $uid[$u], 'unit_id' => $unit[$j]]);
    }

    // Dokumenty (skutečné soubory PDF, ať jdou otevřít)
    $doc = [];
    foreach ([['stanovy', 'Stanovy společenství', 'zakladni', 'public', -900, null], ['dum', 'Domovní řád', 'zakladni', 'public', -400, null], ['pozv', 'Pozvánka na shromáždění', 'pozvanky', 'public', -5, null],
        ['zapis', 'Zápis ze shromáždění', 'zapisy', 'residents', -200, null], ['uzav', 'Účetní závěrka za loňský rok', 'uzaverky', 'residents', -150, null], ['rozp', 'Návrh rozpočtu na příští rok', 'nabidky', 'residents', -6, null],
        ['zmena', 'Formulář – změna počtu osob v bytě', 'formulare', 'public', -300, null], ['vyuct', 'Vyúčtování služeb – jednotka 12A/2', 'oznameni', 'residents', -120, 1]] as [$key, $title, $cat, $vis, $days, $forUnit]) {
        $file = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents($uploads . '/' . $file, demo_pdf($title));
        $doc[$key] = $ins('documents', ['title' => $title, 'category' => $cat, 'visibility' => $vis, 'filename' => $file, 'original_name' => $key . '.pdf', 'size' => filesize($uploads . '/' . $file),
            'doc_date' => $day($days), 'unit_id' => $forUnit === null ? null : $unit[$forUnit]]);
    }

    // Aktuality
    $posts = [['Pozvánka na shromáždění vlastníků', "Výbor svolává shromáždění vlastníků. Na programu je schválení účetní závěrky, rozpočet na příští rok a **výměna vstupních dveří**.\n\nÚčast prosím potvrďte přímo na webu – výbor tak předem ví, zda bude shromáždění usnášeníschopné.", 'public', 1, -5, ['pozv', 'rozp']],
        ['Odečty vodoměrů do konce měsíce', "Prosíme o zapsání stavu vodoměrů v části Pro obyvatele → Odečty měřidel. Kdo nemůže, vhodí lístek do schránky výboru.", 'residents', 0, -9, []],
        ['Revize plynu proběhne ve čtvrtek', "Revizní technik bude procházet byty mezi 8. a 14. hodinou. Zpřístupněte prosím plynoměr a spotřebiče.", 'public', 0, -20, []],
        ['Nový web společenství', "Spustili jsme nový web. Najdete tu dokumenty, kontakty na výbor, hlášení závad i hlasování.", 'public', 0, -45, ['dum']]];
    foreach ($posts as [$title, $body, $vis, $pin, $days, $docs]) {
        $pid = $ins('posts', ['title' => $title, 'body' => $body, 'visibility' => $vis, 'pinned' => $pin, 'published_at' => $day($days), 'author_id' => $uid[0]]);
        foreach ($docs as $k) {
            $ins('post_documents', ['post_id' => $pid, 'document_id' => $doc[$k]]);
        }
    }

    // Kontakty
    foreach ([['vybor', 'Předsedkyně výboru', 'Jana Výborová', '602 111 222', 'jana.vyborova@example.cz', ''], ['vybor', 'Člen výboru', 'Tomáš Dvořák', '604 333 444', 'tomas.dvorak@example.cz', 'Vchod A'],
        ['vybor', 'Člen výboru', 'Martin Kučera', '606 555 666', 'martin.kucera@example.cz', 'Vchod B'], ['sprava', 'Účetní', 'Účetnictví Domov s.r.o.', '541 000 111', 'ucetni@example.cz', ''],
        ['havarie', 'Voda a topení', 'Instalatér nonstop', '800 000 111', '', ''], ['havarie', 'Výtah', 'Servis výtahů', '800 000 222', '', 'Uveďte číslo výtahu ze štítku v kabině.']] as $i => [$g, $role, $name, $phone, $email, $note]) {
        $ins('contacts', ['grp' => $g, 'role' => $role, 'name' => $name, 'phone' => $phone, 'email' => $email, 'note' => $note, 'sort' => ($i + 1) * 10]);
    }

    // Shromáždění s potvrzením účasti a hlasováním
    $ev = $ins('events', ['title' => 'Shromáždění vlastníků', 'event_date' => $day(90), 'event_time' => '18:00', 'place' => 'společenská místnost, vchod A', 'rsvp' => 1,
        'note' => 'Prezence od 17:30. Kdo nemůže přijít, může udělit plnou moc.', 'program' => "1. Zahájení a kontrola usnášeníschopnosti\n2. Účetní závěrka za loňský rok\n3. Rozpočet na příští rok\n4. Výměna vstupních dveří\n5. Různé"]);
    $ins('events', ['title' => 'Odečet vodoměrů a měřičů tepla', 'event_date' => $day(20), 'note' => 'Technik prochází byty 16–19 h.']);
    $ins('events', ['title' => 'Jarní úklid okolí domu', 'event_date' => $day(140), 'event_time' => '9:00', 'place' => 'před vchodem A']);
    foreach (['pozv', 'rozp', 'uzav'] as $k) {
        $ins('event_documents', ['event_id' => $ev, 'document_id' => $doc[$k]]);
    }
    foreach ([[0, 0, 'yes', ''], [1, 1, 'yes', ''], [2, 3, 'proxy', 'Jana Výborová'], [3, 4, 'yes', ''], [5, 6, 'no', ''], [6, 7, 'yes', '']] as [$j, $u, $status, $proxy]) {
        $ins('event_rsvp', ['event_id' => $ev, 'unit_id' => $unit[$j], 'user_id' => $uid[$u], 'status' => $status, 'proxy_name' => $proxy, 'updated_at' => $at(-3)]);
    }
    $poll = $ins('polls', ['title' => 'Výměna vstupních dveří a nové schránky', 'body' => 'Hlasování mimo zasedání (per rollam). Podklady a nabídky dodavatelů jsou v dokumentech.', 'opens_at' => $day(-7), 'closes_at' => $day(60),
        'threshold' => 50, 'online' => 1, 'published' => 1, 'created_by' => $uid[0]]);
    $q1 = $ins('poll_items', ['poll_id' => $poll, 'question' => 'Souhlasíte s výměnou vstupních dveří v obou vchodech za cenu do 280 000 Kč?', 'sort' => 1]);
    $q2 = $ins('poll_items', ['poll_id' => $poll, 'question' => 'Souhlasíte s pořízením nových poštovních schránek?', 'sort' => 2]);
    foreach ([[0, 0, 'yes', 'yes'], [1, 1, 'yes', 'no'], [3, 4, 'yes', 'yes'], [5, 6, 'no', 'abstain'], [6, 7, 'yes', 'yes']] as [$j, $u, $c1, $c2]) {
        $ins('poll_votes', ['item_id' => $q1, 'unit_id' => $unit[$j], 'user_id' => $uid[$u], 'choice' => $c1, 'voted_at' => $at(-2)]);
        $ins('poll_votes', ['item_id' => $q2, 'unit_id' => $unit[$j], 'user_id' => $uid[$u], 'choice' => $c2, 'voted_at' => $at(-2)]);
    }

    // Závady, diskuze
    $ins('tickets', ['user_id' => $uid[1], 'subject' => 'Nesvítí světlo ve 3. patře', 'location' => 'vchod A, chodba 3. patro', 'body' => 'Od víkendu nesvítí světlo u výtahu.', 'status' => 'in_progress',
        'admin_note' => 'Elektrikář objednán na středu.', 'created_at' => $at(-4), 'updated_at' => $at(-3)]);
    $ins('tickets', ['user_id' => $uid[5], 'subject' => 'Kape radiátor v kočárkárně', 'location' => 'vchod A, přízemí', 'body' => 'Pod radiátorem je louže.', 'status' => 'new', 'created_at' => $at(-1), 'updated_at' => $at(-1)]);
    $ins('tickets', ['user_id' => $uid[3], 'subject' => 'Prosba o druhý čip ke vchodu', 'location' => 'vchod A', 'body' => 'Potřebovali bychom ještě jeden čip pro dceru.', 'status' => 'done',
        'admin_note' => 'Čip vydán, zapsáno v evidenci.', 'created_at' => $at(-30), 'updated_at' => $at(-27)]);
    $th = $ins('forum_posts', ['title' => 'Kola v kočárkárně', 'body' => 'Dalo by se domluvit, aby kola stála jen u zadní stěny? Kočárky se tam pak nevejdou.', 'author_id' => $uid[4], 'created_at' => $at(-12), 'last_activity_at' => $at(-10)]);
    $ins('forum_posts', ['parent_id' => $th, 'body' => 'Souhlas. Navrhuji dát na shromáždění do bodu Různé stojan na kola před dům.', 'author_id' => $uid[1], 'created_at' => $at(-11), 'last_activity_at' => $at(-11)]);
    $ins('forum_posts', ['parent_id' => $th, 'body' => 'Výbor to zařadí do programu.', 'author_id' => $uid[0], 'created_at' => $at(-10), 'last_activity_at' => $at(-10)]);
    $ins('forum_posts', ['title' => 'Doporučení na malíře', 'body' => 'Nemáte někdo dobrou zkušenost s malířem v okolí?', 'author_id' => $uid[6], 'created_at' => $at(-6), 'last_activity_at' => $at(-6)]);

    // Provoz domu
    foreach ([['Revize plynového zařízení', 'Plynoservis Brno', -340, 12], ['Revize výtahů', 'Servis výtahů', -70, 3], ['Kontrola hasicích přístrojů a hydrantů', 'Požární servis', -350, 12],
        ['Revize hromosvodu', 'Elektro revize', -1200, 48], ['Čištění komínů a spalinových cest', 'Kominictví', -200, 12]] as [$title, $who, $last, $months]) {
        $ins('maintenance', ['title' => $title, 'contractor' => $who, 'last_date' => $day($last), 'interval_months' => $months, 'next_date' => date('Y-m-d', strtotime($day($last) . " +$months month"))]);
    }
    foreach ([[1, 1, 'sv', 'SV-20417', 312.4], [1, 1, 'tv', 'TV-20418', 148.9], [4, 3, 'sv', 'SV-20431', 201.0], [6, 5, 'sv', 'SV-20440', 95.7]] as [$j, $u, $kind, $meter, $amount]) {
        $ins('readings', ['unit_id' => $unit[$j], 'kind' => $kind, 'meter_no' => $meter, 'amount' => $amount, 'read_date' => $day(-8), 'user_id' => $uid[$u]]);
    }
    $room = $ins('resources', ['name' => 'Společenská místnost', 'note' => 'Kapacita 20 osob. Klíč u výboru, úklid po akci.']);
    $ins('resources', ['name' => 'Sušárna', 'note' => 'Vchod B, suterén.']);
    $ins('reservations', ['resource_id' => $room, 'user_id' => $uid[3], 'res_date' => $day(6), 'time_from' => '15:00', 'time_to' => '19:00', 'note' => 'Oslava narozenin']);
    $ins('reservations', ['resource_id' => $room, 'user_id' => $uid[0], 'res_date' => $day(90), 'time_from' => '17:00', 'time_to' => '21:00', 'note' => 'Shromáždění vlastníků']);
    foreach ([['Čip č. 014', 1, 'Petr Novák', -300, null], ['Čip č. 015', 1, 'Marie Nováková', -300, null], ['Klíč od kočárkárny', 4, 'Eva Svobodová', -90, null], ['Klíč od společenské místnosti', 3, 'Tomáš Dvořák', -2, null],
        ['Čip č. 009', 8, 'Pavel Marek', -500, -40]] as [$label, $j, $holder, $issued, $returned]) {
        $ins('keys_log', ['label' => $label, 'unit_id' => $unit[$j], 'holder' => $holder, 'issued_at' => $day($issued), 'returned_at' => $returned === null ? null : $day($returned)]);
    }

    // Rozeslaný e-mail s přehledem doručení
    $mail = $ins('mailings', ['subject' => 'Pozvánka na shromáždění vlastníků', 'body' => "Vážení vlastníci,\n\nzveme Vás na shromáždění. Účast prosím potvrďte na webu.", 'audience' => 'owners', 'sent_count' => 6, 'tracked' => 1,
        'sent_by' => $uid[0], 'sent_at' => $at(-5, '10:00:00')]);
    foreach ([1, 2, 3, 4, 6, 7] as $n => $u) {
        $ins('mailing_recipients', ['mailing_id' => $mail, 'user_id' => $uid[$u], 'name' => $people[$u][0], 'email' => $people[$u][1], 'token' => bin2hex(random_bytes(16)), 'sent_ok' => 1,
            'opened_at' => $n < 4 ? $at(-5, '1' . $n . ':20:00') : null, 'clicked_at' => $n < 3 ? $at(-5, '1' . $n . ':25:00') : null]);
    }

    foreach (['intro' => 'Ukázkový web smyšleného společenství vlastníků. Prohlédněte si veřejnou část, část pro obyvatele i administraci – změny se v ukázce neukládají.',
        'address' => 'Zahradní 12, Brno', 'ico' => '00000000', 'account' => '000000-0000000000/0000', 'webmaster_email' => '',
        'emergency_note' => "V případě havárie volejte uvedená čísla.\n\n**Výjezd havarijní služby musí odsouhlasit člen výboru** – kromě situací, kdy hrozí škoda na zdraví nebo majetku.",
        'forum_note' => 'Diskuze není oficiální komunikační kanál s výborem. Vulgární a urážlivé příspěvky správce maže.'] as $k => $v) {
        $ins('settings', ['key' => $k, 'value' => $v]);
    }
}

/** Obsah ukázky v tabulkách, které vznikají až po základním schématu (ankety). */
function seed_demo_more(PDO $pdo): void
{
    $day = fn(int $n) => date('Y-m-d', time() + $n * 86400);
    $at = fn(int $n, string $time = '18:30:00') => $day($n) . ' ' . $time;
    $ins = function (string $table, array $row) use ($pdo): int {
        $pdo->prepare("INSERT INTO $table (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        return (int) $pdo->lastInsertId();
    };
    $uid = array_map('intval', array_column($pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(), 'id'));
    if (count($uid) < 8) {
        return;
    }
    // Ankety: jedna běžící (obyvatel z ukázky v ní ještě neodpověděl, takže vidí možnosti) a jedna uzavřená s výsledky
    $survey = $ins('surveys', ['title' => 'Kdy uspořádat společnou brigádu?', 'body' => 'Čeká nás úklid dvora, natření laviček a výsadba u vchodu B. Vyberte termín, který Vám vyhovuje nejvíc – výbor pak pošle pozvánku.',
        'closes_at' => $day(10), 'anonymous' => 1, 'published' => 1, 'created_at' => $at(-4, '09:15:00')]);
    $opt = [];
    foreach (['Sobota dopoledne', 'Sobota odpoledne', 'Neděle dopoledne', 'Nezúčastním se, ale přispěji na občerstvení'] as $i => $label) {
        $opt[] = $ins('survey_options', ['survey_id' => $survey, 'label' => $label, 'sort' => $i]);
    }
    foreach ([[0, 0], [2, 0], [3, 1], [4, 0], [6, 3], [7, 2]] as $n => [$u, $o]) {
        $ins('survey_answers', ['survey_id' => $survey, 'user_id' => $uid[$u], 'option_id' => $opt[$o], 'answered_at' => $at(-3 + ($n % 3), '1' . $n . ':05:00')]);
    }
    $survey = $ins('surveys', ['title' => 'Máte zájem o kolárnu ve dvoře?', 'body' => 'Zjišťujeme zájem, než necháme zpracovat nabídku na uzamykatelný přístřešek pro 12 kol.',
        'closes_at' => $day(-20), 'anonymous' => 0, 'published' => 1, 'created_at' => $at(-45, '17:40:00')]);
    $opt = [];
    foreach (['Ano, využil(a) bych ji', 'Ne, kolo nemám nebo ho mám ve sklepě', 'Je mi to jedno'] as $i => $label) {
        $opt[] = $ins('survey_options', ['survey_id' => $survey, 'label' => $label, 'sort' => $i]);
    }
    foreach ([[0, 0], [1, 0], [2, 0], [3, 1], [4, 0], [5, 2], [6, 1], [7, 0]] as $n => [$u, $o]) {
        $ins('survey_answers', ['survey_id' => $survey, 'user_id' => $uid[$u], 'option_id' => $opt[$o], 'answered_at' => $at(-40 + $n, '18:1' . $n . ':00')]);
    }

    // Úkoly a usnesení: otevřené, po termínu i splněné
    foreach ([
        ['Oslovit tři firmy s poptávkou na výměnu vchodových dveří', 'Nabídky předložit na příštím jednání výboru. Požadujeme bezpečnostní třídu RC2 a samozavírač.', 'Jana Výborová', 21, 0, 'Usnesení shromáždění č. 3/2026', -30],
        ['Objednat revizi hromosvodu', 'Poslední revize proběhla před čtyřmi lety, lhůta je pět let.', 'Tomáš Dvořák', 45, 0, 'Jednání výboru', -12],
        ['Vyvěsit nový domovní řád ve vchodech A a B', 'Schválené znění je v Dokumentech.', 'Petr Novák', -5, 0, 'Usnesení shromáždění č. 5/2026', -40],
        ['Zjistit zájem o kolárnu ve dvoře', 'Anketa na webu, výsledek předložit výboru.', 'Jana Výborová', -25, 1, 'Jednání výboru', -50],
        ['Uzavřít smlouvu na úklid společných prostor', 'Vybrána nabídka s úklidem dvakrát týdně.', 'Jana Výborová', -60, 1, 'Usnesení shromáždění č. 2/2026', -120],
    ] as [$title, $note, $who, $due, $done, $source, $created]) {
        $ins('tasks', ['title' => $title, 'note' => $note, 'assignee' => $who, 'due_date' => $day($due), 'done' => $done, 'source' => $source, 'created_at' => $at($created, '19:00:00')]);
    }

    // Dodavatelé (smyšlení); první otevřená závada má přiřazeného instalatéra
    $sup = [];
    foreach ([
        ['Instalatérství Vodička', 'Voda, topení, kanalizace', '777 100 201', 'vodicka@example.cz', 'Havarijní výjezd do 2 hodin. Servisní smlouva na stoupačky.'],
        ['Výtahy Morava s.r.o.', 'Servis a revize výtahů', '777 100 202', 'servis@vytahy-morava.example.cz', 'Pravidelná prohlídka každé 3 měsíce, odborná zkouška jednou za 3 roky.'],
        ['Elektro Blesk', 'Elektroinstalace, hromosvody', '777 100 203', 'blesk@example.cz', 'Revize společné elektroinstalace a hromosvodu.'],
        ['Úklid Čistý dům', 'Úklid společných prostor', '777 100 204', 'info@cisty-dum.example.cz', 'Úklid dvakrát týdně, mytí oken na jaře a na podzim.'],
        ['Zámečnictví Klíč', 'Zámky, dveře, čipový systém', '777 100 205', 'klic@example.cz', 'Dodavatel čipů ke vchodovým dveřím; nové čipy do 3 pracovních dnů.'],
        ['Správa domů Jih s.r.o.', 'Účetnictví a správa', '777 100 206', 'sprava@sprava-jih.example.cz', 'Vede účetnictví, předpisy záloh a roční vyúčtování.'],
    ] as [$name, $field, $phone, $email, $note]) {
        $sup[] = $ins('suppliers', ['name' => $name, 'field' => $field, 'phone' => $phone, 'email' => $email, 'note' => $note]);
    }
    $ticket = (int) $pdo->query("SELECT id FROM tickets WHERE status <> 'done' ORDER BY id LIMIT 1")->fetchColumn();
    if ($ticket) {
        $pdo->prepare('UPDATE tickets SET supplier_id = ? WHERE id = ?')->execute([$sup[0], $ticket]);
    }

    // Platby na jednotku: předpis měsíčních záloh podle podílu a stav k 1. dni měsíce – většina v pořádku, pár přeplatků
    // a dva nedoplatky (jeden u obyvatele z ukázky, aby bylo vidět upozornění a QR platbu).
    $petrUnit = (int) $pdo->query('SELECT unit_id FROM user_units WHERE user_id = ' . $uid[1] . ' ORDER BY unit_id LIMIT 1')->fetchColumn();
    $balances = [0, 0, 350, 0, -7420, 0, 0, 1200, 0, 0, 0, 0];
    foreach ($pdo->query('SELECT id, entrance, number, share FROM units ORDER BY entrance, number + 0')->fetchAll() as $i => $un) {
        [$num, $den] = array_map('intval', explode('/', $un['share'] ?: '800/10000')) + [0, 1];
        $prescribed = round(32000 * ($den ? $num / $den : 0.08) / 10) * 10;   // dům: zálohy celkem asi 32 000 Kč měsíčně
        $balance = (int) $un['id'] === $petrUnit ? -1250.0 : (float) ($balances[$i % count($balances)] ?? 0);
        $note = $balance < -5000 ? 'Neuhrazené zálohy za září a říjen' : ($balance < 0 ? 'Nedoplatek z vyúčtování služeb za loňský rok' : ($balance > 0 ? 'Přeplatek – započte se do příští zálohy' : ''));
        $ins('unit_accounts', ['unit_id' => (int) $un['id'], 'prescribed' => $prescribed, 'balance' => $balance, 'vs' => ($un['entrance'] === 'A' ? '1' : '2') . sprintf('%02d', (int) $un['number']) . '12',
            'as_of' => date('Y-m-01'), 'note' => $note]);
    }

    // Kdo je kdo: výbor a kontrolní komise
    foreach ([0 => 'predseda', 4 => 'mistopredseda', 3 => 'clen_vyboru', 6 => 'predseda_kk', 7 => 'clen_kk'] as $i => $position) {
        $pdo->prepare('UPDATE users SET position = ? WHERE id = ?')->execute([$position, $uid[$i]]);
    }
    // Zprávy mezi sousedy: rozhovor obyvatele z ukázky s předsedkyní a jedna nepřečtená zpráva od sousedky
    foreach ([
        [1, 0, 'Dobrý den, paní Výborová, v kočárkárně už týden nesvítí světlo. Mám to zadat jako závadu, nebo to stačí takhle?', -3, '18:12:00', true],
        [0, 1, 'Dobrý den, děkuji za upozornění. Zadejte to prosím jako závadu, ať se to neztratí – elektrikář přijde ve čtvrtek kvůli hromosvodu a vezme to při jednom.', -3, '19:40:00', true],
        [1, 0, 'Zadáno, děkuji.', -3, '19:55:00', true],
        [0, 1, 'Opraveno, vyměnili zářivku i startér. Kdyby to zlobilo dál, dejte vědět.', -1, '16:05:00', true],
        [2, 1, 'Petře, půjčíš nám v sobotu vozík na přívěs? Vezeme starou skříň do sběrného dvora.', 0, '08:20:00', false],
    ] as [$from, $to, $body, $d, $time, $read]) {
        $ins('messages', ['from_id' => $uid[$from], 'to_id' => $uid[$to], 'body' => $body, 'created_at' => $at($d, $time), 'read_at' => $read ? $at($d, '23:00:00') : null]);
    }
}
