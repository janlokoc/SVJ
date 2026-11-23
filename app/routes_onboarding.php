<?php
declare(strict_types=1);

// Průvodce pro nového správce: co je potřeba udělat, než web ukáže sousedům. U každého kroku semafor –
// hotovo (pozná se samo podle obsahu webu, nebo to správce odškrtne), čeká, nebo přeskočeno.

const ONBOARDING_GROUPS = ['zaklad' => 'Základ webu', 'dum' => 'Dům a lidé', 'obsah' => 'Obsah', 'start' => 'Před spuštěním'];

/**
 * Kroky průvodce: klíč => [skupina, název, proč, odkaz, text odkazu, splněno podle obsahu webu, důležité].
 */
function onboarding_steps(): array
{
    $n = fn(string $sql, array $p = []) => (int) val($sql, $p);
    $me = function_exists('user') ? (user() ?: []) : [];
    $steps = [
        'udaje' => ['zaklad', 'Údaje společenství', 'Celý název, adresa a IČO se zobrazují v patičce, v kontaktech a na fakturách za web.',
            '/admin/nastaveni', 'Otevřít nastavení', setting('address') !== '' && setting('ico') !== '', true],
        'vzhled' => ['zaklad', 'Vzhled a logo', 'Vyberte šablonu a barvu, případně nahrajte logo domu. Sousedé web poznají na první pohled.',
            '/admin/nastaveni', 'Upravit vzhled', setting('logo') !== '', false],
        'overeni' => ['zaklad', 'Dvoufázové ověření správce', 'Účet správce vidí osobní údaje všech obyvatel. Druhý krok při přihlášení ho ochrání, i kdyby uniklo heslo.',
            '/muj-ucet#overeni', 'Zapnout ověření', (int) ($me['totp_enabled'] ?? 0) === 1, true],
        'jednotky' => ['dum', 'Seznam jednotek', 'Jednotky s vlastníky a podíly založíte naráz z výpisu z katastru nemovitostí. Bez nich nejde přiřadit účty k bytům, hlasovat podle podílů ani evidovat odečty.',
            '/admin/jednotky/import', 'Import z katastru', $n('SELECT COUNT(*) FROM units') > 0, true],
        'ucty' => ['dum', 'Účty obyvatel', 'Vlastníky a nájemníky přidáte hromadně z tabulky, nebo zapnete odkaz, přes který se k jednotce přihlásí sami a Vy jen schválíte. Každému pak přijde e-mail s odkazem na nastavení hesla.',
            '/admin/uzivatele/zadosti', 'Přidat obyvatele', $n('SELECT COUNT(*) FROM users WHERE active = 1') > 1, true],
        'vybor' => ['dum', 'Kdo je ve výboru', 'U účtů členů výboru a kontrolní komise vyplňte funkci. Sousedé pak ví, na koho se obrátit.',
            '/admin/uzivatele', 'Otevřít uživatele', $n("SELECT COUNT(*) FROM users WHERE position <> ''") > 0, false],
        'kontakty' => ['dum', 'Kontakty a havarijní čísla', 'Výbor, správce domu, havarijní služba, výtahy. Nejčastější důvod, proč lidé web otevřou.',
            '/admin/kontakty', 'Přidat kontakty', $n('SELECT COUNT(*) FROM contacts') > 0, true],
        'dokumenty' => ['obsah', 'Základní dokumenty', 'Stanovy, domovní řád, poslední zápis ze shromáždění a vyúčtování. Více souborů nahrajete najednou.',
            '/admin/dokumenty/hromadne', 'Nahrát dokumenty', $n('SELECT COUNT(*) FROM documents') > 0, true],
        'aktualita' => ['obsah', 'První vlastní aktualita', 'Napište sousedům, k čemu web slouží a co na něm najdou. Uvítací text z registrace můžete přepsat.',
            '/admin/aktuality/nova', 'Napsat aktualitu', $n("SELECT COUNT(*) FROM posts WHERE title <> 'Vítejte na novém webu'") > 0, false],
        'termin' => ['obsah', 'Nejbližší termín', 'Shromáždění, odečty, revize nebo úklid. Termíny se ukazují na úvodní stránce a jdou přidat do kalendáře.',
            '/admin/terminy/novy', 'Přidat termín', $n('SELECT COUNT(*) FROM events') > 0, false],
        'prevod' => ['obsah', 'Obsah ze starého webu', 'Máte starší web? Dokumenty a aktuality můžete přenést sami, nebo si převod objednat. Pokud žádný starý web nemáte, krok přeskočte.',
            '/admin/predplatne#prevod', 'Objednat převod', function_exists('migration_row') && defined('TENANT') && TENANT !== '' && (migration_row(TENANT) !== null || tenant_migrated(TENANT) !== ''), false],
        'udaje-ochrana' => ['start', 'Ochrana osobních údajů', 'Přečtěte si text stránky „Ochrana osobních údajů“ a doplňte, kdo je ve Vašem společenství kontaktní osobou.',
            '/admin/nastaveni', 'Zkontrolovat text', false, true],
        'zastup' => ['start', 'Zástup správce', 'Druhý účet správce se hodí při dovolené nebo nemoci. Web nesmí stát na jednom člověku.',
            '/admin/uzivatele', 'Přidat správce', $n("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1") > 1, false],
        'oznameni' => ['start', 'Dát vědět sousedům', 'Až je web připravený, pošlete všem e-mail s adresou webu a vyvěste ji na nástěnku ve vchodu.',
            '/admin/zpravy/nova', 'Napsat e-mail', $n('SELECT COUNT(*) FROM mailings') > 0, true],
    ];
    // Edice Zdarma: vzhled se nenastavuje a druhého správce edice nedovolí – takové kroky se nenabízejí.
    if (function_exists('plan_managed') && plan_managed() && !plan_has('sablony')) {
        unset($steps['vzhled']);
    }
    if (function_exists('plan_limit') && plan_limit('admins') === 1) {
        unset($steps['zastup']);
    }
    // Druhého správce edice Zdarma neumožňuje – krok by jen mátl.
    if (function_exists('plan_limit') && plan_managed() && plan_limit('admins') === 1) {
        unset($steps['zastup']);
    }
    $out = [];
    foreach ($steps as $key => [$group, $title, $why, $href, $label, $auto, $important]) {
        $manual = setting('onb_' . $key);
        $state = $auto || $manual === 'done' ? 'done' : ($manual === 'skip' ? 'skip' : 'wait');
        $out[$key] = ['key' => $key, 'group' => $group, 'title' => $title, 'why' => $why, 'href' => $href, 'label' => $label,
            'auto' => (bool) $auto, 'important' => $important, 'state' => $state];
    }
    return $out;
}
/** Souhrn pro přehled administrace a menu. */
function onboarding_summary(): array
{
    $steps = onboarding_steps();
    $done = count(array_filter($steps, fn($s) => $s['state'] !== 'wait'));
    $waiting = array_values(array_filter($steps, fn($s) => $s['state'] === 'wait'));
    usort($waiting, fn($a, $b) => (int) $b['important'] <=> (int) $a['important']);
    return ['steps' => $steps, 'total' => count($steps), 'done' => $done, 'waiting' => $waiting,
        'important' => count(array_filter($waiting, fn($s) => $s['important'])), 'hidden' => setting('onb_hidden') === '1'];
}
function onboarding_save(string $key, string $value): void
{
    q('DELETE FROM settings WHERE `key` = ?', ['onb_' . $key]);
    if ($value !== '') {
        q('INSERT INTO settings (`key`, value) VALUES (?,?)', ['onb_' . $key, $value]);
    }
    setting('__reset');
}

$route('GET', '/admin/pruvodce', function () {
    require_admin();
    render('admin/onboarding', ['title' => 'Průvodce nastavením', 'o' => onboarding_summary()]);
});

$route('POST', '/admin/pruvodce/skryt', function () {
    require_admin();
    onboarding_save('hidden', isset($_POST['show']) ? '' : '1');
    flash(isset($_POST['show']) ? 'Průvodce se znovu ukazuje na přehledu.' : 'Průvodce je z přehledu skrytý. Najdete ho dál v menu Správa webu.', 'info');
    redirect(isset($_POST['show']) ? '/admin/pruvodce' : '/admin');
});

$route('POST', '/admin/pruvodce/([a-z-]+)', function (string $key) {
    require_admin();
    $steps = onboarding_steps();
    $to = post('state');
    if (isset($steps[$key]) && in_array($to, ['done', 'skip', 'wait'], true)) {
        onboarding_save($key, $to === 'wait' ? '' : $to);
        audit('Průvodce nastavením', $steps[$key]['title'] . ': ' . ['done' => 'označeno jako hotové', 'skip' => 'přeskočeno', 'wait' => 'vráceno mezi čekající'][$to]);
    }
    redirect('/admin/pruvodce#krok-' . $key);
});
