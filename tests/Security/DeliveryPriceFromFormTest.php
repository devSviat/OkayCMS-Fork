<?php

namespace Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Модулі доставки ставили ціну доставки замовлення з прихованого поля форми
 * кошика (novaposhta_delivery_price, meest_delivery_price, ukrposhta_delivery_price).
 * Покупець змінював його в браузері: товар за 1799 грн оформлювався на 299 грн
 * з доставкою −1500, і для будь-якого платного способу, не лише свого.
 * Ціна береться тільки з розрахунку сервера (Okay\Helpers\DeliveryQuoteHelper).
 */
class DeliveryPriceFromFormTest extends TestCase
{
    public function testNoModuleReadsDeliveryPriceFromTheForm(): void
    {
        $offenders = [];
        $modules = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/Okay/Modules', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($modules as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            if (preg_match('~->post\(\s*[\'"][a-z_]*delivery_price[\'"]~', $source)) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'ціна доставки з POST — покупець підставить будь-яку');
    }

    /** @return iterable<string, array{string}> */
    public static function extenders(): iterable
    {
        yield 'NovaposhtaCost' => ['Okay/Modules/OkayCMS/NovaposhtaCost/Extenders/FrontExtender.php'];
    }

    /**
     * Ціна ставиться лише для способу доставки цього модуля: раніше умовою було
     * POST-поле is_*_delivery, і чужий спосіб отримував ціну з форми.
     */
    #[DataProvider('extenders')]
    public function testPriceIsAppliedOnlyToOwnDeliveryMethod(string $path): void
    {
        $method = $this->method($path, 'setCartDeliveryPrice');

        $this->assertStringContainsString('$delivery->module_id', $method);
        $this->assertStringContainsString('getModuleIdByNamespace', $method);
        $this->assertStringContainsString('deliveryQuoteHelper->find(', $method);
        $this->assertDoesNotMatchRegularExpression('~is_[a-z]+_delivery~', $method);
    }

    private function method(string $path, string $name): string
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $start = strpos($source, 'function ' . $name . '(');
        $this->assertNotFalse($start, $path . ': немає ' . $name);
        $end = strpos($source, "\n    public function", $start + 1);

        return substr($source, $start, $end === false ? null : $end - $start);
    }
}
