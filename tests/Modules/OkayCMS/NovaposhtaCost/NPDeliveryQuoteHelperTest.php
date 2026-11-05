<?php

namespace Modules\OkayCMS\NovaposhtaCost;

use Okay\Modules\OkayCMS\NovaposhtaCost\Helpers\NPDeliveryQuoteHelper;
use PHPUnit\Framework\TestCase;

/**
 * Ціна доставки при оформленні береться лише з розрахунку сервера для того самого
 * кошика й тих самих параметрів.
 */
class NPDeliveryQuoteHelperTest extends TestCase
{
    private NPDeliveryQuoteHelper $quotes;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->quotes = new NPDeliveryQuoteHelper();
    }

    public function testSameCartAndContextReturnsRememberedPrice(): void
    {
        $this->quotes->remember('novaposhta:3', ['city' => 'kyiv', 'cod' => 0], $this->cart([1 => 1], 1799), 95.0);

        $this->assertSame(95.0, $this->quotes->find('novaposhta:3', ['cod' => 0, 'city' => 'kyiv'], $this->cart([1 => 1], 1799)));
    }

    public function testOtherCityGetsNothing(): void
    {
        $this->quotes->remember('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799), 95.0);

        $this->assertNull($this->quotes->find('novaposhta:3', ['city' => 'lviv'], $this->cart([1 => 1], 1799)));
    }

    public function testChangedCartGetsNothing(): void
    {
        $this->quotes->remember('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799), 95.0);

        $this->assertNull($this->quotes->find('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 3], 5397)));
        $this->assertNull($this->quotes->find('novaposhta:3', ['city' => 'kyiv'], $this->cart([2 => 1], 1799)));
    }

    public function testOtherDeliveryMethodGetsNothing(): void
    {
        $this->quotes->remember('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799), 95.0);

        $this->assertNull($this->quotes->find('novaposhta:1', ['city' => 'kyiv'], $this->cart([1 => 1], 1799)));
    }

    public function testNegativePriceIsNeverRemembered(): void
    {
        $this->quotes->remember('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799), -1500.0);

        $this->assertNull($this->quotes->find('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799)));
    }

    public function testTamperedSessionKeyIsRejected(): void
    {
        $this->quotes->remember('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799), 95.0);
        $_SESSION['novaposhta_delivery_quotes']['novaposhta:3']['key'] = 'x';

        $this->assertNull($this->quotes->find('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799)));
    }

    /** Тарифи НП змінюються: ціна, розрахована давно, при оформленні вже не береться. */
    public function testExpiredQuoteGetsNothing(): void
    {
        $this->quotes->remember('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799), 95.0);
        $_SESSION['novaposhta_delivery_quotes']['novaposhta:3']['expires'] = time() - 1;

        $this->assertNull($this->quotes->find('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799)));
    }

    public function testFreshQuoteLivesForAtLeastAnHour(): void
    {
        $this->quotes->remember('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799), 95.0);

        $this->assertGreaterThanOrEqual(time() + 3600, $_SESSION['novaposhta_delivery_quotes']['novaposhta:3']['expires']);
    }

    /** Запис без строку (збережений до появи строку) вважається простроченим. */
    public function testQuoteWithoutExpiryGetsNothing(): void
    {
        $this->quotes->remember('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799), 95.0);
        unset($_SESSION['novaposhta_delivery_quotes']['novaposhta:3']['expires']);

        $this->assertNull($this->quotes->find('novaposhta:3', ['city' => 'kyiv'], $this->cart([1 => 1], 1799)));
    }

    /** @param array<int, int> $items variant_id => amount */
    private function cart(array $items, float $total): object
    {
        $purchases = [];
        foreach ($items as $variantId => $amount) {
            $purchases[] = (object)['variant' => (object)['id' => $variantId], 'amount' => $amount];
        }

        return (object)['purchases' => $purchases, 'total_price' => $total];
    }
}
