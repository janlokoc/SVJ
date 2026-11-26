<?php
declare(strict_types=1);

/*
 * Plán oprav a fond oprav (dlouhodobá záloha na správu domu a pozemku): seznam plánovaných oprav, stav fondu, měsíční
 * příspěvky (rozpočítané podle spoluvlastnických podílů, nebo stejně na každou jednotku) a výhled zůstatku na 10–20 let.
 * Roky, kdy by fond šel do mínusu, se zvýrazní a web spočítá, o kolik by bylo třeba zvýšit měsíční příspěvky.
 * Nastavení fondu je v tabulce settings (klíče modf_fund_*), opravy v tabulce modf_repairs.
 */

const MODF_REPAIR_STATUS = ['planned' => 'Plánováno', 'approved' => 'Schváleno', 'in_progress' => 'Probíhá', 'postponed' => 'Odloženo', 'done' => 'Hotovo'];
const MODF_PRIORITY = [1 => 'Vysoká', 2 => 'Střední', 3 => 'Nízká'];
const MODF_FUND_MODES = ['shares' => 'Celková částka za dům, rozpočítaná podle podílů', 'flat' => 'Stejná částka za každou jednotku'];
const MODF_FUND_KEYS = ['balance', 'balance_date', 'mode', 'monthly', 'years', 'inflation', 'published'];

function modf_fund_settings(): array
{
    $years = (int) setting('modf_fund_years', '15');
    return [
        'balance' => (float) setting('modf_fund_balance', '0'),
        'balance_date' => valid_date(setting('modf_fund_balance_date')) ? setting('modf_fund_balance_date') : '',
        'mode' => isset(MODF_FUND_MODES[setting('modf_fund_mode')]) ? setting('modf_fund_mode') : 'shares',
        'monthly' => (float) setting('modf_fund_monthly', '0'),
        'years' => max(10, min(20, $years)),
        'inflation' => max(0.0, min(15.0, (float) setting('modf_fund_inflation', '0'))),
        'published' => setting('modf_fund_published') === '1',
    ];
}
/** Kontrola nastavení fondu z formuláře: [data, chyby]. */
function modf_fund_validate(array $in): array
{
    $err = [];
    $balance = modf_number((string) ($in['balance'] ?? ''));
    $monthly = modf_number((string) ($in['monthly'] ?? ''));
    $infl = modf_number((string) ($in['inflation'] ?? '0'));
    $d = ['balance' => $balance ?? 0.0, 'balance_date' => trim((string) ($in['balance_date'] ?? '')), 'mode' => (string) ($in['mode'] ?? ''), 'monthly' => $monthly ?? 0.0,
        'years' => (int) ($in['years'] ?? 15), 'inflation' => $infl ?? 0.0, 'published' => !empty($in['published'])];
    if ($balance === null) {
        $err[] = 'Zadejte stav fondu oprav (číslo v korunách, může být i záporné).';
    }
    if (!valid_date($d['balance_date'])) {
        $err[] = 'Zadejte, ke kterému dni stav fondu platí.';
    }
    if (!isset(MODF_FUND_MODES[$d['mode']])) {
        $err[] = 'Vyberte, jak se příspěvky do fondu počítají.';
    }
    if ($monthly === null || $monthly < 0) {
        $err[] = 'Měsíční příspěvek musí být číslo 0 nebo vyšší.';
    }
    if ($d['years'] < 10 || $d['years'] > 20) {
        $err[] = 'Výhled může být na 10 až 20 let.';
    }
    if ($infl === null || $infl < 0 || $infl > 15) {
        $err[] = 'Růst cen zadejte v procentech ročně (0 až 15).';
    }
    return [$d, $err];
}
function modf_fund_save(array $d): void
{
    foreach (MODF_FUND_KEYS as $k) {
        $v = $d[$k];
        $v = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        q('DELETE FROM settings WHERE `key` = ?', ['modf_fund_' . $k]);
        q('INSERT INTO settings (`key`, value) VALUES (?,?)', ['modf_fund_' . $k, $v]);
    }
    setting('__reset');
}
/** Měsíční příspěvek celého domu. */
function modf_fund_monthly_total(array $s, int $unitCount): float
{
    return $s['mode'] === 'flat' ? $s['monthly'] * $unitCount : $s['monthly'];
}
/** Měsíční příspěvek každé jednotky: [[unit, podíl (0–1), částka], …]. Chybí-li některé jednotce podíl, dělí se rovným dílem. */
function modf_unit_contributions(array $s, array $units, ?float $total = null): array
{
    $total ??= modf_fund_monthly_total($s, count($units));
    if (!$units) {
        return [];
    }
    $shares = array_map(fn($un) => share_value((string) ($un['share'] ?? '')), $units);
    $equal = $s['mode'] === 'flat' || in_array(0.0, $shares, true);
    $sum = array_sum($shares);
    $out = [];
    foreach (array_values($units) as $i => $un) {
        $w = $equal || $sum <= 0 ? 1 / count($units) : $shares[$i] / $sum;
        $out[] = ['unit' => $un, 'share' => $w, 'amount' => round($total * $w, 2)];
    }
    return $out;
}

function modf_repair_validate(array $in, ?int $thisYear = null): array
{
    $thisYear ??= (int) date('Y');
    $cost = modf_number((string) ($in['cost'] ?? ''));
    $d = ['title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 191), 'year' => (int) ($in['year'] ?? 0), 'cost' => $cost === null ? 0 : (int) round($cost),
        'priority' => (int) ($in['priority'] ?? 2), 'status' => (string) ($in['status'] ?? 'planned'), 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 2000)];
    $err = [];
    if ($d['title'] === '') {
        $err[] = 'Vyplňte, o jakou opravu jde.';
    }
    if ($d['year'] < $thisYear - 30 || $d['year'] > $thisYear + 50) {
        $err[] = 'Zadejte rok opravy (' . ($thisYear - 30) . '–' . ($thisYear + 50) . ').';
    }
    if ($cost === null || $cost < 0 || $cost > 1e10) {
        $err[] = 'Zadejte odhad nákladů v korunách.';
    }
    if (!isset(MODF_PRIORITY[$d['priority']])) {
        $err[] = 'Vyberte prioritu.';
    }
    if (!isset(MODF_REPAIR_STATUS[$d['status']])) {
        $err[] = 'Vyberte stav.';
    }
    return [$d, $err];
}
function modf_repair_save(array $d, int $id = 0): int
{
    modf_ready();
    if ($id) {
        q('UPDATE modf_repairs SET title = ?, year = ?, cost = ?, priority = ?, status = ?, note = ? WHERE id = ?', [$d['title'], $d['year'], $d['cost'], $d['priority'], $d['status'], $d['note'], $id]);
        return $id;
    }
    q('INSERT INTO modf_repairs (title, year, cost, priority, status, note, created_at) VALUES (?,?,?,?,?,?,?)', [$d['title'], $d['year'], $d['cost'], $d['priority'], $d['status'], $d['note'], date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}
function modf_repairs(): array
{
    modf_ready();
    return all("SELECT * FROM modf_repairs ORDER BY status = 'done', year, priority, id");
}

/**
 * Výhled fondu po letech. První rok začíná stavem fondu k zadanému dni a příspěvky za zbývající měsíce roku;
 * opravy z minulých let, které nejsou hotové, se počítají do prvního roku. Náklady rostou o zadaný růst cen ročně.
 * Vrací ['years' => [[year, start, income, cost, end, negative], …], 'monthly' => současný příspěvek domu,
 *  'needed' => měsíční příspěvek, se kterým fond nikdy neklesne pod nulu (null = nelze, chybí peníze hned v prvním roce), 'increase', 'first_negative', 'beyond' => opravy za horizontem].
 */
function modf_fund_projection(array $s, array $repairs, int $unitCount, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $from = $s['balance_date'] !== '' ? $s['balance_date'] : $today;
    $start = (int) substr($from, 0, 4);
    $firstMonths = 12 - (int) substr($from, 5, 2);
    $monthly = modf_fund_monthly_total($s, $unitCount);
    $last = $start + $s['years'] - 1;
    $costs = array_fill($start, $s['years'], 0.0);
    $beyond = [];
    foreach ($repairs as $r) {
        if ($r['status'] === 'done') {
            continue;
        }
        $y = max($start, (int) $r['year']);
        if ($y > $last) {
            $beyond[] = $r;
            continue;
        }
        $costs[$y] += (float) $r['cost'] * (1 + $s['inflation'] / 100) ** ($y - $start);
    }
    $years = [];
    $bal = (float) $s['balance'];
    $cumMonths = 0;
    $cumCost = 0.0;
    $needed = 0.0;
    $possible = true;
    $firstNeg = null;
    foreach ($costs as $y => $cost) {
        $months = $y === $start ? $firstMonths : 12;
        $income = $monthly * $months;
        $end = $bal + $income - $cost;
        $years[] = ['year' => $y, 'start' => round($bal, 2), 'income' => round($income, 2), 'cost' => round($cost, 2), 'end' => round($end, 2), 'negative' => $end < -0.005];
        if ($end < -0.005 && $firstNeg === null) {
            $firstNeg = $y;
        }
        $cumMonths += $months;
        $cumCost += $cost;
        $gap = $cumCost - (float) $s['balance'];
        if ($gap > 0.005) {
            if ($cumMonths === 0) {
                $possible = false;
            } else {
                $needed = max($needed, $gap / $cumMonths);
            }
        }
        $bal = $end;
    }
    $neededTotal = $possible ? (float) ceil(round($needed, 4)) : null;
    return ['years' => $years, 'monthly' => $monthly, 'needed' => $neededTotal, 'first_negative' => $firstNeg, 'beyond' => $beyond,
        'increase' => $neededTotal === null || $firstNeg === null ? 0.0 : max(0.0, $neededTotal - $monthly),
        'increase_unit' => $neededTotal === null || $firstNeg === null || !$unitCount ? 0.0 : (float) ceil(max(0.0, $neededTotal - $monthly) / $unitCount)];
}
/**
 * Rozměry sloupců grafu v procentech výšky (styl nastaví app.js z data-b a data-h – CSP nepovoluje styl ve stránce).
 * Nulová osa je tam, kde by byla nula; záporný zůstatek jde pod ni.
 */
function modf_fund_chart(array $proj): array
{
    $ends = array_column($proj['years'], 'end');
    $costs = array_column($proj['years'], 'cost');
    $top = max(1.0, $ends ? max($ends) : 0, $costs ? max($costs) : 0);
    $bottom = min(0.0, $ends ? min($ends) : 0);
    $range = $top - $bottom;
    $zero = round(-$bottom / $range * 100, 2);
    $cols = [];
    foreach ($proj['years'] as $y) {
        $h = abs($y['end']) / $range * 100;
        $cols[] = $y + ['cost_b' => $zero, 'cost_h' => round($y['cost'] / $range * 100, 2), 'bal_b' => round($y['end'] >= 0 ? $zero : $zero - $h, 2), 'bal_h' => round($h, 2)];
    }
    return ['zero' => $zero, 'top' => $top, 'bottom' => $bottom, 'cols' => $cols];
}
