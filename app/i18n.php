<?php
declare(strict_types=1);

// Jazyky webu. Texty v šablonách jsou česky; pro jiný jazyk se hotová stránka před odesláním přeloží podle slovníku
// (app/lang/<jazyk>.php: český text → překlad). Co ve slovníku není – typicky obsah vložený výborem a obyvateli – zůstává beze změny.
// Administrace a konzole platformy se nepřekládají.

function current_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    if (function_exists('plan_has') && !plan_has('jazyky')) {
        return $lang = 'cs';
    }
    $choice = (string) ($_COOKIE['svj_lang'] ?? '');
    return $lang = isset(LANGS[$choice]) ? $choice : (isset(LANGS[site('lang')]) ? site('lang') : 'cs');
}
function lang_dictionary(string $lang): array
{
    static $cache = [];
    if (!isset($cache[$lang])) {
        $file = __DIR__ . '/lang/' . $lang . '.php';
        $cache[$lang] = preg_match('/^[a-z]{2}$/', $lang) && is_file($file) ? (array) require $file : [];
    }
    return $cache[$lang];
}
/** Přeloží jeden text; '#' ve slovníku zastupuje číslo, '{}' libovolný vložený údaj (jméno, označení jednotky…). */
function t(string $text, ?string $lang = null): string
{
    $lang ??= current_lang();
    if ($lang === 'cs') {
        return $text;
    }
    $dict = lang_dictionary($lang);
    $key = trim(preg_replace('/\s+/u', ' ', $text));
    if ($key === '' || !preg_match('/\p{L}/u', $key)) {
        return $text;
    }
    $found = $dict[$key] ?? null;
    if ($found === null) {
        // Text s čísly: ve slovníku je s # místo čísel.
        $numbers = [];
        $pattern = preg_replace_callback('/\d+(?:[.,]\d+)*/', function ($m) use (&$numbers) {
            $numbers[] = $m[0];
            return '#';
        }, $key);
        if ($numbers && isset($dict[$pattern])) {
            $i = 0;
            $found = preg_replace_callback('/#/', function () use ($numbers, &$i) {
                return $numbers[$i++] ?? '';
            }, $dict[$pattern]);
        }
    }
    if ($found === null) {
        // Text s vloženým údajem: ve slovníku je s {} na jeho místě.
        static $wild = [];
        if (!isset($wild[$lang])) {
            $wild[$lang] = [];
            // Delší (konkrétnější) vzory dřív: „Jednotka {}. Kód jsme poslali jako {}.“ musí vyhrát nad „Jednotka {}“.
            $keys = array_filter(array_keys($dict), fn($k) => str_contains((string) $k, '{}'));
            usort($keys, fn($a, $b) => mb_strlen(str_replace('{}', '', $b)) <=> mb_strlen(str_replace('{}', '', $a)));
            foreach ($keys as $k) {
                $wild[$lang]['~^' . str_replace('\{\}', '(.+?)', preg_quote($k, '~')) . '$~u'] = $dict[$k];
            }
        }
        foreach ($wild[$lang] as $regex => $v) {
            if (preg_match($regex, $key, $m)) {
                $i = 1;
                $found = preg_replace_callback('/\{\}/', function () use ($m, &$i) {
                    return $m[$i++] ?? '';
                }, $v);
                break;
            }
        }
    }
    if ($found === null) {
        return $text;
    }
    // Zachovat mezery okolo, aby se nespojila slova mezi značkami.
    preg_match('/^(\s*).*?(\s*)$/us', $text, $ws);
    return ($ws[1] === '' ? '' : ' ') . $found . ($ws[2] === '' ? '' : ' ');
}
/** Přeloží texty a vybrané atributy v hotové stránce. */
function translate_html(string $html, string $lang): string
{
    if ($lang === 'cs' || !lang_dictionary($lang)) {
        return $html;
    }
    $html = preg_replace_callback('~(\s(?:placeholder|aria-label|title|alt|data-confirm|data-label|data-tip)=")([^"]*)(")~u',
        fn($m) => $m[1] . htmlspecialchars(t(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $lang), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[3], $html);
    $html = preg_replace_callback('~(<title>)([^<]*)(</title>)~u', function ($m) use ($lang) {
        $parts = explode(' · ', html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 2);
        $parts[0] = t($parts[0], $lang);
        return $m[1] . htmlspecialchars(implode(' · ', $parts), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[3];
    }, $html);
    return preg_replace_callback('~(>)([^<>]+)(<)~u', function ($m) use ($lang) {
        $plain = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = t($plain, $lang);
        return $out === $plain ? $m[0] : $m[1] . htmlspecialchars($out, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[3];
    }, $html);
}
/** Má se tato stránka překládat? (jen veřejná část a část pro obyvatele) */
function page_translatable(): bool
{
    return empty($GLOBALS['IS_PLATFORM']) && !str_starts_with((string) ($GLOBALS['PATH'] ?? ''), '/admin');
}

const MONTHS_I18N = [
    'sk' => ['', 'januára', 'februára', 'marca', 'apríla', 'mája', 'júna', 'júla', 'augusta', 'septembra', 'októbra', 'novembra', 'decembra'],
    'en' => ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    'vi' => ['', 'tháng 1', 'tháng 2', 'tháng 3', 'tháng 4', 'tháng 5', 'tháng 6', 'tháng 7', 'tháng 8', 'tháng 9', 'tháng 10', 'tháng 11', 'tháng 12'],
    'uk' => ['', 'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'],
];
const MONTHS_SHORT_I18N = [
    'sk' => ['', 'jan', 'feb', 'mar', 'apr', 'máj', 'jún', 'júl', 'aug', 'sep', 'okt', 'nov', 'dec'],
    'en' => ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    'vi' => ['', 'Th1', 'Th2', 'Th3', 'Th4', 'Th5', 'Th6', 'Th7', 'Th8', 'Th9', 'Th10', 'Th11', 'Th12'],
    'uk' => ['', 'січ', 'лют', 'бер', 'кві', 'тра', 'чер', 'лип', 'сер', 'вер', 'жов', 'лис', 'гру'],
];
const WEEKDAYS_I18N = [
    'sk' => ['nedeľa', 'pondelok', 'utorok', 'streda', 'štvrtok', 'piatok', 'sobota'],
    'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    'vi' => ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'],
    'uk' => ['неділя', 'понеділок', 'вівторок', 'середа', 'четвер', 'п’ятниця', 'субота'],
];
function month_name(int $n, bool $short = false): string
{
    $lang = page_translatable() ? current_lang() : 'cs';
    return $short ? (MONTHS_SHORT_I18N[$lang][$n] ?? MONTHS_SHORT[$n]) : (MONTHS_I18N[$lang][$n] ?? MONTHS[$n]);
}
function weekday_name(int $w): string
{
    $lang = page_translatable() ? current_lang() : 'cs';
    return WEEKDAYS_I18N[$lang][$w] ?? WEEKDAYS[$w];
}
