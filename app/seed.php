<?php
declare(strict_types=1);

/** Naplní novou databázi obsahem převzatým z původního webu svjtabor48.webnode.cz. */
function seed_database(PDO $pdo): void
{
    $seed = json_decode(file_get_contents(__DIR__ . '/seed.json'), true);

    $ids = [];
    $st = $pdo->prepare('INSERT INTO documents (title, category, visibility, original_name, external_url, doc_date) VALUES (?,?,?,?,?,?)');
    foreach ($seed['documents'] as $d) {
        $st->execute([$d['title'], $d['category'], $d['visibility'], $d['original_name'], $d['external_url'], $d['doc_date']]);
        $ids[$d['key']] = (int) $pdo->lastInsertId();
    }

    $st = $pdo->prepare('INSERT INTO posts (title, body, pinned, published_at) VALUES (?,?,?,?)');
    $link = $pdo->prepare('INSERT INTO post_documents (post_id, document_id) VALUES (?,?)');
    foreach ($seed['posts'] as $p) {
        $st->execute([$p['title'], $p['body'], $p['pinned'], $p['published_at']]);
        $pid = (int) $pdo->lastInsertId();
        foreach ($p['docs'] as $key) {
            $link->execute([$pid, $ids[$key]]);
        }
    }

    $contacts = [
        ['vybor', 'Předseda SVJ', 'MgA. Bohdan Jeřábek', '604 524 971', 'bohdan.jerabek@centrum.cz', ''],
        ['vybor', 'Místopředseda SVJ', 'David Skyba', '605 239 976', 'david.skyba@dmb-stavby.cz', 'Zástupce vchodu 48 C'],
        ['vybor', 'Člen výboru', 'Mgr. Jan Pavlík, Ph.D.', '608 771 429', 'velkykorys@gmail.com', 'Zástupce vchodu 48 A'],
        ['sprava', 'Účetní', 'Z - CONSULT s.r.o., Ing. Jarmila Zaviačičová, MBA', '604 371 173', 'zaviacicova@z-consult.cz', ''],
        ['havarie', 'Voda, plyn, elektřina, topení', 'Teplárny Brno', '545 161 545', '', ''],
        ['havarie', 'Elektro', 'Bořivoj Kubík', '724 014 061', 'borivoj.kubik@eon.cz', 'Klusáčkova 1'],
    ];
    $st = $pdo->prepare('INSERT INTO contacts (grp, role, name, phone, email, note, sort) VALUES (?,?,?,?,?,?,?)');
    foreach ($contacts as $i => $c) {
        $st->execute([...$c, ($i + 1) * 10]);
    }

    $pdo->prepare('INSERT INTO events (title, event_date, note, rsvp) VALUES (?,?,?,1)')
        ->execute(['Shromáždění vlastníků SVJ', '2026-12-02', 'Čas, místo a program najdete v pozvánce.']);

    $settings = [
        'intro' => 'Web Společenství vlastníků jednotek Tábor 48 a–d. Najdete tu aktuální oznámení výboru, pozvánky a zápisy ze shromáždění, dokumenty společenství a kontakty na výbor i havarijní servis.',
        'address' => 'Tábor 48 a–d, Brno',
        'ico' => '29236916',
        'account' => '2200506953/2010',
        'emergency_note' => "V případě jakékoliv závady či problému v domě volejte uvedená čísla.\n\n**Objednání havarijního servisu musí odsouhlasit alespoň jeden člen výboru** – kromě stavů ohrožujících život nebo hrozící nenávratné škody na majetku. Neschválený výjezd servisní služby hradí objednatel.",
        'forum_note' => 'Diskuze není oficiální komunikační kanál s výborem – členové výboru nejsou povinni reagovat. Uveďte prosím, kterého vchodu či bytu se příspěvek týká. Vulgární a urážlivé příspěvky správce maže.',
        'webmaster_email' => '',
    ];
    $st = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?,?)');
    foreach ($settings as $k => $v) {
        $st->execute([$k, $v]);
    }
}

/** Naplní novou databázi: jediný web dostane obsah SVJ Tábor 48, nové společenství na platformě začíná prázdné s vlastním názvem. */
function seed_for(PDO $pdo, string $tenant): void
{
    if ($tenant === '') {
        seed_database($pdo);
        return;
    }
    $row = function_exists('tenant_row') ? (tenant_row($tenant) ?? []) : [];
    if (($row['seed'] ?? '') === 'tabor') {
        seed_database($pdo);
    } elseif (($row['seed'] ?? '') === 'demo') {
        require_once __DIR__ . '/seed_demo.php';
        seed_demo($pdo, tenant_storage($tenant) . '/uploads');
    } else {
        $st = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?,?)');
        foreach ([
            'intro' => 'Web našeho společenství vlastníků jednotek. Najdete tu oznámení výboru, dokumenty a kontakty.',
            'address' => '', 'ico' => '', 'account' => '', 'webmaster_email' => '',
            'emergency_note' => 'V případě havárie volejte uvedená čísla.',
            'forum_note' => 'Diskuze není oficiální komunikační kanál s výborem. Vulgární a urážlivé příspěvky správce maže.',
        ] as $k => $v) {
            $st->execute([$k, $v]);
        }
        $pdo->prepare('INSERT INTO posts (title, body, pinned, published_at) VALUES (?,?,1,?)')
            ->execute(['Vítejte na novém webu', 'Web je připraven. Výbor zde bude zveřejňovat oznámení, dokumenty a termíny shromáždění.', date('Y-m-d')]);
    }
    // Název, vzhled a značení jednotek podle toho, co zadal správce platformy při založení.
    $st = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?,?)');
    foreach (['name', 'title', 'accent', 'tagline', 'full', 'unit_prefix', 'entrances', 'look', 'style', 'lang'] as $k) {
        if (isset($row[$k])) {
            $st->execute(['site_' . $k, (string) $row[$k]]);
        }
    }
    if (!empty($row['address'])) {
        $pdo->prepare('UPDATE settings SET value = ? WHERE `key` = ?')->execute([$row['address'], 'address']);
    }
}

/** Doplní obsah, který potřebuje tabulky přidané po základním schématu. */
function seed_after(PDO $pdo, string $tenant): void
{
    $row = $tenant !== '' && function_exists('tenant_row') ? (tenant_row($tenant) ?? []) : [];
    if (($row['seed'] ?? '') === 'demo') {
        require_once __DIR__ . '/seed_demo.php';
        seed_demo_more($pdo);
    }
}
