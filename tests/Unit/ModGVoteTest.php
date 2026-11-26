<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/** Modul G: jednorázové hlasování (objednávka, potvrzení platby, faktura, storno, limit hlasování) a edice Zdarma podle velikosti domu. */
final class ModGVoteTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $old) {
            if ($old === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
                pset('__reset');
            } else {
                pset_save($key, $old);
            }
        }
        $this->saved = [];
        parent::tearDown();
    }

    private function setPset(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->saved)) {
            $v = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
            $this->saved[$key] = $v === false ? null : (string) $v;
        }
        pset_save($key, $value);
    }

    private function tenant(int $units = 0, array $extra = []): array
    {
        $slug = 'mv-' . bin2hex(random_bytes(3));
        tenant_create($extra + ['slug' => $slug, 'name' => 'SVJ ' . $slug, 'title' => 'Test', 'billing' => 1, 'owner_email' => $slug . '@example.cz']);
        $pdo = tenant_pdo($slug);
        $pdo->exec('DELETE FROM units');
        $st = $pdo->prepare('INSERT INTO units (entrance, number) VALUES (?, ?)');
        for ($i = 1; $i <= $units; $i++) {
            $st->execute(['A', (string) $i]);
        }
        $t = tenant_row($slug);
        tenant_unit_count($t, true);
        return $t;
    }

    private function subjects(): array
    {
        return array_map(fn($m) => mb_decode_mimeheader((string) $m['subject']), $this->mails());
    }

    // ---------- Jednorázové hlasování ----------

    public function testOfferOnlyForFreeEditionWithPrice(): void
    {
        $free = $this->tenant();
        $this->assertTrue(vote_offerable($free));
        $paid = $this->tenant(0, ['paid_until' => date('Y-m-d', strtotime('+100 day'))]);
        pq("UPDATE tenants SET plan = 'standard' WHERE slug = ?", [$paid['slug']]);
        $this->assertFalse(vote_offerable(tenant_row($paid['slug'])), 'předplatitel hlasování nepotřebuje');
        $this->assertFalse(vote_offerable($this->tenant(0, ['billing' => 0])), 'web bez placení má vše');
        $this->assertFalse(vote_offerable(null));
        $this->setPset('price_vote', '0');
        $this->assertFalse(vote_offerable($free), 'cena 0 = nenabízet');
        $this->assertSame('Jednorázové hlasování se nabízí jen webům v edici Zdarma.', vote_order($free));
    }

    public function testOrderConfirmInvoiceAndExtraPoll(): void
    {
        $t = $this->tenant();
        $p = vote_order($t);
        $this->assertIsArray($p);
        $this->assertSame('vote', $p['kind']);
        $this->assertSame(vote_price(), (int) $p['amount']);
        $this->assertSame(990, (int) $p['amount']);
        $this->assertNotSame('', $p['vs']);
        $this->assertSame($p['id'], vote_order($t)['id'], 'druhá objednávka vrátí tu otevřenou');
        $this->assertSame($p['id'], vote_payment($t)['id']);
        $this->assertSame(0, vote_extra_polls($t));

        $inv = invoice_view($p);
        $this->assertStringContainsString('Jednorázové hlasování', (string) $inv['item']);

        $r = billing_confirm($p, 'test');
        $this->assertSame(date('Y-m-d'), $r['from']);
        $this->assertSame(date('Y-m-d', strtotime('+' . (VOTE_PASS_DAYS - 1) . ' day')), $r['to']);
        $row = pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch();
        $this->assertSame('paid', $row['status']);
        $this->assertNotSame('', $row['invoice_no']);
        $this->assertSame(1, vote_extra_polls($t));
        $this->assertNull(vote_payment($t));
        $this->assertSame('', (string) tenant_row($t['slug'])['paid_until'], 'hlasování nemění předplatné edice');
        $this->assertContains('Jednorázové hlasování je zaplacené – ' . $t['name'], $this->subjects());
        $this->assertNull(billing_confirm($row, 'test'), 'zaplacenou platbu nejde potvrdit znovu');

        // Po 60 dnech už neplatí.
        pq('UPDATE payments SET period_to = ? WHERE id = ?', [date('Y-m-d', strtotime('-1 day')), $p['id']]);
        $this->assertSame(0, vote_extra_polls($t));
    }

    public function testPaymentPageView(): void
    {
        $t = $this->tenant();
        $p = vote_order($t);
        $title = 'Jednorázové hlasování';
        $iban = '';
        $qr = '';
        $active = 0;
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/pricing/vote_payment.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString($p['vs'], $html);
        $this->assertStringContainsString('Platbu jsem odeslal(a)', $html);
        $this->assertStringContainsString('/admin/predplatne/doklad/' . $p['id'], $html);
    }

    public function testStornoEndsVotePass(): void
    {
        $t = $this->tenant();
        $p = vote_order($t);
        billing_confirm($p, 'test');
        $paid = pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch();
        [$no, $notes] = billing_storno($paid, 'test', 'chybná platba');
        $this->assertStringStartsWith('D', $no);
        $this->assertStringContainsString('Jednorázové hlasování už neplatí', implode(' ', $notes));
        $this->assertSame(0, vote_extra_polls($t));
        $this->assertSame('', (string) tenant_row($t['slug'])['paid_until']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPaidVoteRaisesPollLimit(): void
    {
        pq("UPDATE tenants SET billing = 1, plan = '', paid_until = '', trial_ends = '' WHERE slug = 'dum'");
        q('DELETE FROM polls WHERE opens_at >= ?', [date('Y-01-01')]);
        q("INSERT INTO polls (title, body, opens_at, closes_at, threshold) VALUES ('Limit', '', ?, ?, 50)", [date('Y-m-d'), date('Y-m-d', strtotime('+10 day'))]);
        $free = plan_limit_of('free', 'polls');
        $this->assertSame($free, plan_limit('polls'));
        $this->assertNotNull(plan_limit_error('polls'), 'limit edice Zdarma je vyčerpaný');
        $p = vote_order(plan_tenant());
        $this->assertIsArray($p);
        billing_confirm($p, 'test');
        $this->assertSame($free + 1, plan_limit('polls'));
        $this->assertNull(plan_limit_error('polls'), 'zaplacené hlasování dovolí jedno navíc');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPollFormShowsOfferWhenLimitReached(): void
    {
        pq("UPDATE tenants SET billing = 1, plan = '', paid_until = '', trial_ends = '' WHERE slug = 'dum'");
        q("INSERT INTO polls (title, body, opens_at, closes_at, threshold) VALUES ('Limit', '', ?, ?, 50)", [date('Y-m-d'), date('Y-m-d', strtotime('+10 day'))]);
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/pricing/vote_offer.php';
        $html = ob_get_clean();
        $this->assertStringContainsString('Objednat jednorázové hlasování', $html);
        $this->assertStringContainsString('/admin/hlasovani/jednorazove', $html);
        vote_order(plan_tenant());
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/pricing/vote_offer.php';
        $this->assertStringContainsString('Platební údaje', ob_get_clean(), 'objednané hlasování: odkaz na platbu');
    }

    // ---------- Edice Zdarma podle velikosti ----------

    public function testFreeSizeOffByDefault(): void
    {
        $t = $this->tenant(200);
        $this->assertSame(0, free_max_units());
        $this->assertTrue(free_size_allowed($t));
        $this->assertFalse(free_size_check($t));
        $this->assertSame('', (string) tenant_row($t['slug'])['trial_ends']);
        $this->assertFalse(free_size_limited($t));
    }

    public function testBigHouseGetsStandardTrialOnce(): void
    {
        $this->setPset('free_max_units', '12');
        $small = $this->tenant(12);
        $this->assertFalse(free_size_check($small), 'do hranice včetně zůstává edice Zdarma');
        $big = $this->tenant(13);
        $this->assertFalse(free_size_allowed($big));
        $this->assertTrue(free_size_check($big));
        $row = tenant_row($big['slug']);
        $this->assertSame(date('Y-m-d', strtotime('+' . PLAN_TRIAL_DAYS . ' day')), $row['trial_ends']);
        $this->assertSame('standard', tenant_edition($row));
        $this->assertFalse(free_size_check($row), 'zkouška jen jednou');
        $this->assertFalse(free_size_limited($row), 'během zkoušky není omezený');
        // Po zkoušce: omezený režim s edicí Zdarma.
        pq('UPDATE tenants SET trial_ends = ? WHERE slug = ?', [date('Y-m-d', strtotime('-1 day')), $big['slug']]);
        $row = tenant_row($big['slug']);
        $this->assertSame('free', tenant_edition($row));
        $this->assertTrue(free_size_limited($row));
        $this->assertFalse(free_size_check($row));
    }

    public function testFreeSizeSkipsPaidDemoAndUnbilled(): void
    {
        $this->setPset('free_max_units', '5');
        $this->assertFalse(free_size_check($this->tenant(30, ['paid_until' => date('Y-m-d', strtotime('+10 day'))])));
        $this->assertFalse(free_size_check($this->tenant(30, ['billing' => 0])));
        $this->assertFalse(free_size_check($this->tenant(30, ['demo' => 2])));
        $this->assertFalse(free_size_check(null));
    }
}
