<?php
declare(strict_types=1);

/*
 * Hledání na webu: aktuality, dokumenty (i jejich obsah – PDF, Word, OpenOffice, Excel), termíny, diskuze,
 * kontakty, závady; správcům navíc jednotky, účty a dodavatelé. Bez háčků a čárek („zapis schuze“ najde
 * „Zápis ze schůze“), všechna slova dotazu musí být nalezena. Každý vidí jen to, co smí vidět i na webu.
 */

/** Malá písmena bez diakritiky, znak za znak (délka zůstává – kvůli zvýraznění nálezu v původním textu). */
function search_norm(string $s): string
{
    static $map = null;
    $map ??= array_combine(
        preg_split('//u', 'áäčďéěëíľĺňóôöŕřšťúůüýžÁÄČĎÉĚËÍĽĹŇÓÔÖŔŘŠŤÚŮÜÝŽàâçèêîïñùûÀÂÇÈÊÎÏÑÙÛąćęłńśźżĄĆĘŁŃŚŹŻ', -1, PREG_SPLIT_NO_EMPTY),
        preg_split('//u', 'aacdeeeillnooorrstuuuyzAACDEEEILLNOOORRSTUUUYZaaceeiinuuAACEEIINUUacelnszzACELNSZZ', -1, PREG_SPLIT_NO_EMPTY));
    return mb_strtolower(strtr($s, $map));
}
/** Slova dotazu (bez diakritiky), nejvýš 8, alespoň 2 znaky. */
function search_terms(string $q): array
{
    $words = preg_split('/[^\p{L}\p{N}]+/u', search_norm($q), -1, PREG_SPLIT_NO_EMPTY);
    return array_slice(array_values(array_unique(array_filter($words, fn($w) => mb_strlen($w) >= 2))), 0, 8);
}
/** Úryvek kolem prvního nálezu se zvýrazněnými slovy (HTML, už ošetřené). */
function search_snippet(string $text, array $terms, int $len = 180): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }
    $norm = search_norm($text);
    $pos = null;
    foreach ($terms as $t) {
        $p = mb_strpos($norm, $t);
        if ($p !== false && ($pos === null || $p < $pos)) {
            $pos = $p;
        }
    }
    $start = $pos === null ? 0 : max(0, $pos - 60);
    $piece = mb_substr($text, $start, $len);
    $pieceNorm = mb_substr($norm, $start, $len);
    // Zvýraznění: najdi výskyty v normalizovaném úryvku a obal odpovídající místa původního textu.
    $marks = [];
    foreach ($terms as $t) {
        $o = 0;
        while (($p = mb_strpos($pieceNorm, $t, $o)) !== false) {
            $marks[$p] = max($marks[$p] ?? 0, mb_strlen($t));
            $o = $p + 1;
        }
    }
    ksort($marks);
    $out = '';
    $i = 0;
    foreach ($marks as $p => $l) {
        if ($p < $i) {
            continue;
        }
        $out .= e(mb_substr($piece, $i, $p - $i)) . '<mark>' . e(mb_substr($piece, $p, $l)) . '</mark>';
        $i = $p + $l;
    }
    $out .= e(mb_substr($piece, $i));
    return ($start > 0 ? '…' : '') . $out . ($start + $len < mb_strlen($text) ? '…' : '');
}

// ---------- Text z dokumentů ----------

/** Vytáhne text z nahraného souboru (PDF, DOCX, ODT, XLSX, ODS). Nikdy nevyhodí výjimku. */
function doc_text_extract(string $path, string $ext): string
{
    try {
        $ext = strtolower($ext);
        if (!is_file($path)) {
            return '';
        }
        $xml = fn(string $zipPath, string $inner) => class_exists('ZipArchive') && ($z = new ZipArchive())->open($zipPath) === true ? (string) $z->getFromName($inner) : '';
        $t = match ($ext) {
            'pdf' => pdf_text($path),
            'docx' => strip_tags(str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", ' ', "\n"], $xml($path, 'word/document.xml'))),
            'odt', 'ods' => strip_tags(str_replace(['</text:p>', '<text:tab/>', '</table:table-cell>'], ["\n", ' ', ' '], $xml($path, 'content.xml'))),
            'xlsx' => strip_tags(str_replace('</si>', "\n", $xml($path, 'xl/sharedStrings.xml'))),
            'txt' => (string) file_get_contents($path),
            default => '',
        };
        $t = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $t = mb_convert_encoding($t, 'UTF-8', 'UTF-8');
        return mb_substr(trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', preg_replace('/\R{3,}/u', "\n\n", $t))), 0, 200000);
    } catch (Throwable $e) {
        return '';
    }
}
/** Text z PDF: program pdftotext, pokud ho server má; jinak vlastní čtení (běžná PDF z Wordu, tiskáren a prohlížečů). */
function pdf_text(string $path): string
{
    if (getenv('SVJ_PDF_BUILTIN') !== '1' && function_exists('shell_exec') && is_executable('/usr/bin/pdftotext')) {
        $t = (string) @shell_exec('/usr/bin/pdftotext -q -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        if (trim($t) !== '') {
            return $t;
        }
    }
    return pdf_text_builtin((string) file_get_contents($path));
}
/** Jednoduché čtení textu z PDF bez knihoven: rozbalí proudy, načte převodní tabulky písem (ToUnicode) a sebere texty. */
function pdf_text_builtin(string $pdf): string
{
    if (!str_starts_with($pdf, '%PDF')) {
        return '';
    }
    $objects = [];
    if (preg_match_all('/(\d+)\s+0\s+obj(.*?)endobj/s', $pdf, $m, PREG_SET_ORDER)) {
        foreach ($m as $o) {
            $objects[(int) $o[1]] = $o[2];
        }
    }
    $stream = function (string $obj): ?string {
        if (!preg_match('/stream\r?\n(.*)\r?\nendstream/s', $obj, $s)) {
            return null;
        }
        $data = $s[1];
        if (str_contains($obj, '/FlateDecode')) {
            $d = @gzuncompress($data);
            if ($d === false) {
                $d = @gzinflate(substr($data, 2));
            }
            return $d === false ? null : $d;
        }
        return str_contains($obj, '/Filter') ? null : $data;
    };
    // Převodní tabulky písem: ToUnicode CMap (kódy znaků → Unicode).
    $cmaps = [];
    foreach ($objects as $num => $obj) {
        if (preg_match('/\/ToUnicode\s+(\d+)\s+0\s+R/', $obj, $tu) && isset($objects[(int) $tu[1]])) {
            $data = $stream($objects[(int) $tu[1]]);
            if ($data === null) {
                continue;
            }
            $map = [];
            if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $data, $bc)) {
                foreach ($bc[1] as $block) {
                    preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER);
                    foreach ($pairs as $p) {
                        $map[strtolower($p[1])] = mb_convert_encoding((string) hex2bin(strlen($p[2]) % 2 ? '0' . $p[2] : $p[2]), 'UTF-8', 'UTF-16BE');
                    }
                }
            }
            if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $data, $br)) {
                foreach ($br[1] as $block) {
                    preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $ranges, PREG_SET_ORDER);
                    foreach ($ranges as $r) {
                        $lo = hexdec($r[1]);
                        $hi = hexdec($r[2]);
                        $dst = hexdec($r[3]);
                        for ($c = $lo; $c <= $hi && $c - $lo < 2000; $c++) {
                            $map[str_pad(dechex($c), strlen($r[1]), '0', STR_PAD_LEFT)] = mb_convert_encoding(pack('n', $dst + $c - $lo), 'UTF-8', 'UTF-16BE');
                        }
                    }
                }
            }
            if ($map) {
                $cmaps[$num] = $map;
            }
        }
    }
    // Názvy písem v jednotlivých stránkách → převodní tabulka.
    $fontMap = [];
    foreach ($objects as $num => $obj) {
        if (preg_match('/\/Font\s*<<(.*?)>>/s', $obj, $fd)) {
            preg_match_all('/\/([A-Za-z0-9_.+-]+)\s+(\d+)\s+0\s+R/', $fd[1], $fr, PREG_SET_ORDER);
            foreach ($fr as $f) {
                if (isset($cmaps[(int) $f[2]])) {
                    $fontMap[$f[1]] = $cmaps[(int) $f[2]];
                }
            }
        }
    }
    $decodeLiteral = fn(string $s) => preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', fn($x) => match ($x[1]) {
        'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\', default => chr(octdec($x[1])) }, $s);
    $out = '';
    foreach ($objects as $obj) {
        if (str_contains($obj, '/ToUnicode') || preg_match('/\/(Subtype\s*\/(Image|XML)|Type\s*\/(XRef|ObjStm|Metadata))/', $obj)) {
            continue;
        }
        $data = $stream($obj);
        if ($data === null || !str_contains($data, 'BT')) {
            continue;
        }
        $font = null;
        preg_match_all('/\/([A-Za-z0-9_.+-]+)\s+[\d.]+\s+Tf|\[((?:\\\\.|[^\]])*)\]\s*TJ|\(((?:\\\\.|[^\\\\)])*)\)\s*(?:Tj|\'|")|<([0-9A-Fa-f\s]+)>\s*Tj|(ET|T\*|Td|TD)/s', $data, $ops, PREG_SET_ORDER);
        foreach ($ops as $op) {
            if (($op[1] ?? '') !== '') {
                $font = $fontMap[$op[1]] ?? null;
                continue;
            }
            if (($op[5] ?? '') !== '') {
                $out .= $op[5] === 'ET' ? "\n" : ' ';
                continue;
            }
            $parts = [];
            if (($op[2] ?? '') !== '') {
                preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)|<([0-9A-Fa-f\s]+)>|(-?\d+(?:\.\d+)?)/s', $op[2], $items, PREG_SET_ORDER);
                foreach ($items as $it) {
                    if (($it[3] ?? '') !== '') {
                        if ((float) $it[3] < -200) {
                            $parts[] = [' ', 'raw'];   // velká mezera mezi slovy
                        }
                    } elseif (($it[2] ?? '') !== '') {
                        $parts[] = [preg_replace('/\s+/', '', $it[2]), 'hex'];
                    } else {
                        $parts[] = [$decodeLiteral($it[1]), 'lit'];
                    }
                }
            } elseif (($op[3] ?? '') !== '') {
                $parts[] = [$decodeLiteral($op[3]), 'lit'];
            } elseif (($op[4] ?? '') !== '') {
                $parts[] = [preg_replace('/\s+/', '', $op[4]), 'hex'];
            }
            foreach ($parts as [$s, $kind]) {
                if ($kind === 'raw') {
                    $out .= $s;
                    continue;
                }
                $bytes = $kind === 'hex' ? (string) hex2bin(strlen($s) % 2 ? $s . '0' : $s) : $s;
                if ($font) {
                    $w = strlen((string) array_key_first($font)) / 2;   // délka kódu v bajtech (1 nebo 2)
                    $w = $w >= 2 ? 2 : 1;
                    for ($i = 0; $i < strlen($bytes); $i += $w) {
                        $out .= $font[bin2hex(substr($bytes, $i, $w))] ?? '';
                    }
                } else {
                    $out .= mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
                }
            }
        }
    }
    return $out;
}
/** Doplní text u dokumentů, které ho ještě nemají (postupně, aby se stránka nezdržela). */
function search_index_pending(int $limit = 15, float $seconds = 4.0): int
{
    $start = microtime(true);
    $n = 0;
    foreach (all("SELECT id, filename, original_name FROM documents WHERE content IS NULL AND filename IS NOT NULL AND filename <> '' LIMIT " . (int) $limit) as $d) {
        $ext = strtolower(pathinfo($d['original_name'] ?: $d['filename'], PATHINFO_EXTENSION));
        $text = preg_match('/^[a-f0-9]{32}\.[a-z]+$/', $d['filename']) ? doc_text_extract(UPLOAD_DIR . '/' . $d['filename'], $ext) : '';
        q('UPDATE documents SET content = ? WHERE id = ?', [$text, $d['id']]);
        $n++;
        if (microtime(true) - $start > $seconds) {
            break;
        }
    }
    return $n;
}

// ---------- Hledání ----------

/**
 * Výsledky po skupinách: [klíč => [název skupiny, [[nadpis, adresa, úryvek HTML, meta, skóre], …]]].
 * Prohledává jen to, co aktuální návštěvník smí vidět.
 */
function site_search(string $q): array
{
    $terms = search_terms($q);
    if (!$terms) {
        return [];
    }
    $u = user();
    $admin = $u && is_admin();
    $hit = function (array $fields) use ($terms): ?int {
        $score = 0;
        foreach ($terms as $t) {
            $found = false;
            foreach ($fields as $i => $f) {
                $c = mb_substr_count(search_norm((string) $f), $t);
                if ($c) {
                    $found = true;
                    $score += $i === 0 ? 10 * $c : $c;   // nadpis váží víc
                }
            }
            if (!$found) {
                return null;   // musí se najít všechna slova
            }
        }
        return $score;
    };
    $groups = [];
    $add = function (string $key, string $label, array $rows) use (&$groups) {
        if ($rows) {
            usort($rows, fn($a, $b) => $b[4] <=> $a[4]);
            $groups[$key] = [$label, array_slice($rows, 0, 30), count($rows)];
        }
    };
    if (feature_on('aktuality')) {
        $rows = [];
        foreach (all('SELECT id, title, body, published_at FROM posts WHERE ' . vis_sql() . " AND published_at <= date('now','localtime')") as $r) {
            if (($s = $hit([$r['title'], $r['body']])) !== null) {
                $rows[] = [$r['title'], '/aktuality/' . $r['id'], search_snippet($r['body'], $terms), cz_date($r['published_at']), $s];
            }
        }
        $add('aktuality', 'Aktuality', $rows);
    }
    if (feature_on('dokumenty')) {
        search_index_pending(10, 3.0);
        $rows = [];
        foreach (all('SELECT id, title, category, original_name, doc_date, content FROM documents WHERE ' . doc_scope_sql('', true)) as $r) {
            if (($s = $hit([$r['title'], $r['original_name'] . ' ' . (CATEGORIES[$r['category']] ?? ''), (string) $r['content']])) !== null) {
                $rows[] = [$r['title'], '/soubor/' . $r['id'], search_snippet((string) $r['content'] !== '' ? (string) $r['content'] : $r['original_name'], $terms),
                    trim((CATEGORIES[$r['category']] ?? '') . ($r['doc_date'] ? ' · ' . cz_date($r['doc_date']) : '')), $s];
            }
        }
        $add('dokumenty', 'Dokumenty', $rows);
    }
    if ($u && feature_on('terminy')) {
        $rows = [];
        foreach (all('SELECT id, title, event_date, event_time, place, note, program FROM events') as $r) {
            if (($s = $hit([$r['title'], $r['place'] . ' ' . $r['note'] . ' ' . $r['program']])) !== null) {
                $rows[] = [$r['title'], '/terminy/' . $r['id'], search_snippet(trim($r['note'] . ' ' . str_replace('**', '', $r['program'])) ?: $r['place'], $terms), cz_date($r['event_date'], true) . ($r['place'] ? ' · ' . $r['place'] : ''), $s];
            }
        }
        $add('terminy', 'Termíny', $rows);
    }
    if ($u && feature_on('diskuze')) {
        $rows = [];
        foreach (all('SELECT f.id, f.parent_id, f.title, f.body, f.created_at, p.title AS thread FROM forum_posts f LEFT JOIN forum_posts p ON p.id = f.parent_id') as $r) {
            $title = $r['parent_id'] ? 'Odpověď v diskuzi „' . $r['thread'] . '“' : $r['title'];
            if (($s = $hit([$r['parent_id'] ? '' : $r['title'], $r['body']])) !== null) {
                $rows[] = [$title, '/diskuze/' . ($r['parent_id'] ?: $r['id']), search_snippet($r['body'], $terms), cz_date($r['created_at']), $s];
            }
        }
        $add('diskuze', 'Diskuze', $rows);
    }
    if (feature_on('kontakty')) {
        $rows = [];
        foreach (all('SELECT * FROM contacts') as $r) {
            if (($s = $hit([$r['name'], $r['role'] . ' ' . $r['note'] . ' ' . $r['phone'] . ' ' . $r['email']])) !== null) {
                $rows[] = [$r['name'], '/kontakty', search_snippet(trim($r['role'] . ' · ' . $r['phone'] . ' · ' . $r['note'], ' ·'), $terms), CONTACT_GROUPS[$r['grp']] ?? '', $s];
            }
        }
        $add('kontakty', 'Kontakty', $rows);
    }
    if ($u && feature_on('zavady')) {
        $rows = [];
        foreach (all('SELECT id, subject, location, body, status, created_at FROM tickets' . ($admin ? '' : ' WHERE user_id = ' . (int) $u['id'])) as $r) {
            if (($s = $hit([$r['subject'], $r['location'] . ' ' . $r['body']])) !== null) {
                $rows[] = [$r['subject'], $admin ? '/admin/zavady#t' . $r['id'] : '/zavady#t' . $r['id'], search_snippet($r['body'], $terms), (TICKET_STATUS[$r['status']] ?? '') . ' · ' . cz_date($r['created_at']), $s];
            }
        }
        $add('zavady', $admin ? 'Závady a požadavky' : 'Moje závady a požadavky', $rows);
    }
    if ($admin) {
        $rows = [];
        foreach (all('SELECT * FROM units') as $r) {
            if (($s = $hit([unit_name($r) . ' ' . $r['entrance'] . '/' . $r['number'], $r['owner_note'] . ' ' . ($r['note'] ?? '')])) !== null) {
                $rows[] = [unit_name($r), '/admin/jednotky/' . $r['id'], search_snippet($r['owner_note'], $terms), 'podíl ' . $r['share'], $s];
            }
        }
        $add('jednotky', 'Jednotky (jen pro správce)', $rows);
        $rows = [];
        foreach (all('SELECT u.id, u.name, u.email, u.phone, ' . units_sql('u') . ' units FROM users u WHERE u.active = 1') as $r) {
            if (($s = $hit([$r['name'], $r['email'] . ' ' . $r['phone'] . ' ' . $r['units']])) !== null) {
                $rows[] = [$r['name'], '/admin/uzivatele/' . $r['id'], search_snippet($r['email'] . ($r['phone'] ? ' · ' . $r['phone'] : ''), $terms), (string) $r['units'], $s];
            }
        }
        $add('ucty', 'Účty (jen pro správce)', $rows);
        $rows = [];
        foreach (all('SELECT * FROM suppliers') as $r) {
            $fields = array_values(array_filter($r, 'is_string'));
            if (($s = $hit([$r['name'] ?? '', implode(' ', $fields)])) !== null) {
                $rows[] = [(string) ($r['name'] ?? 'Dodavatel'), '/admin/dodavatele', search_snippet(implode(' · ', array_filter([$r['field'] ?? '', $r['phone'] ?? '', $r['email'] ?? '', $r['note'] ?? ''])), $terms), '', $s];
            }
        }
        $add('dodavatele', 'Dodavatelé (jen pro správce)', $rows);
    }
    return $groups;
}
