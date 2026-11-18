<?php

namespace Requests;

use Okay\Admin\Requests\BackendFeaturesRequest;
use Okay\Core\Request;
use Okay\Core\Translit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Позначка «Індексувати в парі з брендом» — це перемикач: знятий не приходить у POST зовсім,
 * і саме це мусить знімати позначку. Колонка NOT NULL, тож у сутність іде лише 0 або 1.
 */
class BackendFeaturesRequestIndexWithBrandTest extends TestCase
{
    public static function posted(): array
    {
        return [
            'перемикач знято' => [null, 0],
            'увімкнено' => ['1', 1],
            'сміття' => ['abc', 0],
            'інше число' => ['7', 1],
            'масив' => [['1'], 1],
        ];
    }

    #[DataProvider('posted')]
    public function testFlagIsZeroOrOne($posted, int $expected): void
    {
        $request = new class($posted) extends Request {
            public function __construct(private $posted)
            {
            }

            public function post($name = null, $type = null, $default = null)
            {
                $value = ['name' => 'Тип', 'url' => 'typ', 'index_with_brand' => $this->posted][$name] ?? null;
                if ($type === 'int' || $type === 'integer') {
                    $value = intval(is_array($value) ? reset($value) : ($value ?? $default));
                }

                return $value;
            }
        };

        $this->assertSame(
            $expected,
            (new BackendFeaturesRequest($request, $this->createStub(Translit::class)))->postFeature()->index_with_brand
        );
    }
}
