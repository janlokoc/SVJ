<?php
declare(strict_types=1);

/*
 * Vyúčtování služeb podle zákona č. 67/2013 Sb. (https://www.zakonyprolidi.cz/cs/2013-67):
 * - § 7 odst. 1: vyúčtování se doručí nejpozději do 4 měsíců od skončení zúčtovacího období,
 * - § 8 odst. 1: námitky může příjemce uplatnit do 30 dnů od doručení vyúčtování,
 * - § 8 odst. 2: poskytovatel námitky vyřídí do 30 dnů od jejich doručení.
 * Správce nahraje soubory vyúčtování jednotek (jednotka se pozná z názvu souboru, zbytek se přiřadí ručně), nastaví období
 * a den doručení a zveřejní. Vlastníci jednotky dostanou e-mail a upozornění, v části Moje vyúčtování převzetí potvrdí
 * (čas se zapíše) a mohou podat námitku. Správce vidí stav každé jednotky a lhůty.
 */

const MODF_OBJECTION_DAYS = 30;   // § 8 odst. 1
const MODF_ANSWER_DAYS = 30;      // § 8 odst. 2
const MODF_DELIVERY_MONTHS = 4;   // § 7 odst. 1
const MODF_ITEM_STATES = [
    'unmatched' => ['bez jednotky', 'status-new'],
    'draft' => ['připraveno', 'status-poll-planned'],
    'delivered' => ['doručeno', 'status-in_progress'],
    'confirmed' => ['potvrzeno', 'status-done'],
    'objection' => ['námitka', 'status-new'],
    'resolved' => ['námitka vyřízena', 'status-done'],
];

function modf_delivery_deadline(string $periodTo): string
{
    return modf_add_months($periodTo, MODF_DELIVERY_MONTHS);
}
function modf_objection_deadline(string $deliveredOn): string
{
    return modf_add_days($deliveredOn, MODF_OBJECTION_DAYS);
}
function modf_answer_deadline(string $objectionDate): string
{
    return modf_add_days($objectionDate, MODF_ANSWER_DAYS);
}

/** Kontrola údajů vyúčtování z formuláře: [data, chyby]. */
function modf_settlement_validate(array $in): array
{
    $d = ['title' => trim((string) ($in['title'] ?? '')), 'period_from' => trim((string) ($in['period_from'] ?? '')), 'period_to' => trim((string) ($in['period_to'] ?? '')),
        'delivery_date' => trim((string) ($in['delivery_date'] ?? '')), 'note' => trim((string) ($in['note'] ?? ''))];
    $err = [];
    if ($d['title'] === '') {
        $err[] = 'Vyplňte název (např. Vyúčtování služeb za rok 2026).';
    }
    $d['title'] = mb_substr($d['title'], 0, 191);
    $d['note'] = mb_substr($d['note'], 0, 2000);
    if (!valid_date($d['period_from']) || !valid_date($d['period_to'])) {
        $err[] = 'Zadejte začátek i konec zúčtovacího období.';
    } elseif ($d['period_from'] >= $d['period_to']) {
        $err[] = 'Konec zúčtovacího období musí být po jeho začátku.';
    } elseif ($d['period_to'] > modf_add_months($d['period_from'], 24)) {
        $err[] = 'Zúčtovací období může být nejvýš dva roky (obvykle je to kalendářní rok).';
    }
    if ($d['delivery_date'] !== '' && !valid_date($d['delivery_date'])) {
        $err[] = 'Den doručení není platné datum.';
    } elseif ($d['delivery_date'] !== '' && valid_date($d['period_to']) && $d['delivery_date'] < $d['period_to']) {
        $err[] = 'Vyúčtování nelze doručit dřív, než skončí zúčtovací období.';
    }
    return [$d, $err];
}
function modf_settlement_save(array $d, int $id = 0, int $by = 0): int
{
    modf_ready();
    if ($id) {
        q('UPDATE modf_settlements SET title = ?, period_from = ?, period_to = ?, delivery_date = ?, note = ? WHERE id = ?', [$d['title'], $d['period_from'], $d['period_to'], $d['delivery_date'], $d['note'], $id]);
        return $id;
    }
    q('INSERT INTO modf_settlements (title, period_from, period_to, delivery_date, published_at, note, created_at, created_by) VALUES (?,?,?,?,?,?,?,?)',
        [$d['title'], $d['period_from'], $d['period_to'], $d['delivery_date'], '', $d['note'], date('Y-m-d H:i:s'), $by]);
    return (int) db()->lastInsertId();
}
function modf_settlement(int $id): ?array
{
    modf_ready();
    return one('SELECT * FROM modf_settlements WHERE id = ?', [$id]);
}
/** Seznam vyúčtování s počty: jednotek, potvrzených, otevřených námitek. */
function modf_settlements(): array
{
    modf_ready();
    return all("SELECT s.*, (SELECT COUNT(*) FROM modf_settlement_items i WHERE i.settlement_id = s.id AND i.unit_id > 0) AS units,
        (SELECT COUNT(*) FROM modf_settlement_items i WHERE i.settlement_id = s.id AND i.unit_id = 0) AS unmatched,
        (SELECT COUNT(*) FROM modf_settlement_items i WHERE i.settlement_id = s.id AND i.confirmed_at <> '') AS confirmed,
        (SELECT COUNT(*) FROM modf_objections o JOIN modf_settlement_items i ON i.id = o.item_id WHERE i.settlement_id = s.id AND o.answered_at = '') AS open_objections
        FROM modf_settlements s ORDER BY s.period_to DESC, s.id DESC");
}

/** Jednotka podle názvu souboru: „A-5.pdf“, „12B10.pdf“, „vyuctovani_12A_3.pdf“; u domu bez vchodů stačí číslo. Nenajde-li se jednoznačně, null. */
function modf_match_unit(string $filename, array $units): ?array
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $byKey = [];
    $byNumber = [];
    foreach ($units as $un) {
        $byKey[mb_strtoupper((string) $un['entrance']) . '/' . $un['number']] = $un;
        if ((string) $un['entrance'] === '') {
            $byNumber[(string) $un['number']] = $un;
        }
    }
    $prefix = preg_quote(unit_prefix(), '~');
    if (preg_match_all('~(?<![\p{L}])(?:' . $prefix . ')?([\p{L}])[ _.\-/]?(\d{1,4})(?!\d)~u', $name, $mm, PREG_SET_ORDER)) {
        $hits = [];
        foreach ($mm as $m) {
            $k = mb_strtoupper($m[1]) . '/' . $m[2];
            if (isset($byKey[$k])) {
                $hits[(int) $byKey[$k]['id']] = $byKey[$k];
            }
        }
        if (count($hits) === 1) {
            return reset($hits);
        }
        if (count($hits) > 1) {
            return null;
        }
    }
    if ($byNumber && preg_match_all('~(?<!\d)(\d{1,4})(?!\d)~', $name, $mm)) {
        $hits = [];
        foreach ($mm[1] as $n) {
            $n = ltrim($n, '0') ?: '0';
            if (isset($byNumber[$n])) {
                $hits[(int) $byNumber[$n]['id']] = $byNumber[$n];
            }
        }
        return count($hits) === 1 ? reset($hits) : null;
    }
    return null;
}
/** Soubor vyúčtování musí být PDF. */
function modf_settlement_file_ok(string $name): bool
{
    return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf';
}
/**
 * Přidá uložený soubor ([filename, original_name, size] ze store_upload) k vyúčtování. Jednotka podle názvu souboru
 * (nebo $unitId). Je-li vyúčtování už zveřejněné a jednotka známá, rovnou se doručí. Vrací řádek položky.
 */
function modf_settlement_add_file(int $sid, array $stored, ?int $unitId = null): array
{
    modf_ready();
    if ($unitId === null) {
        $un = modf_match_unit((string) $stored[1], all_units());
        $unitId = $un ? (int) $un['id'] : 0;
    }
    q('INSERT INTO modf_settlement_items (settlement_id, unit_id, filename, original_name, size, uploaded_at) VALUES (?,?,?,?,?,?)',
        [$sid, $unitId, $stored[0], mb_substr((string) $stored[1], 0, 255), (int) $stored[2], date('Y-m-d H:i:s')]);
    $itemId = (int) db()->lastInsertId();
    $s = modf_settlement($sid);
    if ($unitId && $s && $s['published_at'] !== '') {
        modf_item_deliver($itemId, date('Y-m-d'));
    }
    return modf_item($itemId);
}
function modf_item(int $id): ?array
{
    modf_ready();
    return one('SELECT * FROM modf_settlement_items WHERE id = ?', [$id]);
}
function modf_items(int $sid): array
{
    modf_ready();
    return all('SELECT i.*, un.entrance, un.number FROM modf_settlement_items i LEFT JOIN units un ON un.id = i.unit_id WHERE i.settlement_id = ? ORDER BY i.unit_id = 0 DESC, un.entrance, un.number + 0, un.number, i.id', [$sid]);
}
/** Ruční přiřazení souboru k jednotce; u zveřejněného vyúčtování se tím soubor doručí. */
function modf_item_assign(int $itemId, int $unitId): ?string
{
    $it = modf_item($itemId);
    if (!$it) {
        return 'Soubor neexistuje.';
    }
    if ($it['delivered_on'] !== '') {
        return 'Soubor už byl vlastníkům doručen – jednotku u něj nelze měnit. Nahrajte opravený soubor.';
    }
    if (!one('SELECT id FROM units WHERE id = ?', [$unitId])) {
        return 'Vyberte jednotku.';
    }
    q('UPDATE modf_settlement_items SET unit_id = ? WHERE id = ?', [$unitId, $itemId]);
    $s = modf_settlement((int) $it['settlement_id']);
    if ($s && $s['published_at'] !== '') {
        modf_item_deliver($itemId, date('Y-m-d'));
    }
    return null;
}
/** Smazání souboru – jen dokud nebyl doručen (doručené vyúčtování je doklad). */
function modf_item_delete(int $itemId): ?string
{
    $it = modf_item($itemId);
    if (!$it) {
        return 'Soubor neexistuje.';
    }
    if ($it['delivered_on'] !== '') {
        return 'Doručené vyúčtování nelze smazat – slouží jako doklad o doručení.';
    }
    if (preg_match('/^[a-f0-9]{32}\.[a-z]+$/', $it['filename'])) {
        @unlink(UPLOAD_DIR . '/' . $it['filename']);
    }
    q('DELETE FROM modf_settlement_items WHERE id = ?', [$itemId]);
    return null;
}
/** Smazání celého vyúčtování – jen dokud není zveřejněné. */
function modf_settlement_delete(int $sid): ?string
{
    $s = modf_settlement($sid);
    if (!$s) {
        return 'Vyúčtování neexistuje.';
    }
    if ($s['published_at'] !== '') {
        return 'Zveřejněné vyúčtování nelze smazat – slouží jako doklad o doručení.';
    }
    foreach (modf_items($sid) as $it) {
        modf_item_delete((int) $it['id']);
    }
    q('DELETE FROM modf_settlements WHERE id = ?', [$sid]);
    return null;
}

/** Vlastníci jednotky s aktivním účtem (vyúčtování dostává vlastník, ne nájemník). */
function modf_unit_owners(int $unitId): array
{
    return all("SELECT u.id, u.name, u.email FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE uu.unit_id = ? AND u.active = 1 AND u.kind = 'owner' ORDER BY u.id", [$unitId]);
}
/** Doručení položky: zapíše den doručení a dá vědět vlastníkům (e-mail a telefon). */
function modf_item_deliver(int $itemId, string $on): void
{
    $it = modf_item($itemId);
    if (!$it || !(int) $it['unit_id'] || $it['delivered_on'] !== '') {
        return;
    }
    q('UPDATE modf_settlement_items SET delivered_on = ? WHERE id = ?', [$on, $itemId]);
    $s = modf_settlement((int) $it['settlement_id']);
    $un = one('SELECT * FROM units WHERE id = ?', [(int) $it['unit_id']]);
    $owners = modf_unit_owners((int) $it['unit_id']);
    $deadline = modf_objection_deadline($on);
    foreach ($owners as $o) {
        if (filter_var($o['email'], FILTER_VALIDATE_EMAIL)) {
            send_mail($o['email'], 'Vyúčtování služeb: ' . $s['title'] . ' – ' . site('name'),
                "Dobrý den,\n\nna webu " . site('name') . ' je pro Vaši jednotku ' . ($un ? unit_name($un) : '') . ' vyúčtování služeb za období ' . cz_date($s['period_from']) . ' – ' . cz_date($s['period_to']) . ".\n\n"
                . "Prosíme, vyúčtování si otevřete a potvrďte jeho převzetí tlačítkem „Potvrzuji převzetí“.\n"
                . 'Případnou námitku můžete podat na webu do ' . cz_date($deadline) . " (30 dnů od doručení).\n\n" . absolute_url('/vyuctovani'));
        }
    }
    if ($owners && function_exists('push_notify')) {
        push_notify(array_column($owners, 'id'), site('name'), 'Vyúčtování služeb je na webu – potvrďte prosím převzetí.', '/vyuctovani');
    }
}
/** Zveřejní vyúčtování: soubory přiřazené k jednotkám se doručí k zadanému dni. Vrací chybu, nebo počet doručených. */
function modf_settlement_publish(int $sid, string $deliveryDate, ?string $today = null): string|int
{
    $today ??= date('Y-m-d');
    $s = modf_settlement($sid);
    if (!$s) {
        return 'Vyúčtování neexistuje.';
    }
    if ($s['published_at'] !== '') {
        return 'Vyúčtování už je zveřejněné.';
    }
    if (!valid_date($deliveryDate)) {
        return 'Zadejte den doručení.';
    }
    if ($deliveryDate > $today) {
        return 'Den doručení nemůže být v budoucnu – zveřejněte vyúčtování až v ten den.';
    }
    if ($deliveryDate < $s['period_to']) {
        return 'Vyúčtování nelze doručit dřív, než skončí zúčtovací období.';
    }
    $items = array_filter(modf_items($sid), fn($i) => (int) $i['unit_id'] > 0);
    if (!$items) {
        return 'Nejdřív nahrajte soubory vyúčtování a přiřaďte je k jednotkám.';
    }
    q('UPDATE modf_settlements SET delivery_date = ?, published_at = ? WHERE id = ?', [$deliveryDate, date('Y-m-d H:i:s'), $sid]);
    foreach ($items as $it) {
        modf_item_deliver((int) $it['id'], $deliveryDate);
    }
    return count($items);
}

/** Položky zveřejněných vyúčtování pro jednotky, jejichž je uživatel vlastníkem. */
function modf_items_for_user(array $u): array
{
    modf_ready();
    if (($u['kind'] ?? '') !== 'owner') {
        return [];
    }
    return all("SELECT i.*, s.title, s.period_from, s.period_to, un.entrance, un.number FROM modf_settlement_items i JOIN modf_settlements s ON s.id = i.settlement_id
        JOIN units un ON un.id = i.unit_id JOIN user_units uu ON uu.unit_id = i.unit_id AND uu.user_id = ?
        WHERE s.published_at <> '' AND i.delivered_on <> '' ORDER BY s.period_to DESC, un.entrance, un.number + 0, i.id", [(int) $u['id']]);
}
/** Smí uživatel vyúčtování otevřít? Správce vždy, vlastník jen doručené vyúčtování své jednotky. */
function modf_can_view_item(array $u, array $item): bool
{
    return ($u['role'] ?? '') === 'admin' || modf_owns_item($u, (int) $item['id']);
}
/** Je položka doručeným vyúčtováním jednotky, kterou uživatel vlastní? (Potvrdit a podat námitku smí jen vlastník, ne správce za něj.) */
function modf_owns_item(array $u, int $itemId): bool
{
    return in_array($itemId, array_map('intval', array_column(modf_items_for_user($u), 'id')), true);
}
/** Vlastník potvrdí převzetí (zapíše se čas a kdo). Vrací false, když nesmí nebo už bylo potvrzeno. */
function modf_confirm(int $itemId, array $u): bool
{
    $it = modf_item($itemId);
    if (!$it || !modf_owns_item($u, $itemId) || $it['confirmed_at'] !== '') {
        return false;
    }
    q("UPDATE modf_settlement_items SET confirmed_at = ?, confirmed_by = ?, confirmed_name = ? WHERE id = ? AND confirmed_at = ''", [date('Y-m-d H:i:s'), (int) $u['id'], $u['name'], $itemId]);
    audit('Potvrzení převzetí vyúčtování', $u['name'] . ' · položka ' . $itemId);
    return true;
}
function modf_objections(array $itemIds): array
{
    modf_ready();
    $itemIds = array_values(array_filter(array_map('intval', $itemIds)));
    if (!$itemIds) {
        return [];
    }
    $out = [];
    foreach (all('SELECT * FROM modf_objections WHERE item_id IN (' . implode(',', $itemIds) . ') ORDER BY id') as $o) {
        $out[(int) $o['item_id']][] = $o;
    }
    return $out;
}
/** Lze ještě podat námitku? Do 30 dnů od doručení (včetně posledního dne). */
function modf_objection_open(array $item, ?string $today = null): bool
{
    $today ??= date('Y-m-d');
    return $item['delivered_on'] !== '' && $today <= modf_objection_deadline($item['delivered_on']);
}
/**
 * Námitka vlastníka: text (a příloha [filename, original_name, size]). Vrací chybu, nebo id námitky.
 * Správci dostanou e-mail a upozornění.
 */
function modf_objection_add(int $itemId, array $u, string $text, ?array $stored = null, ?string $today = null): string|int
{
    $it = modf_item($itemId);
    $text = trim($text);
    if (!$it || !modf_owns_item($u, $itemId)) {
        return 'Vyúčtování nenalezeno.';
    }
    if (!modf_objection_open($it, $today)) {
        return 'Lhůta pro námitky (30 dnů od doručení, do ' . cz_date(modf_objection_deadline((string) $it['delivered_on'])) . ') už uplynula. Napište prosím výboru přímo.';
    }
    if (mb_strlen($text) < 10) {
        return 'Popište prosím, s čím nesouhlasíte (alespoň pár slov).';
    }
    q('INSERT INTO modf_objections (item_id, user_id, user_name, text, filename, original_name, size, created_at, answer, answered_at, answered_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$itemId, (int) $u['id'], $u['name'], mb_substr($text, 0, 5000), $stored[0] ?? '', mb_substr((string) ($stored[1] ?? ''), 0, 255), (int) ($stored[2] ?? 0), date('Y-m-d H:i:s'), '', '', '']);
    $oid = (int) db()->lastInsertId();
    $s = modf_settlement((int) $it['settlement_id']);
    $un = one('SELECT * FROM units WHERE id = ?', [(int) $it['unit_id']]);
    audit('Námitka k vyúčtování', $s['title'] . ' · ' . ($un ? unit_name($un) : ''));
    modf_mail_admins('Námitka k vyúčtování ' . ($un ? unit_name($un) : ''), $u['name'] . ' podal(a) námitku k vyúčtování „' . $s['title'] . "“:\n\n" . mb_substr($text, 0, 2000)
        . "\n\nPodle zákona č. 67/2013 Sb. je třeba námitku vyřídit do " . cz_date(modf_answer_deadline(date('Y-m-d'))) . ' (30 dnů).', '/admin/vyuctovani/' . $s['id'] . '#n' . $oid);
    return $oid;
}
/** Vyřízení námitky správcem; vlastník dostane odpověď e-mailem a do telefonu. */
function modf_objection_answer(int $objectionId, array $admin, string $answer): ?string
{
    modf_ready();
    $o = one('SELECT * FROM modf_objections WHERE id = ?', [$objectionId]);
    $answer = trim($answer);
    if (!$o) {
        return 'Námitka neexistuje.';
    }
    if ($o['answered_at'] !== '') {
        return 'Námitka už je vyřízená.';
    }
    if (mb_strlen($answer) < 5) {
        return 'Napište, jak byla námitka vyřízena.';
    }
    q('UPDATE modf_objections SET answer = ?, answered_at = ?, answered_by = ? WHERE id = ?', [mb_substr($answer, 0, 5000), date('Y-m-d H:i:s'), $admin['name'], $objectionId]);
    $it = modf_item((int) $o['item_id']);
    $s = $it ? modf_settlement((int) $it['settlement_id']) : null;
    audit('Vyřízení námitky k vyúčtování', ($s['title'] ?? '') . ' · ' . $o['user_name']);
    $u = one('SELECT id, email FROM users WHERE id = ? AND active = 1', [(int) $o['user_id']]);
    if ($u) {
        if (filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
            send_mail($u['email'], 'Vyřízení námitky k vyúčtování – ' . site('name'), "Dobrý den,\n\nk Vaší námitce k vyúčtování „" . ($s['title'] ?? '') . "“ výbor odpověděl:\n\n" . $answer . "\n\n" . absolute_url('/vyuctovani'));
        }
        if (function_exists('push_notify')) {
            push_notify([(int) $u['id']], site('name'), 'Výbor vyřídil Vaši námitku k vyúčtování.', '/vyuctovani');
        }
    }
    return null;
}
/** Stav položky pro přehled správce. */
function modf_item_state(array $item, array $objections = []): string
{
    if (!(int) $item['unit_id']) {
        return 'unmatched';
    }
    if ($item['delivered_on'] === '') {
        return 'draft';
    }
    if ($objections) {
        return array_filter($objections, fn($o) => $o['answered_at'] === '') ? 'objection' : 'resolved';
    }
    return $item['confirmed_at'] !== '' ? 'confirmed' : 'delivered';
}
/** Přehled vyúčtování po jednotkách: řádky se stavem a lhůtami a souhrn počtů. */
function modf_settlement_overview(int $sid, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $items = modf_items($sid);
    $objs = modf_objections(array_column($items, 'id'));
    $rows = [];
    $sum = array_fill_keys(array_keys(MODF_ITEM_STATES), 0);
    foreach ($items as $it) {
        $o = $objs[(int) $it['id']] ?? [];
        $state = modf_item_state($it, $o);
        $sum[$state]++;
        $open = array_values(array_filter($o, fn($x) => $x['answered_at'] === ''));
        $answerBy = $open ? modf_answer_deadline(substr($open[0]['created_at'], 0, 10)) : '';
        $rows[] = $it + ['state' => $state, 'objections' => $o, 'unit_label' => (int) $it['unit_id'] && $it['number'] !== null ? unit_name($it) : '',
            'objection_until' => $it['delivered_on'] !== '' ? modf_objection_deadline($it['delivered_on']) : '', 'answer_until' => $answerBy, 'answer_late' => $answerBy !== '' && $answerBy < $today,
            'owners' => (int) $it['unit_id'] ? count(modf_unit_owners((int) $it['unit_id'])) : 0];
    }
    return ['rows' => $rows, 'sum' => $sum];
}
/** Jednotky, pro které ve vyúčtování chybí soubor. */
function modf_units_missing(int $sid): array
{
    $have = array_map('intval', array_column(all('SELECT DISTINCT unit_id FROM modf_settlement_items WHERE settlement_id = ? AND unit_id > 0', [$sid]), 'unit_id'));
    return array_values(array_filter(all_units(), fn($un) => !in_array((int) $un['id'], $have, true)));
}
/** Denně: připomínka nezveřejněného vyúčtování (14 dní před zákonnou lhůtou) a nevyřízených námitek (7 dní před lhůtou a po ní). */
function modf_settlement_reminders(string $today): int
{
    $n = 0;
    foreach (all("SELECT * FROM modf_settlements WHERE published_at = ''") as $s) {
        if (!valid_date($s['period_to'])) {
            continue;
        }
        $dl = modf_delivery_deadline($s['period_to']);
        if ($dl <= modf_add_days($today, 14) && $dl >= $today && modf_remind_once('settle-' . $s['id'] . '-' . $dl)) {
            $n += modf_mail_admins('Lhůta pro doručení vyúčtování', 'vyúčtování „' . $s['title'] . '“ ještě není zveřejněné. Podle § 7 zákona č. 67/2013 Sb. je třeba ho doručit do '
                . cz_date($dl) . ' (4 měsíce od konce zúčtovacího období).', '/admin/vyuctovani/' . $s['id']);
        }
    }
    foreach (all("SELECT o.*, i.settlement_id FROM modf_objections o JOIN modf_settlement_items i ON i.id = o.item_id WHERE o.answered_at = ''") as $o) {
        $dl = modf_answer_deadline(substr($o['created_at'], 0, 10));
        $stage = $dl < $today ? 'late' : ($dl <= modf_add_days($today, 7) ? 'soon' : '');
        if ($stage !== '' && modf_remind_once('objection-' . $o['id'] . '-' . $stage)) {
            $n += modf_mail_admins('Nevyřízená námitka k vyúčtování', 'námitku, kterou podal(a) ' . $o['user_name'] . ' ' . cz_date(substr($o['created_at'], 0, 10)) . ', je třeba vyřídit do '
                . cz_date($dl) . ($stage === 'late' ? ' – lhůta už uplynula.' : '.'), '/admin/vyuctovani/' . $o['settlement_id'] . '#n' . $o['id']);
        }
    }
    return $n;
}
