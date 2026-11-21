<?php
declare(strict_types=1);

// Malý generátor QR kódů (bajtový režim, úroveň oprav M, verze 1–10 = až 213 bajtů). Stačí na QR platbu a web nepotřebuje žádnou knihovnu.

/** Vrátí matici QR kódu (true = tmavý bod), nebo null, když se text nevejde. */
function qr_matrix(string $data): ?array
{
    // [datových slov celkem, opravných slov na blok, bloky skupiny 1, slov v bloku 1, bloky skupiny 2, slov v bloku 2]
    static $spec = [1 => [16, 10, 1, 16, 0, 0], [28, 16, 1, 28, 0, 0], [44, 26, 1, 44, 0, 0], [64, 18, 2, 32, 0, 0], [86, 24, 2, 43, 0, 0],
        [108, 16, 4, 27, 0, 0], [124, 18, 4, 31, 0, 0], [154, 22, 2, 38, 2, 39], [182, 22, 3, 36, 2, 37], [216, 26, 4, 43, 1, 44]];
    static $align = [1 => [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];
    $len = strlen($data);
    $version = 0;
    foreach ($spec as $v => $s) {
        if ($len <= $s[0] - ($v < 10 ? 2 : 3)) {
            $version = $v;
            break;
        }
    }
    if (!$version) {
        return null;
    }
    [$total, $ecLen, $b1, $c1, $b2, $c2] = $spec[$version];

    // Datová slova: režim, délka, data, ukončení a výplň.
    $bits = '0100' . str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $bits .= str_repeat('0', min(4, $total * 8 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $words = array_map('bindec', str_split($bits, 8));
    for ($pad = 0; count($words) < $total; $pad ^= 1) {
        $words[] = $pad ? 0x11 : 0xEC;
    }

    // Reedovy–Solomonovy opravné kódy nad GF(256).
    $exp = $log = [];
    for ($i = 0, $x = 1; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;
        }
    }
    $gen = [1];
    for ($i = 0; $i < $ecLen; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $g) {
            $next[$j] ^= $g;
            $next[$j + 1] ^= $g ? $exp[($log[$g] + $i) % 255] : 0;
        }
        $gen = $next;
    }
    $blocks = $ecBlocks = [];
    $pos = 0;
    foreach ([[$b1, $c1], [$b2, $c2]] as [$count, $size]) {
        for ($b = 0; $b < $count; $b++) {
            $block = array_slice($words, $pos, $size);
            $pos += $size;
            $rem = array_merge($block, array_fill(0, $ecLen, 0));
            for ($i = 0; $i < $size; $i++) {
                $f = $rem[$i];
                if ($f) {
                    foreach ($gen as $j => $g) {
                        $rem[$i + $j] ^= $g ? $exp[($log[$g] + $log[$f]) % 255] : 0;
                    }
                }
            }
            $blocks[] = $block;
            $ecBlocks[] = array_slice($rem, $size);
        }
    }
    $stream = [];
    for ($i = 0; $i < max($c1, $c2); $i++) {
        foreach ($blocks as $block) {
            if (isset($block[$i])) {
                $stream[] = $block[$i];
            }
        }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($ecBlocks as $block) {
            $stream[] = $block[$i];
        }
    }

    // Pevné vzory.
    $n = 17 + 4 * $version;
    $m = array_fill(0, $n, array_fill(0, $n, false));
    $fixed = array_fill(0, $n, array_fill(0, $n, false));
    $set = function (int $r, int $c, bool $dark) use (&$m, &$fixed, $n): void {
        if ($r >= 0 && $r < $n && $c >= 0 && $c < $n) {
            $m[$r][$c] = $dark;
            $fixed[$r][$c] = true;
        }
    };
    foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r0, $c0]) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $inside = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
                $set($r0 + $r, $c0 + $c, $inside && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)));
            }
        }
    }
    for ($i = 8; $i < $n - 8; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }
    foreach ($align[$version] as $r0) {
        foreach ($align[$version] as $c0) {
            if (($r0 === 6 && $c0 === 6) || ($r0 === 6 && $c0 === $n - 7) || ($r0 === $n - 7 && $c0 === 6)) {
                continue;
            }
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $set($r0 + $r, $c0 + $c, max(abs($r), abs($c)) !== 1);
                }
            }
        }
    }
    $set($n - 8, 8, true);
    // Místa pro informaci o formátu a verzi (hodnoty se doplní až po volbě masky).
    for ($i = 0; $i < 9; $i++) {
        $fixed[8][$i] = $fixed[$i][8] = true;
    }
    for ($i = 0; $i < 8; $i++) {
        $fixed[8][$n - 1 - $i] = $fixed[$n - 1 - $i][8] = true;
    }
    if ($version >= 7) {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $info = ($version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (bool) (($info >> $i) & 1);
            $set($n - 11 + $i % 3, intdiv($i, 3), $dark);
            $set(intdiv($i, 3), $n - 11 + $i % 3, $dark);
        }
    }

    // Uložení dat klikatě odprava dole.
    $bitAt = fn(int $i): bool => $i < count($stream) * 8 && (($stream[$i >> 3] >> (7 - ($i & 7))) & 1);
    $i = 0;
    $up = true;
    for ($col = $n - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;
        }
        for ($k = 0; $k < $n; $k++) {
            $r = $up ? $n - 1 - $k : $k;
            foreach ([$col, $col - 1] as $c) {
                if (!$fixed[$r][$c]) {
                    $m[$r][$c] = $bitAt($i++);
                }
            }
        }
        $up = !$up;
    }

    // Maska: vyzkouší se všech osm a vybere ta s nejnižším trestem.
    $masks = [
        fn($r, $c) => ($r + $c) % 2 === 0, fn($r, $c) => $r % 2 === 0, fn($r, $c) => $c % 3 === 0, fn($r, $c) => ($r + $c) % 3 === 0,
        fn($r, $c) => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0, fn($r, $c) => ($r * $c) % 2 + ($r * $c) % 3 === 0,
        fn($r, $c) => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0, fn($r, $c) => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
    ];
    $best = null;
    $bestScore = PHP_INT_MAX;
    foreach ($masks as $k => $mask) {
        $t = $m;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if (!$fixed[$r][$c] && $mask($r, $c)) {
                    $t[$r][$c] = !$t[$r][$c];
                }
            }
        }
        // Informace o formátu: úroveň M (00) a číslo masky, zabezpečené kódem BCH.
        $fmt = $k;
        $rem = $fmt;
        for ($j = 0; $j < 10; $j++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $fmt = (($fmt << 10) | $rem) ^ 0x5412;
        $bit = fn(int $j): bool => (bool) (($fmt >> $j) & 1);
        for ($j = 0; $j <= 5; $j++) {
            $t[8][$j] = $bit(14 - $j);
            $t[$j][8] = $bit($j);
        }
        $t[8][7] = $bit(8);
        $t[8][8] = $bit(7);
        $t[7][8] = $bit(6);
        for ($j = 0; $j < 8; $j++) {
            $t[8][$n - 1 - $j] = $bit($j);
        }
        for ($j = 0; $j < 7; $j++) {
            $t[$n - 1 - $j][8] = $bit(14 - $j);
        }
        $t[$n - 8][8] = true;
        $score = qr_penalty($t, $n);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $t;
        }
    }
    return $best;
}
function qr_penalty(array $m, int $n): int
{
    $score = 0;
    $dark = 0;
    for ($a = 0; $a < $n; $a++) {
        foreach ([0, 1] as $dir) {
            $run = 1;
            $line = '';
            for ($b = 0; $b < $n; $b++) {
                $v = $dir ? $m[$b][$a] : $m[$a][$b];
                $line .= $v ? '1' : '0';
                if ($b > 0) {
                    $prev = $dir ? $m[$b - 1][$a] : $m[$a][$b - 1];
                    if ($v === $prev) {
                        $run++;
                    } else {
                        $score += $run >= 5 ? $run - 2 : 0;
                        $run = 1;
                    }
                }
            }
            $score += $run >= 5 ? $run - 2 : 0;
            $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
        }
        for ($b = 0; $b < $n; $b++) {
            $dark += $m[$a][$b] ? 1 : 0;
            if ($a < $n - 1 && $b < $n - 1 && $m[$a][$b] === $m[$a][$b + 1] && $m[$a][$b] === $m[$a + 1][$b] && $m[$a][$b] === $m[$a + 1][$b + 1]) {
                $score += 3;
            }
        }
    }
    return $score + 10 * intdiv((int) abs($dark * 100 / ($n * $n) - 50), 5);
}
/** QR kód jako obrázek SVG vložený přímo do stránky. */
function qr_svg(string $data, string $label = 'QR kód'): string
{
    $m = qr_matrix($data);
    if (!$m) {
        return '';
    }
    $n = count($m);
    $path = '';
    foreach ($m as $r => $row) {
        for ($c = 0; $c < $n; $c++) {
            if ($row[$c]) {
                $start = $c;
                while ($c + 1 < $n && $row[$c + 1]) {
                    $c++;
                }
                $path .= 'M' . ($start + 4) . ' ' . ($r + 4) . 'h' . ($c - $start + 1) . 'v1h-' . ($c - $start + 1) . 'z';
            }
        }
    }
    $size = $n + 8;
    return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" shape-rendering="crispEdges">'
        . '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
}
