<?php


namespace Okay\Core\Modules;


/**
 * Налаштування платіжного модуля, оголошені як `<settings type="secret">` у settings.xml.
 *
 * Сторінка способу оплати не друкує збережений секрет, тож поле повертається
 * порожнім, якщо менеджер не вписав нове значення. Порожнє поле означає
 * «не змінювати», а стерти значення можна лише окремою позначкою.
 */
final class SecretSettings
{
    public const string TYPE = 'secret';

    /**
     * Зливає надіслані налаштування способу оплати зі збереженими.
     *
     * Для кожної секретної змінної: є в $cleared → ''; надіслано порожнє чи самі
     * пробіли → збережене значення; інакше → надіслане без пробілів по краях.
     * Секрет, якого немає серед надісланих, так і лишається відсутнім, як і решта
     * полів модуля, що його форма не показала. Інші ключі повертаються як прийшли.
     *
     * @param array<string, mixed> $posted
     * @param array<string, mixed> $stored
     * @param list<string> $secretVariables
     * @param list<string> $cleared
     * @return array<string, mixed>
     */
    public static function merge(array $posted, array $stored, array $secretVariables, array $cleared): array
    {
        foreach ($secretVariables as $variable) {
            if (in_array($variable, $cleared, true)) {
                $posted[$variable] = '';
                continue;
            }

            if (!array_key_exists($variable, $posted)) {
                continue;
            }

            $value = is_string($posted[$variable]) ? trim($posted[$variable]) : '';
            if ($value === '') {
                $value = is_scalar($stored[$variable] ?? null) ? (string)$stored[$variable] : '';
            }
            $posted[$variable] = $value;
        }

        return $posted;
    }
}
