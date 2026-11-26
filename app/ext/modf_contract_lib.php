<?php
declare(strict_types=1);

/*
 * Smlouvy a pojištění: evidence smluv s dodavateli (odkaz na Dodavatele) a pojistných smluv, s dokumentem.
 * Hlídají se dva termíny: poslední den, kdy lze smlouvu s automatickým prodloužením vypovědět (konec smlouvy
 * minus výpovědní lhůta), a konec smlouvy. Správcům přijde e-mail (a upozornění do telefonu) zadaný počet dní předem
 * a znovu týden předem. Smlouva s automatickým prodloužením se po svém konci sama posune o další období.
 */

const MODF_CONTRACT_KINDS = ['contract' => 'Smlouva', 'insurance' => 'Pojištění'];
const MODF_NOTICE_UNITS = ['d' => 'dní', 'w' => 'týdnů', 'm' => 'měsíců'];
const MODF_PRICE_PERIODS = ['month' => 'měsíčně', 'quarter' => 'čtvrtletně', 'year' => 'ročně', 'once' => 'jednorázově'];
const MODF_REMIND_AGAIN = 7;   // druhá připomínka týden předem

/** Dodavatelé z evidence Dodavatelé (id => název); bez tabulky prázdné. */
function modf_suppliers(): array
{
    try {
        return array_column(all('SELECT id, name FROM suppliers ORDER BY name'), 'name', 'id');
    } catch (Throwable) {
        return [];
    }
}
function modf_contract_validate(array $in): array
{
    $price = modf_number((string) ($in['price'] ?? ''));
    $d = [
        'kind' => (string) ($in['kind'] ?? 'contract'), 'supplier_id' => (int) ($in['supplier_id'] ?? 0), 'party' => mb_substr(trim((string) ($in['party'] ?? '')), 0, 191),
        'subject' => mb_substr(trim((string) ($in['subject'] ?? '')), 0, 255), 'number' => mb_substr(trim((string) ($in['number'] ?? '')), 0, 80),
        'start_date' => trim((string) ($in['start_date'] ?? '')), 'end_date' => trim((string) ($in['end_date'] ?? '')),
        'notice_value' => (int) ($in['notice_value'] ?? 0), 'notice_unit' => (string) ($in['notice_unit'] ?? 'm'), 'auto_renew' => !empty($in['auto_renew']) ? 1 : 0,
        'renew_months' => (int) ($in['renew_months'] ?? 12), 'price' => $price ?? 0.0, 'price_period' => (string) ($in['price_period'] ?? 'year'),
        'remind_days' => (int) ($in['remind_days'] ?? 30), 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 3000),
    ];
    $err = [];
    if (!isset(MODF_CONTRACT_KINDS[$d['kind']])) {
        $err[] = 'Vyberte, zda jde o smlouvu, nebo pojištění.';
    }
    if ($d['subject'] === '') {
        $err[] = 'Vyplňte předmět smlouvy (např. Úklid společných prostor, Pojištění domu).';
    }
    if ($d['supplier_id'] && !isset(modf_suppliers()[$d['supplier_id']])) {
        $d['supplier_id'] = 0;
        $err[] = 'Vybraný dodavatel už neexistuje.';
    }
    if (!$d['supplier_id'] && $d['party'] === '') {
        $err[] = $d['kind'] === 'insurance' ? 'Vyplňte pojišťovnu.' : 'Vyberte dodavatele, nebo vyplňte druhou smluvní stranu.';
    }
    if ($d['start_date'] !== '' && !valid_date($d['start_date'])) {
        $err[] = 'Začátek smlouvy není platné datum.';
    }
    if ($d['end_date'] !== '' && !valid_date($d['end_date'])) {
        $err[] = 'Konec smlouvy není platné datum.';
    } elseif ($d['end_date'] !== '' && $d['start_date'] !== '' && valid_date($d['start_date']) && $d['end_date'] <= $d['start_date']) {
        $err[] = 'Konec smlouvy musí být po jejím začátku.';
    }
    if ($d['notice_value'] < 0 || $d['notice_value'] > 999 || !isset(MODF_NOTICE_UNITS[$d['notice_unit']])) {
        $err[] = 'Výpovědní lhůta musí být 0 až 999 dní, týdnů nebo měsíců.';
    }
    if ($d['auto_renew'] && $d['end_date'] === '') {
        $err[] = 'Smlouva s automatickým prodloužením musí mít datum konce (konec aktuálního období).';
    }
    if ($d['renew_months'] < 1 || $d['renew_months'] > 120) {
        $err[] = 'Prodloužení může být o 1 až 120 měsíců.';
    }
    if ($price === null && trim((string) ($in['price'] ?? '')) !== '' || $d['price'] < 0) {
        $err[] = 'Cenu zadejte jako číslo v korunách.';
    }
    if (!isset(MODF_PRICE_PERIODS[$d['price_period']])) {
        $d['price_period'] = 'year';
    }
    if ($d['remind_days'] < 1 || $d['remind_days'] > 365) {
        $err[] = 'Připomenout lze 1 až 365 dní předem.';
    }
    return [$d, $err];
}
function modf_contract_save(array $d, int $id = 0, ?array $file = null): int
{
    modf_ready();
    $cols = ['kind', 'supplier_id', 'party', 'subject', 'number', 'start_date', 'end_date', 'notice_value', 'notice_unit', 'auto_renew', 'renew_months', 'price', 'price_period', 'remind_days', 'note'];
    $vals = array_map(fn($c) => $d[$c], $cols);
    if ($id) {
        q('UPDATE modf_contracts SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?', [...$vals, $id]);
    } else {
        q('INSERT INTO modf_contracts (' . implode(', ', $cols) . ', filename, original_name, size, created_at) VALUES (' . str_repeat('?,', count($cols)) . "'', '', 0, ?)", [...$vals, date('Y-m-d H:i:s')]);
        $id = (int) db()->lastInsertId();
    }
    if ($file) {
        modf_contract_file_remove($id);
        q('UPDATE modf_contracts SET filename = ?, original_name = ?, size = ? WHERE id = ?', [$file[0], mb_substr((string) $file[1], 0, 255), (int) $file[2], $id]);
    }
    return $id;
}
function modf_contract(int $id): ?array
{
    modf_ready();
    return one('SELECT * FROM modf_contracts WHERE id = ?', [$id]);
}
function modf_contracts(string $kind = ''): array
{
    modf_ready();
    $rows = isset(MODF_CONTRACT_KINDS[$kind]) ? all('SELECT * FROM modf_contracts WHERE kind = ?', [$kind]) : all('SELECT * FROM modf_contracts');
    $sup = modf_suppliers();
    foreach ($rows as &$r) {
        $r['supplier'] = $sup[(int) $r['supplier_id']] ?? '';
        $r['events'] = modf_contract_events($r);
        $r['next'] = $r['events'][0]['date'] ?? '9999-12-31';
    }
    unset($r);
    usort($rows, fn($a, $b) => [$a['next'], $a['subject']] <=> [$b['next'], $b['subject']]);
    return $rows;
}
function modf_contract_file_remove(int $id): void
{
    $c = one('SELECT filename FROM modf_contracts WHERE id = ?', [$id]);
    if ($c && preg_match('/^[a-f0-9]{32}\.[a-z]+$/', (string) $c['filename'])) {
        @unlink(UPLOAD_DIR . '/' . $c['filename']);
    }
    q("UPDATE modf_contracts SET filename = '', original_name = '', size = 0 WHERE id = ?", [$id]);
}
function modf_contract_delete(int $id): void
{
    modf_contract_file_remove($id);
    q('DELETE FROM modf_contracts WHERE id = ?', [$id]);
    q('DELETE FROM modf_reminders WHERE rkey LIKE ?', ['contract-' . $id . '-%']);
}
/** Název smlouvy do seznamů a připomínek („Úklid – Čistota s.r.o.“). */
function modf_contract_title(array $c): string
{
    $party = $c['supplier'] ?? '';
    if ($party === '' && (int) $c['supplier_id']) {
        $party = modf_suppliers()[(int) $c['supplier_id']] ?? '';
    }
    $party = $party !== '' ? $party : (string) $c['party'];
    return $c['subject'] . ($party !== '' ? ' – ' . $party : '');
}
/** Poslední den, kdy je třeba doručit výpověď, aby se smlouva neprodloužila (jen smlouvy s automatickým prodloužením). */
function modf_notice_deadline(array $c): ?string
{
    if (!(int) $c['auto_renew'] || !valid_date((string) $c['end_date']) || (int) $c['notice_value'] <= 0) {
        return null;
    }
    $n = (int) $c['notice_value'];
    return match ($c['notice_unit']) {
        'm' => modf_add_months($c['end_date'], -$n),
        'w' => modf_add_days($c['end_date'], -7 * $n),
        default => modf_add_days($c['end_date'], -$n),
    };
}
/** Hlídané termíny smlouvy seřazené podle data: [['date', 'kind' => notice|end, 'label'], …]. */
function modf_contract_events(array $c): array
{
    $out = [];
    if ($nd = modf_notice_deadline($c)) {
        $out[] = ['date' => $nd, 'kind' => 'notice', 'label' => 'Poslední den pro výpověď'];
    }
    if (valid_date((string) $c['end_date'])) {
        $out[] = ['date' => $c['end_date'], 'kind' => 'end', 'label' => (int) $c['auto_renew'] ? 'Automatické prodloužení' : ($c['kind'] === 'insurance' ? 'Konec pojištění' : 'Konec smlouvy')];
    }
    usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
}
/** Smlouvy s automatickým prodloužením, jejichž období skončilo, se posunou o další období. Vrací počet. */
function modf_contracts_roll(string $today): int
{
    modf_ready();
    $n = 0;
    foreach (all("SELECT * FROM modf_contracts WHERE auto_renew = 1 AND end_date <> '' AND end_date < ?", [$today]) as $c) {
        $end = $c['end_date'];
        $step = max(1, (int) $c['renew_months']);
        for ($i = 0; $end < $today && $i < 1000; $i++) {
            $end = modf_add_months($end, $step);
        }
        q('UPDATE modf_contracts SET end_date = ? WHERE id = ?', [$end, (int) $c['id']]);
        audit('Automatické prodloužení smlouvy', modf_contract_title($c) . ' · do ' . cz_date($end));
        $n++;
    }
    return $n;
}
/** Připomínky termínů smluv: X dní předem (podle smlouvy) a znovu týden předem; každá jen jednou. Vrací počet odeslaných e-mailů. */
function modf_contract_reminders(string $today): int
{
    modf_ready();
    $sent = 0;
    foreach (all('SELECT * FROM modf_contracts') as $c) {
        foreach (modf_contract_events($c) as $ev) {
            if ($ev['date'] < $today) {
                continue;
            }
            $days = (int) round((strtotime($ev['date']) - strtotime($today)) / 86400);
            $stage = $days <= MODF_REMIND_AGAIN ? 2 : ($days <= (int) $c['remind_days'] ? 1 : 0);
            $key = 'contract-' . $c['id'] . '-' . $ev['kind'] . '-' . $ev['date'] . '-';
            if (!$stage || !modf_remind_once($key . $stage)) {
                continue;
            }
            if ($stage === 2) {
                modf_remind_once($key . '1');   // první připomínka už nemá smysl
            }
            $what = $ev['kind'] === 'notice'
                ? 'smlouvu „' . modf_contract_title($c) . '“ lze vypovědět nejpozději ' . cz_date($ev['date']) . ' (výpovědní lhůta ' . (int) $c['notice_value'] . ' ' . MODF_NOTICE_UNITS[$c['notice_unit']] . '). Jinak se ' . cz_date($c['end_date']) . ' automaticky prodlouží.'
                : 'smlouva „' . modf_contract_title($c) . '“ ' . ((int) $c['auto_renew'] ? 'se ' . cz_date($ev['date']) . ' automaticky prodlouží.' : 'končí ' . cz_date($ev['date']) . '. Zajistěte včas novou smlouvu nebo pojištění.');
            $sent += modf_mail_admins($ev['label'] . ($days === 0 ? ' dnes' : ' za ' . $days . ' ' . ($days === 1 ? 'den' : ($days >= 2 && $days <= 4 ? 'dny' : 'dní'))) . ': ' . $c['subject'], $what, '/admin/smlouvy/' . $c['id']);
        }
    }
    return $sent;
}
