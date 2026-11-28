<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Konzole → Nastavení služby: dlouhá stránka rozdělená do záložek. Jeden formulář zůstává (skryté části se odesílají
 * také, nic se neztratí); každá část patří do existující záložky, každá záložka má obsah; odkazy z kontrolního
 * seznamu (#provozovatel, #platby…) a přesměrování (#isir, #beta, #prenos, #ai-test) míří na prvky uvnitř části.
 */
final class ConsoleSettingsTabsTest extends TestCase
{
    public function testEveryPartBelongsToATabAndAnchorsResolve(): void
    {
        $root = dirname(__DIR__, 2);
        $s = (string) file_get_contents($root . '/app/views/platform/settings.php');
        preg_match("~\\[('sluzba' => .*?)\\] as \\\$gk~", $s, $m);
        preg_match_all("~'([a-z]+)' => '~", $m[1], $tabs);
        preg_match_all('~data-group="([a-z]+)"~', $s, $groups);
        $this->assertSame([], array_diff($groups[1], $tabs[1]), 'část bez záložky');
        $this->assertSame([], array_diff($tabs[1], $groups[1]), 'prázdná záložka');
        $this->assertSame(1, substr_count($s, '<form method="post" class="card form" id="set-form">'), 'jeden formulář pro všechny části');
        // Všechna pole hlavního formuláře jsou uvnitř části (vykreslená stránka, rozbor přes DOM).
        preg_match_all("~\\\$v\\['([a-z0-9_]+)'~", $s . file_get_contents($root . '/app/views/ext/pricing/settings.php'), $keys);
        $v = [];
        foreach (array_unique($keys[1]) as $k) {
            $v[$k] = pset($k);
        }
        foreach (EDITIONS as $ek => $en) {
            foreach (PLAN_LIMITS as $lk => $lim) {
                $v['lim_' . $ek . '_' . $lk] = '0';
            }
        }
        $errors = [];
        $src = $s;
        ob_start();
        require $root . '/app/views/platform/settings.php';
        $html = (string) ob_get_clean();
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8">' . $html);
        $xp = new \DOMXPath($dom);
        $fields = $xp->query('//form[@id="set-form"]//*[self::input or self::textarea or self::select][not(@type="hidden")]');
        $this->assertGreaterThan(40, $fields->length);
        $loose = $xp->query('//form[@id="set-form"]//*[self::input or self::textarea or self::select][not(@type="hidden")][not(ancestor::*[contains(concat(" ", @class, " "), " set-part ")])]');
        $this->assertSame(0, $loose->length, 'pole mimo záložky by nebylo vidět');
        foreach ($xp->query('//*[contains(concat(" ", @class, " "), " set-part ")]') as $part) {
            $this->assertContains($part->getAttribute('data-group'), $tabs[1]);
        }
        foreach (['provozovatel', 'platby', 'faktury', 'kontakt', 'isir', 'beta', 'prenos', 'ai-test', 'podminky'] as $id) {
            $this->assertMatchesRegularExpression('~id="' . $id . '"~', $src, $id);
        }
        $js = (string) file_get_contents($root . '/assets/app.js');
        $this->assertStringContainsString("form.addEventListener('invalid'", $js, 'chyba v jiné záložce ji otevře');
        $this->assertStringContainsString("document.querySelector('.set-tabs')", $js);
    }
}
