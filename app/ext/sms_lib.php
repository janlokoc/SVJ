<?php
declare(strict_types=1);

/*
 * SMS zprávy (funkce v betě 'sms', výchozí stav vypnuto).
 *
 * - sms_send($telefon, $text): bool – veřejná funkce pro ostatní moduly (např. hlasování: function_exists('sms_send')).
 *   Podrobný výsledek vrací sms_send_ex().
 * - Brány (rozhraní SmsProvider): GoSMS.cz, BulkGate a 'log' (jen zápis do souboru – zkušební prostředí a vývoj).
 *   Zkušební prostředí (is_staging()) posílá VŽDY přes 'log' – skutečná SMS z něj nikdy neodejde.
 * - Placení: z kreditu služeb domu (app/ext/credit_lib.php) – každá odeslaná část zprávy stojí sms_price_h haléřů (výchozí 1,50 Kč)
 *   bez DPH. Edice Premium má sms_free_premium SMS za kalendářní rok v ceně (výchozí 100), čerpají se před kreditem.
 *   Neodeslaná zpráva se do kreditu (i do volných SMS) vrací. Bez kreditu se neposílá. Dřívější kredit v počtu SMS
 *   (sms_ledger) převedla migrace platformy na Kč; tabulka zůstává jako historie.
 *   Web mimo platformu (bez předplatného) kredit nemá – posílá se na účet provozovatele brány bez omezení.
 *
 * Přístupové údaje k bráně jsou jen v storage/config.php (nikdy v databázi ani v repozitáři):
 *   'sms' => [
 *       'gosms_client_id' => '…', 'gosms_client_secret' => '…', 'gosms_channel' => 123,   // GoSMS.cz (selfservice → API)
 *       'bulkgate_app_id' => '…', 'bulkgate_token' => '…', 'bulkgate_sender' => 'gSystem', 'bulkgate_sender_value' => '',   // BulkGate
 *   ],
 * Brána a cena pro zákazníky se volí v konzoli (nastavení platformy sms_provider, sms_price_h).
 */

/** Výchozí hodnoty nastavení provozovatele (pset). Cena je v haléřích za 1 SMS bez DPH. */
const SMS_DEFAULTS = ['sms_provider' => 'log', 'sms_price_h' => '150'];
const SMS_PROVIDERS = ['log' => 'Jen záznam (nic neodesílá)', 'gosms' => 'GoSMS.cz', 'bulkgate' => 'BulkGate'];
/** Nejdelší zpráva v částech (SMS). Delší text se odmítne – zprávu je lepší poslat e-mailem. */
const SMS_MAX_SEGMENTS = 6;

$GLOBALS['EXT_ADMIN_NAV']['/admin/sms'] = ['SMS zprávy', 'Komunikace', fn() => beta_visible('sms')];

// ---------- Telefonní čísla ----------

/**
 * Číslo ve tvaru E.164 (+420…), nebo null. Devítimístné číslo bez předvolby se bere jako české.
 * Přijímá mezery, pomlčky, závorky, předvolbu „+“ i „00“.
 */
function sms_normalize(string $phone): ?string
{
    $p = preg_replace('/[\s\-\/().\x{00a0}]/u', '', trim($phone));
    if ($p === '' || !preg_match('/^(\+|00)?\d+$/', $p)) {
        return null;
    }
    if (str_starts_with($p, '00')) {
        $p = '+' . substr($p, 2);
    }
    if ($p[0] !== '+') {
        if (strlen($p) === 9) {
            $p = '+420' . $p;
        } elseif (strlen($p) === 12 && (str_starts_with($p, '420') || str_starts_with($p, '421'))) {
            $p = '+' . $p;
        } else {
            return null;
        }
    }
    $digits = substr($p, 1);
    if ($digits[0] === '0' || strlen($digits) < 8 || strlen($digits) > 15) {
        return null;
    }
    // Česko a Slovensko: po předvolbě přesně 9 číslic.
    if ((str_starts_with($digits, '420') || str_starts_with($digits, '421')) && strlen($digits) !== 12) {
        return null;
    }
    return $p;
}
/** Je to český mobil? (Mobilní čísla začínají 60x, 70x, 72x–79x; pevné linky SMS nepřijmou.) */
function sms_is_mobile(string $e164): bool
{
    if (str_starts_with($e164, '+420')) {
        return (bool) preg_match('/^\+420(60[1-8]|7[0-9]\d)\d{6}$/', $e164);
    }
    return true;   // jinde to neposuzujeme
}

// ---------- Kódování a počet částí ----------

/** Znaky základní tabulky GSM 03.38 (1 znak = 7 bitů) a rozšíření (escape, počítá se za 2). */
const SMS_GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const SMS_GSM7_EXT = "^{}\\[~]|€\f";

/** Délka textu v jednotkách kódování a počet částí: ['encoding' => 'GSM-7'|'UCS-2', 'units', 'segments', 'per_segment']. */
function sms_segments(string $text): array
{
    static $basic = null, $ext = null;
    $basic ??= array_flip(mb_str_split(SMS_GSM7_BASIC));
    $ext ??= array_flip(mb_str_split(SMS_GSM7_EXT));
    $units = 0;
    $gsm = true;
    foreach (mb_str_split($text) as $ch) {
        if (isset($basic[$ch])) {
            $units++;
        } elseif (isset($ext[$ch])) {
            $units += 2;
        } else {
            $gsm = false;
            break;
        }
    }
    if (!$gsm) {
        // UCS-2/UTF-16: znaky mimo základní rovinu (emoji) zaberou 2 jednotky.
        $units = intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);
        $single = 70;
        $multi = 67;
    } else {
        $single = 160;
        $multi = 153;
    }
    $segments = $units === 0 ? 0 : ($units <= $single ? 1 : (int) ceil($units / $multi));
    return ['encoding' => $gsm ? 'GSM-7' : 'UCS-2', 'units' => $units, 'segments' => $segments, 'per_segment' => $segments > 1 ? $multi : $single];
}
/** Text bez české a slovenské diakritiky (a typografických znaků mimo GSM-7) – zpráva se vejde do 160 znaků místo 70. */
function sms_plain(string $text): string
{
    static $map = null;
    if ($map === null) {
        $map = ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
            'ľ' => 'l', 'ĺ' => 'l', 'ô' => 'o', 'ŕ' => 'r',
            '–' => '-', '—' => '-', '„' => '"', '“' => '"', '”' => '"', '‚' => "'", '‘' => "'", '’' => "'", '…' => '...', "\u{a0}" => ' ', '•' => '-', "\t" => ' '];
        foreach ($map as $k => $v) {
            $up = mb_strtoupper($k);
            if ($up !== $k && !isset($map[$up])) {
                $map[$up] = mb_strtoupper($v);
            }
        }
        // „é“ a „É“ jsou v GSM-7, ponechají se.
    }
    return strtr($text, $map);
}
/** Cena SMS pro zákazníky v haléřích bez DPH. */
function sms_price_h(): int
{
    $v = function_exists('pset') && function_exists('platform_enabled') && platform_enabled() ? pset('sms_price_h') : '';
    return ctype_digit($v) ? min(100000, (int) $v) : (int) SMS_DEFAULTS['sms_price_h'];
}
/** Odhad ceny: [částí na zprávu, částí celkem, Kč celkem (float)] pro $recipients příjemců. */
function sms_cost(string $text, int $recipients = 1): array
{
    $seg = sms_segments($text)['segments'];
    $total = $seg * max(0, $recipients);
    return [$seg, $total, (float) ($total * sms_price_h() / 100)];
}
function sms_money(float $czk): string
{
    return number_format($czk, fmod($czk, 1.0) === 0.0 ? 0 : 2, ',', "\u{a0}") . "\u{a0}Kč";
}

// ---------- Placení z kreditu služeb (databáze platformy) ----------

/** Platí se SMS z kreditu? Jen u webů na platformě. */
function sms_credit_managed(): bool
{
    return function_exists('credit_managed') && credit_managed();
}
/** Dřívější kredit v počtu SMS – jen historie (převedeno na kredit služeb, app/ext/credit_lib.php). */
function sms_ledger_ready(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    platform_db()->exec("CREATE TABLE IF NOT EXISTS sms_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant TEXT NOT NULL, at TEXT NOT NULL, delta INTEGER NOT NULL,
        kind TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', by_whom TEXT NOT NULL DEFAULT '');
        CREATE INDEX IF NOT EXISTS sms_ledger_tenant ON sms_ledger (tenant, id);");
}
/** Kolik SMS má edice v ceně za kalendářní rok (Premium sms_free_premium, jinak 0). */
function sms_free_allowance(string $tenant): int
{
    $t = function_exists('tenant_row') ? tenant_row($tenant) : null;
    return $t && tenant_edition($t) === 'premium' ? credit_setting('sms_free_premium') : 0;
}
/** Volné SMS, které letos zbývají. */
function sms_free_left(string $tenant): int
{
    $allow = sms_free_allowance($tenant);
    if ($allow <= 0) {
        return 0;
    }
    $used = (int) pq("SELECT COALESCE(SUM(qty), 0) FROM credit_ledger WHERE tenant = ? AND kind = 'sms' AND delta_h = 0 AND at >= ?", [$tenant, date('Y-01-01')])->fetchColumn();
    return max(0, $allow - $used);
}
/** Kolik SMS jde teď poslat: volné SMS edice + kredit / cena SMS. */
function sms_balance(?string $tenant = null): int
{
    $tenant ??= TENANT;
    $price = sms_price_h();
    return sms_free_left($tenant) + ($price > 0 ? intdiv(max(0, credit_balance_h($tenant)), $price) : 1000000);
}
/**
 * Zaplatí $n částí zprávy: nejdřív volné SMS edice, zbytek z kreditu (atomicky). Vrací ref pohybů (pro vrácení),
 * nebo null, když na to nestačí.
 */
function sms_charge(string $tenant, int $n, string $note): ?string
{
    $ref = 'sms:' . bin2hex(random_bytes(6));
    $ok = credit_tx(function () use ($tenant, $n, $note, $ref): bool {
        $free = min($n, sms_free_left($tenant));
        $paid = $n - $free;
        if ($paid > 0 && !credit_charge($tenant, $paid * sms_price_h(), 'sms', $note, $ref, $paid)) {
            return false;
        }
        if ($free > 0) {
            credit_row_add($tenant, 0, 'sms', $note . ' (v ceně edice)', '', 0, '', $ref, $free);
        }
        return true;
    });
    return $ok ? $ref : null;
}

// ---------- Záznam odeslaných zpráv (databáze domu) ----------

function sms_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $exists = $mysql ? (bool) $pdo->query("SHOW TABLES LIKE 'sms_log'")->fetch() : (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sms_log'")->fetch();
    if ($exists) {
        return;
    }
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT' : 'INTEGER';
    $pdo->exec('CREATE TABLE sms_log (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", at {$s(19)}, phone {$s(20)}, user_id $int NOT NULL DEFAULT 0,"
        . " segments $int NOT NULL DEFAULT 0, status {$s(12)}, provider {$s(20)}, provider_id {$s(80)}, error {$s(255)}, source {$s(40)}, text {$s(1000)})"
        . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    $pdo->exec('CREATE INDEX sms_log_at ON sms_log (at)');
}

// ---------- Brány ----------

/** HTTP pro brány: fn(string $method, string $url, array $headers, ?string $body): ['status' => int, 'body' => string]. V testech se nahradí. */
function sms_transport(?callable $set = null, bool $reset = false): callable
{
    static $t = null;
    if ($reset) {
        $t = null;
    }
    if ($set !== null) {
        $t = $set;
    }
    return $t ??= 'modc_http';
}
/** Jednoduchý HTTP klient (stream, bez knihoven). Společný pro SMS i banky. */
function modc_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
{
    $ctx = stream_context_create(['http' => ['method' => $method, 'timeout' => $timeout, 'ignore_errors' => true, 'header' => implode("\r\n", $headers), 'content' => $body ?? '']]);
    $res = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})(\s|$)/', $http_response_header[0], $m) ? (int) $m[1] : 0;
    $hdr = [];
    foreach (array_slice($http_response_header ?? [], 1) as $h) {
        if (str_contains($h, ':')) {
            [$k, $v] = explode(':', $h, 2);
            $hdr[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $res === false ? 0 : $status, 'body' => $res === false ? '' : $res, 'headers' => $hdr];
}

interface SmsProvider
{
    /** Odešle jednu zprávu na číslo E.164. Vrací ['ok' => bool, 'id' => string, 'error' => string]. */
    public function send(string $e164, string $text, bool $unicode): array;
}

/** Zkušební brána: nic neodesílá, zprávu jen zapíše do storage (sms-log.jsonl). */
final class SmsLogProvider implements SmsProvider
{
    public function __construct(private string $file)
    {
    }
    public function send(string $e164, string $text, bool $unicode): array
    {
        $id = 'log-' . bin2hex(random_bytes(6));
        $line = json_encode(['at' => date('Y-m-d H:i:s'), 'id' => $id, 'to' => $e164, 'text' => $text, 'unicode' => $unicode], JSON_UNESCAPED_UNICODE) . "\n";
        $ok = @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) !== false;
        return ['ok' => $ok, 'id' => $ok ? $id : '', 'error' => $ok ? '' : 'Záznam zprávy se nepodařilo uložit.'];
    }
}

/**
 * GoSMS.cz – REST API, dokumentace https://doc.gosms.eu/ (OpenAPI: https://doc.gosms.eu/gosms-api-definition_en.json).
 * Token: GET https://app.gosms.eu/oauth/v2/token?client_id&client_secret&grant_type=client_credentials → access_token (platí 3600 s).
 * Odeslání: POST https://app.gosms.eu/api/v1/messages (Bearer) {"message", "recipients", "channel"} → 201 {"recipients":{"invalid":[]}, "link":"api/v1/messages/1"}.
 * Chyby: 400 application/problem+json {"title","detail","errors"}, 401 neplatný token.
 * Neověřeno proti skutečné službě (bez účtu) – ověřeno jen proti dokumentaci.
 */
final class SmsGoSmsProvider implements SmsProvider
{
    private ?string $token = null;

    public function __construct(private string $clientId, private string $clientSecret, private int $channel, private $http, private string $base = 'https://app.gosms.eu')
    {
    }
    private function token(): ?string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $r = ($this->http)('GET', $this->base . '/oauth/v2/token?' . http_build_query(['client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'grant_type' => 'client_credentials']), ['Accept: application/json'], null);
        $j = json_decode((string) $r['body'], true);
        return $this->token = ($r['status'] === 200 && is_array($j) && is_string($j['access_token'] ?? null)) ? $j['access_token'] : null;
    }
    public function send(string $e164, string $text, bool $unicode): array
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->channel <= 0) {
            return ['ok' => false, 'id' => '', 'error' => 'GoSMS: chybí client_id, client_secret nebo číslo kanálu v config.php.'];
        }
        $token = $this->token();
        if ($token === null) {
            return ['ok' => false, 'id' => '', 'error' => 'GoSMS: přihlášení k API se nepovedlo (zkontrolujte client_id a client_secret).'];
        }
        $r = ($this->http)('POST', $this->base . '/api/v1/messages', ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
            json_encode(['message' => $text, 'recipients' => $e164, 'channel' => $this->channel], JSON_UNESCAPED_UNICODE));
        $j = json_decode((string) $r['body'], true);
        if ($r['status'] === 201 && is_array($j)) {
            if (in_array($e164, array_map('strval', (array) ($j['recipients']['invalid'] ?? [])), true)) {
                return ['ok' => false, 'id' => '', 'error' => 'GoSMS: číslo odmítnuto jako neplatné nebo blokované.'];
            }
            return ['ok' => true, 'id' => preg_match('~messages/(\d+)~', (string) ($j['link'] ?? ''), $m) ? 'gosms:' . $m[1] : 'gosms', 'error' => ''];
        }
        if ($r['status'] === 0) {
            return ['ok' => false, 'id' => '', 'error' => 'GoSMS neodpovídá.'];
        }
        $detail = is_array($j) ? trim(($j['title'] ?? '') . ': ' . ($j['detail'] ?? '') . (isset($j['errors']) ? ' ' . implode('; ', array_map('strval', (array) $j['errors'])) : ''), ': ') : '';
        return ['ok' => false, 'id' => '', 'error' => 'GoSMS odmítla zprávu (' . $r['status'] . ')' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 180) : '') . '.'];
    }
}

/**
 * BulkGate – Simple API, transakční SMS: https://www.bulkgate.com/en/docs/simple-api/simple-transactional/
 * POST https://portal.bulkgate.com/api/1.0/simple/transactional (JSON) {application_id, application_token, number, text, unicode, sender_id, sender_id_value, country}
 * → {"data":{"status":"accepted","sms_id":"…","part_id":[…],"number":"420…"}}; chyba {"type","code","error","detail"}.
 * Neověřeno proti skutečné službě (bez účtu) – ověřeno jen proti dokumentaci.
 */
final class SmsBulkGateProvider implements SmsProvider
{
    public function __construct(private string $appId, private string $token, private string $sender, private string $senderValue, private $http, private string $url = 'https://portal.bulkgate.com/api/1.0/simple/transactional')
    {
    }
    public function send(string $e164, string $text, bool $unicode): array
    {
        if ($this->appId === '' || $this->token === '') {
            return ['ok' => false, 'id' => '', 'error' => 'BulkGate: chybí application_id nebo application_token v config.php.'];
        }
        $req = ['application_id' => ctype_digit($this->appId) ? (int) $this->appId : $this->appId, 'application_token' => $this->token, 'number' => ltrim($e164, '+'), 'text' => $text,
            'unicode' => $unicode, 'sender_id' => $this->sender !== '' ? $this->sender : 'gSystem'];
        if ($this->senderValue !== '') {
            $req['sender_id_value'] = $this->senderValue;
        }
        $r = ($this->http)('POST', $this->url, ['Content-Type: application/json', 'Accept: application/json'], json_encode($req, JSON_UNESCAPED_UNICODE));
        $j = json_decode((string) $r['body'], true);
        if ($r['status'] === 200 && is_array($j) && in_array($j['data']['status'] ?? '', ['accepted', 'scheduled', 'sent'], true)) {
            return ['ok' => true, 'id' => 'bulkgate:' . (string) ($j['data']['sms_id'] ?? ''), 'error' => ''];
        }
        if ($r['status'] === 0) {
            return ['ok' => false, 'id' => '', 'error' => 'BulkGate neodpovídá.'];
        }
        $err = is_array($j) ? (string) ($j['error'] ?? ($j['data']['status'] ?? '')) : '';
        return ['ok' => false, 'id' => '', 'error' => 'BulkGate odmítla zprávu (' . $r['status'] . ')' . ($err !== '' ? ': ' . mb_substr($err, 0, 180) : '') . '.'];
    }
}

/** Která brána se použije: zkušební prostředí vždy jen záznam. */
function sms_provider_for(string $configured, bool $staging): string
{
    return $staging || !isset(SMS_PROVIDERS[$configured]) ? 'log' : $configured;
}
function sms_provider_name(): string
{
    $configured = function_exists('pset') && function_exists('platform_enabled') && platform_enabled() ? pset('sms_provider') : (string) (app_config()['sms']['provider'] ?? '');
    return sms_provider_for($configured !== '' ? $configured : SMS_DEFAULTS['sms_provider'], is_staging());
}
/**
 * Lze SMS skutečně odeslat? Na ostrém webu jen přes nastavenou bránu: když ji provozovatel v konzoli nezvolil, výchozí „jen záznam“
 * by zprávu neodeslal a přesto by strhl kredit. Zkušební prostředí a výslovně zvolený záznam v konzoli (zkouška) projdou.
 */
function sms_gateway_ready(): bool
{
    if (is_staging() || sms_provider_name() !== 'log') {
        return true;
    }
    $configured = function_exists('pset') && function_exists('platform_enabled') && platform_enabled() ? pset('sms_provider') : (string) (app_config()['sms']['provider'] ?? '');
    return $configured === 'log';
}
/** Chybějící klíče v config.php pro bránu (prázdné pole = vše vyplněno). */
function sms_config_missing(string $provider): array
{
    $c = (array) (app_config()['sms'] ?? []);
    $need = match ($provider) {
        'gosms' => ['gosms_client_id', 'gosms_client_secret', 'gosms_channel'],
        'bulkgate' => ['bulkgate_app_id', 'bulkgate_token'],
        default => [],
    };
    return array_values(array_filter($need, fn($k) => trim((string) ($c[$k] ?? '')) === ''));
}
function sms_provider(?string $name = null): SmsProvider
{
    $name ??= sms_provider_name();
    $c = (array) (app_config()['sms'] ?? []);
    $http = sms_transport();
    return match ($name) {
        'gosms' => new SmsGoSmsProvider((string) ($c['gosms_client_id'] ?? ''), (string) ($c['gosms_client_secret'] ?? ''), (int) ($c['gosms_channel'] ?? 0), $http, (string) ($c['gosms_url'] ?? 'https://app.gosms.eu')),
        'bulkgate' => new SmsBulkGateProvider((string) ($c['bulkgate_app_id'] ?? ''), (string) ($c['bulkgate_token'] ?? ''), (string) ($c['bulkgate_sender'] ?? 'gSystem'), (string) ($c['bulkgate_sender_value'] ?? ''), $http,
            (string) ($c['bulkgate_url'] ?? 'https://portal.bulkgate.com/api/1.0/simple/transactional')),
        default => new SmsLogProvider(STORAGE_DIR . '/sms-log.jsonl'),
    };
}

// ---------- Odeslání ----------

/**
 * Odešle jednu SMS a zapíše ji do záznamu domu. Vrací ['ok' => bool, 'error' => string, 'segments' => int, 'phone' => ?string].
 * $source = kdo posílá (admin, hlasovani…), $userId = příjemce, je-li to uživatel webu.
 */
function sms_send_ex(string $phone, string $text, string $source = 'modul', int $userId = 0): array
{
    $text = trim(str_replace("\r\n", "\n", $text));
    $e164 = sms_normalize($phone);
    $seg = sms_segments($text);
    $fail = fn(string $err) => ['ok' => false, 'error' => $err, 'segments' => $seg['segments'], 'phone' => $e164];
    if (!beta_visible('sms')) {
        return $fail('SMS zprávy nejsou pro tento web zapnuté.');
    }
    if ($e164 === null) {
        return $fail('Telefonní číslo nemá správný tvar.');
    }
    if ($text === '') {
        return $fail('Zpráva je prázdná.');
    }
    if ($seg['segments'] > SMS_MAX_SEGMENTS) {
        return $fail('Zpráva je příliš dlouhá (nejvýš ' . SMS_MAX_SEGMENTS . ' SMS).');
    }
    if (!sms_gateway_ready()) {
        return $fail('SMS brána zatím není nastavená – zprávy se nedají odeslat. Provozovatel ji připravuje.');
    }
    sms_ready();
    $log = function (string $status, string $provider, string $pid, string $err) use ($e164, $userId, $seg, $source, $text): void {
        q('INSERT INTO sms_log (at, phone, user_id, segments, status, provider, provider_id, error, source, text) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [date('Y-m-d H:i:s'), $e164, $userId, $seg['segments'], $status, $provider, mb_substr($pid, 0, 80), mb_substr($err, 0, 255), mb_substr($source, 0, 40), mb_substr($text, 0, 1000)]);
    };
    $managed = sms_credit_managed();
    $note = 'SMS ' . $source . ' → ' . substr($e164, 0, -3) . '***';
    $ref = $managed ? sms_charge(TENANT, $seg['segments'], $note) : '';
    if ($ref === null) {
        $log('refused', '', '', 'nedostatek kreditu');
        return $fail('Došel kredit služeb. Dobijte ho v administraci v části Kredit služeb.');
    }
    $name = sms_provider_name();
    try {
        $r = sms_provider($name)->send($e164, $text, $seg['encoding'] === 'UCS-2');
    } catch (Throwable $e) {
        $r = ['ok' => false, 'id' => '', 'error' => 'Chyba brány: ' . $e->getMessage()];
    }
    if (!$r['ok'] && $managed) {
        credit_reverse(TENANT, $ref, 'vráceno, neodesláno: ' . $note);
    }
    $log($r['ok'] ? 'sent' : 'failed', $name, (string) $r['id'], (string) $r['error']);
    return ['ok' => (bool) $r['ok'], 'error' => (string) $r['error'], 'segments' => $seg['segments'], 'phone' => $e164];
}
/** Veřejná funkce pro ostatní moduly: odešle SMS, je-li funkce zapnutá a stačí kredit. */
function sms_send(string $phone, string $text): bool
{
    return sms_send_ex($phone, $text, (string) ($GLOBALS['SMS_SOURCE'] ?? 'modul'))['ok'];
}

/** Vlastníci s telefonem: [['id', 'name', 'phone' (E.164)]], bez duplicit čísel; [neplatná čísla] zvlášť. */
function sms_owner_recipients(): array
{
    $ok = $bad = $seen = [];
    foreach (all("SELECT id, name, phone FROM users WHERE active = 1 AND kind = 'owner' AND phone <> '' ORDER BY name") as $u) {
        $p = sms_normalize((string) $u['phone']);
        if ($p === null || !sms_is_mobile($p)) {
            $bad[] = $u;
            continue;
        }
        if (isset($seen[$p])) {
            continue;
        }
        $seen[$p] = true;
        $ok[] = ['id' => (int) $u['id'], 'name' => $u['name'], 'phone' => $p];
    }
    return [$ok, $bad];
}
/** Hromadná zpráva vlastníkům: nejdřív ověří kredit na všechny, pak posílá. Vrací [odesláno, neodesláno, chyba|null]. */
function sms_send_owners(string $text): array
{
    [$rcpt] = sms_owner_recipients();
    if (!$rcpt) {
        return [0, 0, 'Žádný vlastník nemá na webu vyplněný mobil.'];
    }
    [, $total] = sms_cost($text, count($rcpt));
    if (sms_credit_managed() && sms_balance() < $total) {
        return [0, 0, 'Kredit služeb nestačí: zpráva pro ' . count($rcpt) . ' vlastníků potřebuje ' . $total . ' SMS, kredit vystačí na ' . sms_balance() . '.'];
    }
    $sent = $failed = 0;
    $err = null;
    foreach ($rcpt as $r) {
        $res = sms_send_ex($r['phone'], $text, 'admin', $r['id']);
        $res['ok'] ? $sent++ : $failed++;
        if (!$res['ok']) {
            $err ??= $res['error'];
        }
    }
    return [$sent, $failed, $failed ? $err : null];
}
