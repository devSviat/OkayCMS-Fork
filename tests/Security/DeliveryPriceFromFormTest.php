<?php

namespace Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ціна доставки замовлення береться тільки з розрахунку сервера, не з полів форми
 * кошика, і модуль ставить її лише для своїх способів доставки
 * (docs/modules/delivery-price.md).
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

        $this->assertSame([], $offenders, 'ціна доставки не має братися з POST');
    }

    /** @return iterable<string, array{string}> */
    public static function extenders(): iterable
    {
        yield 'NovaposhtaCost' => ['Okay/Modules/OkayCMS/NovaposhtaCost/Extenders/FrontExtender.php'];
    }

    /**
     * Ціна ставиться лише для способу доставки цього модуля, а не за прапорцем
     * із форми.
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

    /**
     * Без серверного розрахунку модуль ціну доставки не змінює — лишається ціна з
     * адмінки. Перевіряються модулі доставки, які є в цьому дереві.
     */
    public function testModulesWithoutServerPriceKeepDeliverySettings(): void
    {
        $paths = array_filter([
            'Okay/Modules/Sviat/Meest/Extenders/FrontExtender.php',
            'Okay/Modules/Sviat/Ukrposhta/Extenders/FrontExtender.php',
        ], fn(string $path): bool => is_file(dirname(__DIR__, 2) . '/' . $path));

        foreach ($paths as $path) {
            $this->assertStringNotContainsString("['delivery_price']", $this->method($path, 'setCartDeliveryPrice'), $path);
        }
        $this->addToAssertionCount(1);
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
