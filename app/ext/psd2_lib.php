<?php
declare(strict_types=1);

/*
 * Propojení s účty u dalších bank přes PSD2 agregátor (funkce v betě 'bank-psd2', výchozí stav vypnuto).
 *
 * Fio má vlastní zdarma API (app/bank_lib.php). Ostatní české banky (ČS, KB, ČSOB, Raiffeisenbank, Moneta, UniCredit,
 * Air Bank, mBank…) zpřístupňují pohyby jen licencovaným poskytovatelům (AISP). Web proto používá agregátor
 * GoCardless Bank Account Data (dříve Nordigen), který licenci má:
 *   dokumentace https://developer.gocardless.com/bank-account-data/overview
 *   rychlý start https://docs.gocardless.com/bank-account-data/quick-start-guide
 *
 * Postup: token (POST /token/new/, /token/refresh/) → seznam bank pro CZ (GET /institutions/?country=cz) →
 * souhlas koncového uživatele (POST /agreements/enduser/, nejvýš 90 dní) → requisition s návratem na
 * /admin/platby/banky/navrat (POST /requisitions/) → výbor se přihlásí do své banky a souhlasí → GET /requisitions/{id}/
 * vrátí účty → GET /accounts/{id}/ (IBAN) → GET /accounts/{id}/transactions/ → bank_store().
 *
 * Omezení: banky dovolují stáhnout pohyby nejvýš 4× denně na účet – web si počítá pokusy (PSD2_DAILY_LIMIT).
 * Souhlas platí 90 dní; 14 a 3 dny před koncem web upozorní správce e-mailem, po vypršení stačí propojení obnovit.
 *
 * Přístupové údaje provozovatele jsou jen v storage/config.php:
 *   'bank' => ['gocardless_id' => '…secret_id…', 'gocardless_key' => '…secret_key…'],   // volitelně 'gocardless_url'
 * Krátkodobé tokeny API se ukládají do storage/psd2-token.json (práva 0600), ne do databáze.
 *
 * Neověřeno proti skutečné službě (bez účtu provozovatele) – implementováno a testováno podle veřejné dokumentace.
 */

const PSD2_DAILY_LIMIT = 4;
const PSD2_CONSENT_DAYS = 90;
const PSD2_WARN_DAYS = [14, 3];
/** Stavy requisition podle dokumentace: CR vytvořeno, GC/UA/SA/GA probíhá souhlas, LN propojeno, RJ odmítnuto, EX vypršelo, SU pozastaveno. */
const PSD2_REQ_STATES = ['CR' => 'čeká na souhlas v bance', 'GC' => 'probíhá souhlas', 'UA' => 'probíhá ověření v bance', 'SA' => 'výběr účtů', 'GA' => 'udělení přístupu',
    'LN' => 'propojeno', 'RJ' => 'banka propojení odmítla', 'EX' => 'souhlas vypršel', 'SU' => 'pozastaveno', 'ID' => 'čeká na souhlas v bance', 'ER' => 'chyba'];

/** Klíče a adresa API z config.php. */
function psd2_config(?array $override = null, bool $reset = false): array
{
    static $test = null;   // jen pro testy: náhrada klíčů z config.php
    if ($reset) {
        $test = null;
    }
    if ($override !== null) {
        $test = $override;
    }
    $b = $test ?? (array) (app_config()['bank'] ?? []);
    return ['id' => trim((string) ($b['gocardless_id'] ?? '')), 'key' => trim((string) ($b['gocardless_key'] ?? '')),
        'url' => rtrim((string) ($b['gocardless_url'] ?? 'https://bankaccountdata.gocardless.com/api/v2'), '/')];
}
function psd2_configured(): bool
{
    $c = psd2_config();
    return $c['id'] !== '' && $c['key'] !== '';
}
/** HTTP pro agregátor: fn(method, url, headers, ?body): ['status', 'body', 'headers']. V testech se nahradí. */
function psd2_transport(?callable $set = null, bool $reset = false): callable
{
    static $t = null;
    if ($reset) {
        $t = null;
    }
    if ($set !== null) {
        $t = $set;
    }
    return $t ??= fn(string $m, string $u, array $h, ?string $b) => modc_http($m, $u, $h, $b, 30);
}

/** Klient GoCardless Bank Account Data (API v2). Odpověď: ['status' => int, 'json' => mixed, 'headers' => array]. */
final class GoCardlessClient
{
    public function __construct(private string $id, private string $key, private $http, private string $base, private string $tokenFile)
    {
    }
    private function raw(string $method, string $path, ?array $body, ?string $token): array
    {
        $h = ['Accept: application/json'];
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
        }
        if ($token !== null) {
            $h[] = 'Authorization: Bearer ' . $token;
        }
        $r = ($this->http)($method, $this->base . $path, $h, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES) : null);
        return ['status' => (int) ($r['status'] ?? 0), 'json' => json_decode((string) ($r['body'] ?? ''), true), 'headers' => (array) ($r['headers'] ?? [])];
    }
    /** Přístupový token: z mezipaměti, obnovou (refresh platí 30 dní), nebo nově z secret_id/secret_key. */
    public function token(bool $fresh = false): ?string
    {
        $c = is_file($this->tokenFile) ? (array) json_decode((string) file_get_contents($this->tokenFile), true) : [];
        $now = time();
        if (!$fresh && ($c['id'] ?? '') === md5($this->id) && ($c['access_until'] ?? 0) > $now + 60) {
            return (string) $c['access'];
        }
        $r = null;
        if (($c['id'] ?? '') === md5($this->id) && ($c['refresh_until'] ?? 0) > $now + 60) {
            $r = $this->raw('POST', '/token/refresh/', ['refresh' => (string) $c['refresh']], null);
            if ($r['status'] === 200 && is_string($r['json']['access'] ?? null)) {
                $c['access'] = $r['json']['access'];
                $c['access_until'] = $now + (int) ($r['json']['access_expires'] ?? 86400);
            } else {
                $r = null;
            }
        }
        if ($r === null) {
            $r = $this->raw('POST', '/token/new/', ['secret_id' => $this->id, 'secret_key' => $this->key], null);
            if ($r['status'] !== 200 || !is_string($r['json']['access'] ?? null)) {
                return null;
            }
            $c = ['id' => md5($this->id), 'access' => $r['json']['access'], 'access_until' => $now + (int) ($r['json']['access_expires'] ?? 86400),
                'refresh' => (string) ($r['json']['refresh'] ?? ''), 'refresh_until' => $now + (int) ($r['json']['refresh_expires'] ?? 0)];
        }
        if (@file_put_contents($this->tokenFile, json_encode($c), LOCK_EX) !== false) {
            @chmod($this->tokenFile, 0600);
        }
        return (string) $c['access'];
    }
    /** Volání s tokenem; při 401 jednou zkusí nový token. */
    public function call(string $method, string $path, ?array $body = null): array
    {
        $token = $this->token();
        if ($token === null) {
            return ['status' => -1, 'json' => null, 'headers' => []];
        }
        $r = $this->raw($method, $path, $body, $token);
        if ($r['status'] === 401 && ($token = $this->token(true)) !== null) {
            $r = $this->raw($method, $path, $body, $token);
        }
        return $r;
    }
}
function psd2_client(): GoCardlessClient
{
    $c = psd2_config();
    return new GoCardlessClient($c['id'], $c['key'], psd2_transport(), $c['url'], ROOT_STORAGE . '/psd2-token.json');
}
/** Srozumitelná hláška k chybové odpovědi API. */
function psd2_error(array $r): string
{
    $j = is_array($r['json']) ? $r['json'] : [];
    $detail = trim((string) ($j['summary'] ?? '') . ' ' . (string) ($j['detail'] ?? ''));
    return match (true) {
        $r['status'] === -1 => 'Přihlášení k agregátoru se nepovedlo – provozovatel musí zkontrolovat klíče (bank.gocardless_id, bank.gocardless_key).',
        $r['status'] === 0 => 'Agregátor bankovních dat teď neodpovídá. Zkuste to později.',
        $r['status'] === 429 => 'Banka dnes další stažení nedovolí (nejvýš ' . PSD2_DAILY_LIMIT . '× denně na účet). Zkusíme to zítra.',
        default => 'Agregátor odpověděl chybou ' . $r['status'] . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : '') . '.',
    };
}

// ---------- Tabulky domu ----------

function psd2_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $exists = $mysql ? (bool) $pdo->query("SHOW TABLES LIKE 'psd2_links'")->fetch() : (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'psd2_links'")->fetch();
    if ($exists) {
        return;
    }
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT' : 'INTEGER';
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $eng = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE psd2_links (id $pk, requisition_id {$s(64)}, agreement_id {$s(64)}, institution_id {$s(80)}, institution_name {$s(160)}, reference {$s(64)},"
        . " status {$s(12)}, created_at {$s(19)}, valid_until {$s(10)}, hist_days $int NOT NULL DEFAULT 90, warned $int NOT NULL DEFAULT 0, last_result {$s(255)}, created_by {$s(191)})$eng");
    $pdo->exec("CREATE TABLE psd2_accounts (id $pk, link_id $int NOT NULL, account_id {$s(64)}, iban {$s(40)}, owner {$s(160)}, fetch_day {$s(10)}, fetch_count $int NOT NULL DEFAULT 0,"
        . " last_fetch {$s(19)}, last_result {$s(255)})$eng");
    $pdo->exec('CREATE UNIQUE INDEX psd2_links_ref ON psd2_links (reference)');
    $pdo->exec('CREATE INDEX psd2_accounts_link ON psd2_accounts (link_id)');
}
function psd2_links(): array
{
    psd2_ready();
    $links = all('SELECT * FROM psd2_links ORDER BY id DESC');
    foreach ($links as &$l) {
        $l['accounts'] = all('SELECT * FROM psd2_accounts WHERE link_id = ? ORDER BY id', [$l['id']]);
        $l['days_left'] = $l['valid_until'] !== '' ? (int) floor((strtotime($l['valid_until']) - strtotime(date('Y-m-d'))) / 86400) : null;
    }
    return $links;
}

// ---------- Převod dat z banky ----------

/** České číslo účtu (předčíslí-číslo/banka) z IBAN CZ; jiný IBAN vrátí beze změny. */
function psd2_iban_to_cz(string $iban): string
{
    $i = strtoupper(preg_replace('/\s+/', '', $iban));
    if (!preg_match('/^CZ\d{2}(\d{4})(\d{6})(\d{10})$/', $i, $m)) {
        return $i;
    }
    $prefix = ltrim($m[2], '0');
    return ($prefix !== '' ? $prefix . '-' : '') . ltrim($m[3], '0') . '/' . $m[1];
}
/**
 * Variabilní symbol z pohybu. České banky ho přes PSD2 předávají různě: v endToEndId („/VS1234/SS/KS“ nebo jen číslice),
 * ve strukturované informaci pro příjemce, nebo v textu („VS: 1234“). Neověřeno u všech bank – nespárované platby jde přiřadit ručně.
 */
function psd2_vs(array $t): string
{
    $texts = [];
    foreach (['endToEndId', 'remittanceInformationStructured', 'creditorReference', 'remittanceInformationUnstructured', 'additionalInformation', 'entryReference'] as $k) {
        if (is_string($t[$k] ?? null) && $t[$k] !== '') {
            $texts[$k] = $t[$k];
        }
    }
    foreach ((array) ($t['remittanceInformationUnstructuredArray'] ?? []) as $x) {
        $texts[] = (string) $x;
    }
    foreach ((array) ($t['remittanceInformationStructuredArray'] ?? []) as $x) {
        $texts[] = is_array($x) ? (string) ($x['reference'] ?? '') : (string) $x;
    }
    foreach ($texts as $s) {
        if (preg_match('~(?:^|[^A-Z])VS[\s:.=/-]*0*(\d{1,10})(?!\d)~i', $s, $m)) {
            return bank_vs($m[1]);
        }
    }
    // endToEndId nebo strukturovaná reference jen z číslic (do 10) = VS.
    foreach (['endToEndId', 'remittanceInformationStructured'] as $k) {
        if (isset($texts[$k]) && preg_match('/^\d{1,10}$/', trim($texts[$k])) && trim($texts[$k]) !== 'NOTPROVIDED') {
            return bank_vs($texts[$k]);
        }
    }
    return '';
}
/** Pohyb z API → řádek pro bank_store(); jen přijaté zaúčtované platby (jinak null). */
function psd2_map_tx(array $t, string $accountId): ?array
{
    $amount = (float) ($t['transactionAmount']['amount'] ?? 0);
    $date = (string) ($t['bookingDate'] ?? ($t['valueDate'] ?? ''));
    if ($amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
        return null;
    }
    $cur = (string) ($t['transactionAmount']['currency'] ?? 'CZK');
    if ($cur !== '' && $cur !== 'CZK') {
        return null;   // zálohy jsou v korunách; cizí měna se nepáruje
    }
    $raw = (string) ($t['transactionId'] ?? ($t['internalTransactionId'] ?? ($t['entryReference'] ?? '')));
    if ($raw === '') {
        $raw = md5(json_encode($t));
    }
    $id = 'psd2:' . substr(md5($accountId), 0, 6) . ':' . (strlen($raw) > 66 ? md5($raw) : $raw);
    $msg = (string) ($t['remittanceInformationUnstructured'] ?? implode(' ', array_map('strval', (array) ($t['remittanceInformationUnstructuredArray'] ?? []))));
    $acc = (string) ($t['debtorAccount']['iban'] ?? ($t['debtorAccount']['bban'] ?? ''));
    return ['id' => $id, 'date' => substr($date, 0, 10), 'amount' => $amount, 'vs' => psd2_vs($t), 'account' => $acc !== '' ? psd2_iban_to_cz($acc) : '',
        'name' => (string) ($t['debtorName'] ?? ($t['ultimateDebtor'] ?? '')), 'message' => trim($msg)];
}

// ---------- Banky a propojení ----------

/** Banky pro Česko: [['id', 'name', 'bic', 'days', 'access_days', 'logo']]. Seznam se drží den v storage. Chyba = string. */
function psd2_institutions(string $country = 'CZ'): array|string
{
    $file = ROOT_STORAGE . '/psd2-institutions-' . strtolower($country) . '.json';
    if (is_file($file) && filemtime($file) > time() - 86400) {
        $cached = json_decode((string) file_get_contents($file), true);
        if (is_array($cached) && $cached) {
            return $cached;
        }
    }
    if (!psd2_configured()) {
        return 'Propojení s bankami zatím není u služby nastavené.';
    }
    $r = psd2_client()->call('GET', '/institutions/?country=' . rawurlencode(strtolower($country)));
    if ($r['status'] !== 200 || !is_array($r['json'])) {
        return psd2_error($r);
    }
    $out = [];
    foreach ($r['json'] as $i) {
        if (!is_array($i) || !is_string($i['id'] ?? null)) {
            continue;
        }
        $out[] = ['id' => $i['id'], 'name' => (string) ($i['name'] ?? $i['id']), 'bic' => (string) ($i['bic'] ?? ''), 'days' => (int) ($i['transaction_total_days'] ?? 90),
            'access_days' => (int) ($i['max_access_valid_for_days'] ?? PSD2_CONSENT_DAYS), 'logo' => (string) ($i['logo'] ?? '')];
    }
    $coll = class_exists('Collator') ? new Collator('cs_CZ') : null;   // české řazení (Č po C)
    usort($out, fn($a, $b) => $coll ? (int) $coll->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));
    @file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $out;
}
/** Založí souhlas a requisition. Vrací adresu banky, kam správce přesměrovat, nebo chybu (string začínající „!“ se nepoužívá). */
function psd2_connect(string $institutionId, array $by): array
{
    psd2_ready();
    $list = psd2_institutions();
    if (is_string($list)) {
        return ['error' => $list];
    }
    $inst = null;
    foreach ($list as $i) {
        if ($i['id'] === $institutionId) {
            $inst = $i;
        }
    }
    if (!$inst) {
        return ['error' => 'Vyberte banku ze seznamu.'];
    }
    $hist = max(1, min(PSD2_CONSENT_DAYS, $inst['days'] ?: PSD2_CONSENT_DAYS));
    $valid = max(1, min(PSD2_CONSENT_DAYS, $inst['access_days'] ?: PSD2_CONSENT_DAYS));
    $api = psd2_client();
    $a = $api->call('POST', '/agreements/enduser/', ['institution_id' => $institutionId, 'max_historical_days' => $hist, 'access_valid_for_days' => $valid, 'access_scope' => ['details', 'transactions']]);
    if (!in_array($a['status'], [200, 201], true) || !is_string($a['json']['id'] ?? null)) {
        return ['error' => psd2_error($a)];
    }
    $ref = 'svj-' . bin2hex(random_bytes(10));
    $r = $api->call('POST', '/requisitions/', ['redirect' => absolute_url('/admin/platby/banky/navrat'), 'institution_id' => $institutionId, 'reference' => $ref,
        'agreement' => $a['json']['id'], 'user_language' => 'CS']);
    $link = (string) ($r['json']['link'] ?? '');
    if (!in_array($r['status'], [200, 201], true) || !is_string($r['json']['id'] ?? null) || !preg_match('~^https://~', $link)) {
        return ['error' => psd2_error($r)];
    }
    q('INSERT INTO psd2_links (requisition_id, agreement_id, institution_id, institution_name, reference, status, created_at, valid_until, hist_days, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$r['json']['id'], $a['json']['id'], $institutionId, mb_substr($inst['name'], 0, 160), $ref, 'CR', date('Y-m-d H:i:s'), date('Y-m-d', strtotime('+' . $valid . ' day')), $hist, mb_substr((string) ($by['name'] ?? ''), 0, 191)]);
    return ['link' => $link];
}
/** Návrat z banky (?ref=…): načte účty a poprvé stáhne pohyby. Vrací [ok, zpráva]. */
function psd2_callback(string $ref, string $error = ''): array
{
    psd2_ready();
    $l = one('SELECT * FROM psd2_links WHERE reference = ?', [$ref]);
    if (!$l) {
        return [false, 'Propojení jsme nenašli. Zkuste banku připojit znovu.'];
    }
    $api = psd2_client();
    $r = $api->call('GET', '/requisitions/' . rawurlencode($l['requisition_id']) . '/');
    if ($r['status'] !== 200 || !is_array($r['json'])) {
        q('UPDATE psd2_links SET last_result = ? WHERE id = ?', [mb_substr(psd2_error($r), 0, 255), $l['id']]);
        return [false, psd2_error($r)];
    }
    $status = (string) ($r['json']['status'] ?? '');
    $accounts = array_values(array_filter((array) ($r['json']['accounts'] ?? []), 'is_string'));
    if ($status !== 'LN' || !$accounts) {
        $msg = $error !== '' || $status === 'RJ' ? 'Propojení s bankou se nedokončilo (' . ($error !== '' ? mb_substr($error, 0, 60) : PSD2_REQ_STATES[$status]) . '). Můžete to zkusit znovu.'
            : 'Banka propojení zatím nepotvrdila (' . (PSD2_REQ_STATES[$status] ?? $status) . ').';
        q('UPDATE psd2_links SET status = ?, last_result = ? WHERE id = ?', [mb_substr($status !== '' ? $status : 'ER', 0, 12), mb_substr($msg, 0, 255), $l['id']]);
        return [false, $msg];
    }
    q('DELETE FROM psd2_accounts WHERE link_id = ?', [$l['id']]);
    foreach ($accounts as $acc) {
        $d = $api->call('GET', '/accounts/' . rawurlencode($acc) . '/');   // metadata účtu (IBAN) – podle dokumentace bez denního limitu
        q('INSERT INTO psd2_accounts (link_id, account_id, iban, owner) VALUES (?,?,?,?)', [$l['id'], mb_substr($acc, 0, 64), mb_substr((string) ($d['json']['iban'] ?? ''), 0, 40), mb_substr((string) ($d['json']['owner_name'] ?? ''), 0, 160)]);
    }
    q("UPDATE psd2_links SET status = 'LN', warned = 0 WHERE id = ?", [$l['id']]);
    [$new, $matched, $notes] = psd2_refresh((int) $l['id']);
    return [true, 'Účet u banky ' . $l['institution_name'] . ' je propojený (' . count($accounts) . ' ' . (count($accounts) === 1 ? 'účet' : 'účty') . "). Nových plateb $new, spárováno $matched." . ($notes ? ' ' . implode(' ', $notes) : '')];
}
/**
 * Stáhne pohyby všech účtů propojení (nejvýš PSD2_DAILY_LIMIT× denně na účet) a uloží je přes bank_store().
 * Vrací [nových, spárovaných, poznámky].
 */
function psd2_refresh(int $linkId): array
{
    psd2_ready();
    bank_ready();
    $l = one('SELECT * FROM psd2_links WHERE id = ?', [$linkId]);
    if (!$l || $l['status'] !== 'LN') {
        return [0, 0, ['Propojení není aktivní.']];
    }
    if ($l['valid_until'] !== '' && $l['valid_until'] < date('Y-m-d')) {
        q("UPDATE psd2_links SET status = 'EX', last_result = ? WHERE id = ?", ['Souhlas s přístupem k účtu vypršel – obnovte propojení.', $linkId]);
        return [0, 0, ['Souhlas s přístupem k účtu u banky ' . $l['institution_name'] . ' vypršel – obnovte propojení.']];
    }
    $api = psd2_client();
    $today = date('Y-m-d');
    $new = $matched = 0;
    $notes = [];
    foreach (all('SELECT * FROM psd2_accounts WHERE link_id = ?', [$linkId]) as $a) {
        $count = $a['fetch_day'] === $today ? (int) $a['fetch_count'] : 0;
        if ($count >= PSD2_DAILY_LIMIT) {
            $notes[] = 'Účet ' . ($a['iban'] !== '' ? psd2_iban_to_cz($a['iban']) : '') . ' už dnes byl stažen ' . PSD2_DAILY_LIMIT . '× (víc banka nedovolí), další stažení zítra.';
            continue;
        }
        $from = $a['last_fetch'] !== '' ? date('Y-m-d', strtotime(substr($a['last_fetch'], 0, 10) . ' -5 day')) : date('Y-m-d', strtotime('-' . (int) $l['hist_days'] . ' day +1 day'));
        $r = $api->call('GET', '/accounts/' . rawurlencode($a['account_id']) . '/transactions/?date_from=' . $from . '&date_to=' . $today);
        $count = $r['status'] === 429 ? PSD2_DAILY_LIMIT : ($r['status'] <= 0 ? $count : $count + 1);   // nepovedené přihlášení se nepočítá
        if ($r['status'] !== 200 || !is_array($r['json']['transactions'] ?? null)) {
            $err = psd2_error($r);
            if (in_array($r['status'], [401, 403], true)) {
                q("UPDATE psd2_links SET status = 'EX' WHERE id = ?", [$linkId]);
                $err = 'Banka přístup k účtu už nepovoluje (souhlas vypršel nebo byl odvolán) – obnovte propojení.';
            }
            q('UPDATE psd2_accounts SET fetch_day = ?, fetch_count = ?, last_result = ? WHERE id = ?', [$today, $count, mb_substr($err, 0, 255), $a['id']]);
            $notes[] = $err;
            continue;
        }
        $txs = [];
        foreach ((array) ($r['json']['transactions']['booked'] ?? []) as $t) {
            if (is_array($t) && ($m = psd2_map_tx($t, $a['account_id']))) {
                $txs[] = $m;
            }
        }
        [$n, $mt] = bank_store($txs, 'psd2');
        $new += $n;
        $matched += $mt;
        q('UPDATE psd2_accounts SET fetch_day = ?, fetch_count = ?, last_fetch = ?, last_result = ? WHERE id = ?', [$today, $count, date('Y-m-d H:i:s'), "nových $n, spárováno $mt", $a['id']]);
    }
    q('UPDATE psd2_links SET last_result = ? WHERE id = ?', [mb_substr(date('Y-m-d H:i') . ': nových ' . $new . ', spárováno ' . $matched . ($notes ? ' · ' . implode(' ', $notes) : ''), 0, 255), $linkId]);
    return [$new, $matched, $notes];
}
/** Zruší propojení (u agregátoru i na webu). */
function psd2_delete(int $linkId): void
{
    psd2_ready();
    $l = one('SELECT * FROM psd2_links WHERE id = ?', [$linkId]);
    if (!$l) {
        return;
    }
    if ($l['requisition_id'] !== '' && psd2_configured()) {
        psd2_client()->call('DELETE', '/requisitions/' . rawurlencode($l['requisition_id']) . '/');   // chyba nevadí: souhlas stejně vyprší
    }
    q('DELETE FROM psd2_accounts WHERE link_id = ?', [$linkId]);
    q('DELETE FROM psd2_links WHERE id = ?', [$linkId]);
}
/** Je funkce pro tento web k dispozici (beta, edice s Platbami, klíče u provozovatele)? */
function psd2_available(): bool
{
    return beta_visible('bank-psd2') && (!function_exists('plan_has') || plan_has('platby'));
}
/**
 * Denně (z bank_daily): vypršení souhlasu, upozornění 14 a 3 dny předem a stažení pohybů.
 * Vrací počet zpracovaných propojení.
 */
function psd2_daily(): int
{
    if (!psd2_available() || !psd2_configured()) {
        return 0;
    }
    psd2_ready();
    $done = 0;
    foreach (all("SELECT * FROM psd2_links WHERE status = 'LN'") as $l) {
        $left = $l['valid_until'] !== '' ? (int) floor((strtotime($l['valid_until']) - strtotime(date('Y-m-d'))) / 86400) : 999;
        $stage = 0;
        foreach (PSD2_WARN_DAYS as $d) {
            if ($left <= $d && $left >= 0 && ((int) $l['warned'] === 0 || (int) $l['warned'] > $d)) {
                $stage = $d;
            }
        }
        if ($stage) {
            $text = "Dobrý den,\n\nsouhlas s automatickým stahováním plateb z účtu u banky " . $l['institution_name'] . ' vyprší ' . cz_date($l['valid_until'])
                . ".\nBanky ho dovolují udělit nejvýš na " . PSD2_CONSENT_DAYS . " dní. Obnovíte ho jedním přihlášením do bankovnictví: " . absolute_url('/admin/platby/banky')
                . "\n\nBez obnovení se platby přestanou párovat samy a výpisy bude potřeba nahrávat ručně.\n\n" . site('name');
            foreach (all("SELECT email FROM users WHERE role = 'admin' AND active = 1") as $admin) {
                send_mail($admin['email'], 'Obnovte propojení s bankou – ' . site('name'), $text);
            }
            q('UPDATE psd2_links SET warned = ? WHERE id = ?', [$stage, $l['id']]);
        }
        psd2_refresh((int) $l['id']);
        $done++;
    }
    return $done;
}
