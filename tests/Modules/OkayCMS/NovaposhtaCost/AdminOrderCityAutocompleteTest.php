<?php

namespace Modules\OkayCMS\NovaposhtaCost;

use PHPUnit\Framework\TestCase;

/**
 * Блок Нової Пошти в замовленні адмінки (order_contact_block.tpl).
 *
 * Обидва поля міста мали спільний клас, і автокомпліт відділень, ініціалізований
 * другим, перекривав автокомпліт адреси: менеджер міняв місто курʼєрської
 * доставки, а в замовленні лишались Ref нового міста й назва, область та вулиця
 * старого — ТТН потім їхала в старе місто. Окремо: id способів доставки
 * приходять числами, а value select — рядком, тож перемикання на курʼєра не
 * показувало полів адреси.
 *
 * Перевірка по вихідному коду: JS-оточення в наборі немає.
 */
class AdminOrderCityAutocompleteTest extends TestCase
{
    private function source(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/Okay/Modules/OkayCMS/NovaposhtaCost/Backend/design/html/order_contact_block.tpl'
        );
        $this->assertIsString($source);

        return $source;
    }

    public function testEachCityFieldHasItsOwnAutocomplete(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            "~\\.fn_np_door_city'\\)[^;]*;\\s*.*?npDoorCityInput\\.devbridgeAutocomplete\\(\\{\\s*serviceUrl: okay\\.router\\['OkayCMS_NovaposhtaCost_find_city_for_door'\\]~s",
            $source,
            'місто адреси мусить шукатись серед населених пунктів (find_city_for_door)'
        );
        $this->assertMatchesRegularExpression(
            "~\\$\\('\\.fn_np_warehouse_city'\\)\\.devbridgeAutocomplete\\(\\{\\s*serviceUrl: okay\\.router\\['OkayCMS_NovaposhtaCost_find_city'\\]~",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '~\$\(\s*"\.fn_newpost_city_name"\s*\)\.devbridgeAutocomplete~',
            $source,
            'спільний клас знову отримав автокомпліт — другий перекриє перший'
        );
    }

    public function testBothInputsCarryTheirClasses(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression('~name="novaposhta_city" class="[^"]*\bfn_np_door_city\b~', $source);
        $this->assertMatchesRegularExpression('~class="[^"]*\bfn_np_warehouse_city\b[^"]*"[^\n]*\|newpost_city~', $source);
    }

    /** Змінене вручну місто без вибору підказки не має Ref — старі назва, область і вулиця йдуть геть. */
    public function testEditingDoorCityClearsDependentFields(): void
    {
        $this->assertMatchesRegularExpression(
            "~npDoorCityInput\\.on\\('input'.*?npCityId\\.val\\(''\\).*?novaposhta_city_name.*?novaposhta_area_name.*?novaposhta_region_name.*?npClearDoorStreet\\(\\)~s",
            $this->source()
        );
    }

    public function testDeliveryIdsAreComparedAsStrings(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('doorsDeliveries.map(String).includes(String(deliveryId))', $source);
        $this->assertStringContainsString('warehousesDeliveries.map(String).includes(String(deliveryId))', $source);
    }

    /** Підказки — відповідь API НП; у DOM вони потрапляють лише як текст. */
    public function testSuggestionsAreRenderedAsText(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("return \$('<div>').text(suggestion.value || '').html();", $source);
        $this->assertDoesNotMatchRegularExpression('~suggestion\.value\.replace\(~', $source);
    }
}
