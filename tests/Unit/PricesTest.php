<?php
declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Tests\TestCase;

/** Ceny edic od 11/2026: Standard 1 990 Kč, Premium 4 490 Kč ročně (bez DPH). */
final class PricesTest extends TestCase
{
    public function testDefaultPrices(): void
    {
        $this->assertSame('1990', PLATFORM_DEFAULTS['price_standard']);
        $this->assertSame('4490', PLATFORM_DEFAULTS['price_premium']);
    }

    public function testFreshPlatformUsesNewPrices(): void
    {
        $old = [pset('price_standard'), pset('price_premium')];
        try {
            pq("DELETE FROM platform_settings WHERE key IN ('price_standard', 'price_premium')");
            pset('__reset');
            $this->assertSame(1990, edition_price('standard'));
            $this->assertSame(4490, edition_price('premium'));
            $this->assertSame(0, edition_price('free'));
        } finally {
            pset_save('price_standard', $old[0]);
            pset_save('price_premium', $old[1]);
        }
    }

    public function testMigrationLowersOnlyOldDefaultPrices(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE platform_settings (key TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO platform_settings VALUES ('price_standard', '2990'), ('price_premium', '5990')");
        platform_prices_2026_11($pdo);
        $this->assertSame(['price_premium' => '4490', 'price_standard' => '1990'], $pdo->query('SELECT key, value FROM platform_settings ORDER BY key')->fetchAll(PDO::FETCH_KEY_PAIR));

        // Vlastní ceny provozovatele zůstanou.
        $pdo->exec("UPDATE platform_settings SET value = '2490' WHERE key = 'price_standard'");
        $pdo->exec("UPDATE platform_settings SET value = '6990' WHERE key = 'price_premium'");
        platform_prices_2026_11($pdo);
        $this->assertSame(['price_premium' => '6990', 'price_standard' => '2490'], $pdo->query('SELECT key, value FROM platform_settings ORDER BY key')->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
