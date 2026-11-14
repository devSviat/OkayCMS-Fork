<?php

namespace Admin\Helpers;

use Okay\Admin\Helpers\BackendPaymentsHelper;
use Okay\Core\Modules\Modules;
use Okay\Entities\PaymentsEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Збереження способу оплати переписує весь масив налаштувань тим, що прийшло
 * з форми. Секретне поле приходить порожнім, тож без злиття зі збереженим
 * кожне натискання «Зберегти» стирало б ключ.
 */
class BackendPaymentsHelperSecretTest extends TestCase
{
    public function testEmptySecretKeepsStoredValueOnSave(): void
    {
        $written = $this->save(['vendor__public_key' => 'public-1111', 'vendor__private_key' => ''], []);

        $this->assertSame(['vendor__public_key' => 'public-1111', 'vendor__private_key' => 'stored-0000'], $written);
    }

    public function testClearedSecretIsWrittenEmpty(): void
    {
        $written = $this->save(['vendor__public_key' => 'public-1111', 'vendor__private_key' => ''], ['vendor__private_key']);

        $this->assertSame('', $written['vendor__private_key']);
    }

    private function save(array $posted, array $cleared): array
    {
        $written = null;

        $paymentsEntity = $this->createStub(PaymentsEntity::class);
        $paymentsEntity->method('getPaymentSettings')->willReturn([
            'vendor__public_key' => 'public-0000',
            'vendor__private_key' => 'stored-0000',
        ]);
        $paymentsEntity->method('updatePaymentSettings')->willReturnCallback(
            static function ($id, array $settings) use (&$written) {
                $written = $settings;
                return $id;
            }
        );

        $modules = $this->createStub(Modules::class);
        $modules->method('secretPaymentSettingVariables')->willReturn(['vendor__private_key']);

        $helper = (new ReflectionClass(BackendPaymentsHelper::class))->newInstanceWithoutConstructor();
        foreach (['paymentMethodsEntity' => $paymentsEntity, 'modules' => $modules] as $name => $value) {
            new \ReflectionProperty(BackendPaymentsHelper::class, $name)->setValue($helper, $value);
        }

        $helper->updateSettings(5, $posted, $cleared);

        $this->assertIsArray($written);

        return $written;
    }
}
