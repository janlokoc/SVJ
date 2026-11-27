<?php
declare(strict_types=1);

/*
 * Příchozí e-maily: rozbor zprávy MIME a vytažení vlastního textu odpovědi (bez citace předchozí zprávy a podpisu).
 * Používá app/ext/inbound_lib.php. Nic neodesílá, nesahá do databáze – jen převádí text zprávy.
 *
 * - inbound_mime_parse($raw): ['headers' => [jméno malými => [hodnoty]], 'from', 'subject', 'text', 'html', 'attachments' => [[name, type, data, inline]]]
 * - inbound_reply_text($text): jen nově napsaný text (citace „>“, „Dne … napsal(a):“, „On … wrote:“, bloky Od:/From: z Outlooku,
 *   „Původní e-mail“ ze Seznamu, podpis za „-- “ a „Odesláno z iPhonu“ se odříznou).
 * - inbound_html_text($html): HTML → prostý text (citace v <blockquote> a bloky gmail_quote / Outlook se vynechají).
 */

/** Nejvýš tolik částí MIME se projde (ochrana před zprávou s tisíci vnořených částí). */
const INBOUND_MIME_MAX_PARTS = 60;

/** Rozdělí zprávu na hlavičky a tělo; hlavičky [jméno malými => [nerozložené hodnoty]]. */
function inbound_mime_split(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $pos = strpos($raw, "\n\n");
    $head = $pos === false ? $raw : substr($raw, 0, $pos);
    $body = $pos === false ? '' : substr($raw, $pos + 2);
    // Pokud zpráva začíná prázdným řádkem, nemá hlavičky.
    if (str_starts_with($raw, "\n")) {
        $head = '';
        $body = substr($raw, 1);
    }
    $headers = [];
    $head = preg_replace("/\n[ \t]+/", ' ', $head);   // pokračovací řádky
    foreach (explode("\n", $head) as $line) {
        if (preg_match('/^([!-9;-~]+):[ \t]*(.*)$/', $line, $m)) {
            $headers[strtolower($m[1])][] = trim($m[2]);
        }
    }
    return [$headers, $body];
}
/** Hodnota hlavičky s rozkódovanými slovy =?UTF-8?B?…?= / =?windows-1250?Q?…?=, převedená do UTF-8. */
function inbound_header_decode(string $v): string
{
    if (str_contains($v, '=?')) {
        // Mezery mezi dvěma kódovanými slovy se podle RFC 2047 vynechávají.
        $v = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $v);
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d === false || $d === '') {
            $d = @mb_decode_mimeheader($v);
        }
        $v = (string) $d;
    }
    return inbound_utf8($v, '');
}
/** Převede text z uvedené znakové sady do UTF-8; neplatné znaky nahradí. Bez sady: UTF-8, jinak windows-1250. */
function inbound_utf8(string $s, string $charset): string
{
    $cs = strtolower(trim($charset, " \"'"));
    $cs = ['cp1250' => 'windows-1250', 'win-1250' => 'windows-1250', 'x-cp1250' => 'windows-1250', 'latin2' => 'iso-8859-2', 'iso8859-2' => 'iso-8859-2',
        'utf8' => 'utf-8', 'us-ascii' => 'utf-8', 'ascii' => 'utf-8', 'ks_c_5601-1987' => 'cp949'][$cs] ?? $cs;
    if ($cs === '' || $cs === 'utf-8') {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $cs = $cs === '' ? 'windows-1250' : $cs;   // české poštovní programy bez uvedené sady posílají nejčastěji windows-1250
        if ($cs === 'utf-8') {
            return mb_scrub($s, 'UTF-8');
        }
    }
    $out = @iconv($cs, 'UTF-8//IGNORE', $s);
    if ($out === false) {
        try {
            $out = mb_convert_encoding($s, 'UTF-8', $cs);
        } catch (Throwable $e) {
            $out = mb_scrub($s, 'UTF-8');
        }
    }
    return mb_scrub((string) $out, 'UTF-8');
}
/** Hodnota hlavičky typu Content-Type rozložená na [hodnota malými, [parametr => hodnota]] (i RFC 2231: filename*=UTF-8''…). */
function inbound_header_params(string $v): array
{
    $parts = [];
    // Středníky uvnitř uvozovek nedělí.
    preg_match_all('/((?:"(?:[^"\\\\]|\\\\.)*"|[^;"])+)/', $v, $m);
    $parts = array_map('trim', $m[1] ?? []);
    $main = strtolower((string) array_shift($parts));
    $params = [];
    $cont = [];
    foreach ($parts as $p) {
        if (!preg_match('/^([^=\s]+)\s*=\s*(.*)$/s', $p, $pm)) {
            continue;
        }
        $k = strtolower($pm[1]);
        $val = trim($pm[2]);
        if (strlen($val) >= 2 && $val[0] === '"' && str_ends_with($val, '"')) {
            $val = stripcslashes(substr($val, 1, -1));
        }
        if (preg_match('/^([a-z0-9_-]+)\*(\d+)?(\*)?$/', $k, $km)) {
            // RFC 2231: name*=charset'lang'%C4%8D…, případně rozdělené name*0*=…; name*1*=…
            $cont[$km[1]][(int) ($km[2] ?? 0)] = [$val, isset($km[3]) || ($km[2] ?? '') === ''];
            continue;
        }
        $params[$k] = inbound_header_decode($val);
    }
    foreach ($cont as $k => $pieces) {
        ksort($pieces);
        $charset = '';
        $joined = '';
        foreach ($pieces as $i => [$val, $encoded]) {
            if ($encoded && $i === array_key_first($pieces) && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $em)) {
                $charset = $em[1];
                $val = $em[2];
            }
            $joined .= $encoded ? rawurldecode($val) : $val;
        }
        $params[$k] = inbound_utf8($joined, $charset !== '' ? $charset : 'utf-8');
    }
    return [$main, $params];
}
/** Rozkóduje tělo části podle Content-Transfer-Encoding. */
function inbound_transfer_decode(string $body, string $enc): string
{
    return match (strtolower(trim($enc))) {
        'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body)),
        'quoted-printable' => quoted_printable_decode(str_replace("\n", "\r\n", $body)),
        default => $body,
    };
}
/** E-mailová adresa z hlavičky From/To (první), malými písmeny; '' když žádná není. */
function inbound_address(string $v): string
{
    if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $v, $m) || preg_match('/([A-Za-z0-9._%+\-=\/!#$&\'*?^`{|}~]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $v, $m)) {
        $a = strtolower(trim($m[1]));
        return filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : '';
    }
    return '';
}
/**
 * Rozebere celou zprávu. Text: první část text/plain (mimo přílohy); není-li, převede se první text/html.
 * Přílohy: části se jménem souboru nebo s Content-Disposition: attachment (vnořené zprávy message/rfc822 se neberou).
 */
function inbound_mime_parse(string $raw): array
{
    [$headers, $body] = inbound_mime_split($raw);
    $msg = ['headers' => $headers, 'from' => inbound_address(inbound_header_decode($headers['from'][0] ?? '')),
        'subject' => inbound_header_decode($headers['subject'][0] ?? ''), 'text' => null, 'html' => null, 'attachments' => [], 'types' => []];
    $count = 0;
    inbound_mime_walk($headers, $body, $msg, 0, $count);
    $text = $msg['text'] ?? ($msg['html'] !== null ? inbound_html_text($msg['html']) : '');
    $msg['text'] = trim(str_replace(["\r\n", "\r"], "\n", $text));
    return $msg;
}
function inbound_mime_walk(array $headers, string $body, array &$msg, int $depth, int &$count): void
{
    if (++$count > INBOUND_MIME_MAX_PARTS || $depth > 8) {
        return;
    }
    [$type, $params] = inbound_header_params($headers['content-type'][0] ?? 'text/plain; charset=us-ascii');
    $type = $type !== '' ? $type : 'text/plain';
    $msg['types'][] = $type;
    if (str_starts_with($type, 'multipart/')) {
        $b = (string) ($params['boundary'] ?? '');
        if ($b === '') {
            return;
        }
        $chunks = preg_split('/^--' . preg_quote($b, '/') . '(?:--)?[ \t]*$/m', $body);
        array_shift($chunks);   // text před první hranicí (preambule)
        array_pop($chunks);     // za poslední hranicí (epilog), případně neukončená část
        if ($chunks === [] && str_contains($body, '--' . $b)) {
            $chunks = array_slice(preg_split('/^--' . preg_quote($b, '/') . '[ \t]*$/m', $body), 1);
        }
        foreach ($chunks as $chunk) {
            $chunk = preg_replace('/^\n/', '', $chunk);
            [$h, $bd] = inbound_mime_split($chunk);
            inbound_mime_walk($h, (string) preg_replace('/\n$/', '', $bd), $msg, $depth + 1, $count);
        }
        return;
    }
    [$disp, $dparams] = inbound_header_params($headers['content-disposition'][0] ?? '');
    $name = (string) ($dparams['filename'] ?? $params['name'] ?? '');
    $data = inbound_transfer_decode($body, $headers['content-transfer-encoding'][0] ?? '');
    $isText = in_array($type, ['text/plain', 'text/html'], true);
    if ($type === 'message/rfc822' || $type === 'message/delivery-status') {
        return;
    }
    if ($isText && $disp !== 'attachment' && $name === '') {
        $s = inbound_utf8($data, (string) ($params['charset'] ?? ''));
        if ($type === 'text/plain' && $msg['text'] === null) {
            $msg['text'] = $s;
        } elseif ($type === 'text/html' && $msg['html'] === null) {
            $msg['html'] = $s;
        }
        return;
    }
    if ($name !== '' || $disp === 'attachment') {
        $msg['attachments'][] = ['name' => $name !== '' ? $name : 'priloha', 'type' => $type, 'data' => $data, 'inline' => $disp === 'inline' || isset($headers['content-id'])];
    }
}
/** HTML na prostý text. Citované části (blockquote, gmail_quote, Outlook divRplyFwdMsg a vše za ním) se vynechají. */
function inbound_html_text(string $html): string
{
    $html = preg_replace('~<(head|style|script|title)\b[^>]*>.*?</\1\s*>~is', '', $html);
    if (class_exists(DOMDocument::class)) {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="UTF-8"?><div id="inbound-root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($ok) {
            $xp = new DOMXPath($doc);
            // Outlook: od oddělovače odpovědi dál je jen citace.
            foreach ($xp->query('//*[@id="divRplyFwdMsg" or @id="appendonsend" or contains(@class,"OutlookMessageHeader")]') as $n) {
                for ($x = $n; $x !== null;) {
                    $next = $x->nextSibling;
                    $x->parentNode?->removeChild($x);
                    $x = $next;
                }
            }
            foreach ($xp->query('//blockquote | //*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ") or contains(@class,"moz-cite-prefix") or contains(@class,"yahoo_quoted") or @data-marker="__QUOTED_TEXT__"]') as $n) {
                $n->parentNode?->removeChild($n);
            }
            foreach ($xp->query('//br') as $n) {
                $n->parentNode?->replaceChild($doc->createTextNode("\n"), $n);
            }
            foreach ($xp->query('//p | //div | //tr | //li | //h1 | //h2 | //h3 | //h4 | //table') as $n) {
                $n->parentNode?->insertBefore($doc->createTextNode("\n"), $n);
                $n->appendChild($doc->createTextNode("\n"));
            }
            foreach ($xp->query('//a[@href]') as $n) {
                $href = (string) $n->getAttribute('href');
                if (preg_match('~^https?://~', $href) && trim($n->textContent) !== $href && trim($n->textContent) !== '') {
                    $n->appendChild($doc->createTextNode(' (' . $href . ')'));
                }
            }
            $root = $doc->getElementById('inbound-root');
            $text = $root ? $root->textContent : $doc->textContent;
            return inbound_tidy_text($text);
        }
    }
    $html = preg_replace('~<blockquote\b.*?</blockquote>~is', '', $html);
    $html = preg_replace('~<br\s*/?>|</(p|div|tr|li|h\d)>~i', "\n", $html);
    return inbound_tidy_text(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}
function inbound_tidy_text(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace("/[ \t]{2,}/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}
/**
 * Z textu odpovědi ponechá jen to, co odesílatel nově napsal: odřízne citovanou předchozí zprávu (podle běžných
 * vzorů Gmailu, Seznamu, Outlooku, Apple Mail, Thunderbirdu) a podpis.
 */
function inbound_reply_text(string $text): string
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    $out = [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = rtrim($lines[$i]);
        $t = trim($line);
        $next = trim($lines[$i + 1] ?? '');
        $next2 = trim($lines[$i + 2] ?? '');
        // Podpis: řádek „-- “ (i bez mezery) a vše za ním.
        if ($line === '-- ' || $t === '--' || $t === '—') {
            break;
        }
        // Oddělovače citace: Outlook, Thunderbird, Seznam, Gmail (přeposlání), Apple Mail.
        if (preg_match('/^-{2,}\s*(Original Message|Původní (zpráva|e-mail|email)|Přeposlaná zpráva|Forwarded message|Pôvodná správa)\s*-{2,}/iu', $t)
            || preg_match('/^(Begin forwarded message|Začátek přeposlané zprávy):/iu', $t)) {
            break;
        }
        // „Dne 27. 11. 2026 v 10:00 Jan Novák <jan@…> napsal(a):“, „On Mon, … wrote:“ (i rozdělené na dva řádky).
        $quoteHead = '/^(>\s*)?(Dne|On|Am|Le|El|Il)\b.{0,300}(napsal\(a\)|napsala|napsal|wrote|schrieb|a écrit|escribió|ha scritto|odeslal\(a\))\s*:?\s*$/iu';
        if (preg_match($quoteHead, $t) || ($t !== '' && preg_match('/^(Dne|On)\b/iu', $t) && preg_match($quoteHead, $t . ' ' . $next))) {
            break;
        }
        // Obecně: řádek končící dvojtečkou s e-mailovou adresou nebo datem, po kterém následují citované řádky „>“.
        if (str_ends_with($t, ':') && (str_contains($t, '@') || preg_match('/\d{1,2}[.\/]\s?\d{1,2}[.\/]\s?\d{2,4}|\d{4}-\d{2}-\d{2}/', $t))
            && (str_starts_with($next, '>') || ($next === '' && str_starts_with($next2, '>')))) {
            break;
        }
        // Outlook bez oddělovače: blok Od:/From: a hned pod ním Odesláno:/Sent:/Datum:/Date:/Komu:/To:.
        if (preg_match('/^\*?(Od|From|Von|De)\s*:\*?\s+\S/iu', $t) && preg_match('/^\*?(Odesláno|Sent|Datum|Date|Komu|To|Gesendet|Envoyé)\s*:/iu', $next)) {
            break;
        }
        // Čára z podtržítek (Outlook na webu) těsně před hlavičkou citace.
        if (preg_match('/^_{10,}$/', $t) && preg_match('/^\*?(Od|From)\s*:/iu', $next)) {
            break;
        }
        if (str_starts_with($t, '>')) {
            continue;   // citovaný řádek (i odpověď vpisovaná mezi citaci – necháme jen vlastní text)
        }
        if (preg_match('/^(Odesláno|Posláno|Sent|Get|Získat|Stáhnout)\b.{0,60}\b(iPhon\w*|iPad\w*|Android\w*|Outlook\w*|Seznam\w*|Gmail\w*|Pošt\w* pro Windows|Mail for Windows|BlackBerry|Samsung\w*|mobil\w*|telefon\w*)\b.*$/iu', $t)) {
            continue;   // „Odesláno z iPhonu“, „Sent from my iPhone“, „Get Outlook for iOS“
        }
        $out[] = $line;
    }
    return inbound_tidy_text(implode("\n", $out));
}
