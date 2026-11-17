<?php

namespace Core;

use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\MetadataTemplate;
use Okay\Core\Settings;
use Okay\Helpers\MetadataHelpers\CommonMetadataHelper;
use Okay\Helpers\XmlFeedHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `{$if}…{$endif}` у SEO-шаблоні: блок виводиться, лише коли всі змінні в ньому непорожні.
 * Шаблон без маркерів підставляється рівно так, як до появи блоків.
 */
class MetadataTemplateTest extends TestCase
{
    private const array FILLED = ['{$product}' => 'Товар A & «ґ» 🙂', '{$color}' => 'Колір', '{$color-value}' => 'Червоний', '{$size-value}' => 'XL'];

    /** Стара підстановка — еталон для шаблонів без блоків і поведінка старого ядра. */
    private static function legacy(string $template, array $parts): string
    {
        return trim(preg_replace('/{\$[^$]*}/', '', strtr($template, $parts)));
    }

    public static function blocks(): array
    {
        return [
            'підпис без значення' => [
                "<h2>{\$product}</h2>\n{\$if}<p><b>Розмір:</b> {\$size-value}</p>{\$endif}\n<p>кінець</p>",
                ['{$product}' => 'A'],
                "<h2>A</h2>\n<p>кінець</p>",
            ],
            'підпис із значенням' => [
                '{$if}<p><b>Розмір:</b> {$size-value}</p>{$endif}',
                ['{$size-value}' => 'XL'],
                '<p><b>Розмір:</b> XL</p>',
            ],
            'дві змінні, одна порожня' => [
                "<p>a</p>\n{\$if}<p><b>{\$color}</b> {\$color-value}</p>{\$endif}\n<p>b</p>",
                ['{$color}' => 'Колір'],
                "<p>a</p>\n<p>b</p>",
            ],
            'дві змінні, обидві є' => [
                '{$if}<p><b>{$color}</b> {$color-value}</p>{$endif}',
                ['{$color}' => 'Колір', '{$color-value}' => 'Червоний'],
                '<p><b>Колір</b> Червоний</p>',
            ],
            'один рядок' => [
                '<h2>{$product}</h2>{$if}<p><b>Розмір:</b> {$size-value}</p>{$endif}<p>b</p>',
                ['{$product}' => 'A'],
                '<h2>A</h2><p>b</p>',
            ],
            'блок на кілька рядків' => [
                "<p>a</p>\n{\$if}\n<p><b>Розмір:</b>\n  {\$size-value}</p>\n{\$endif}\n<p>b</p>",
                [],
                "<p>a</p>\n<p>b</p>",
            ],
            'CRLF' => [
                "<p>a</p>\r\n{\$if}<p>{\$size-value}</p>{\$endif}\r\n<p>b</p>",
                [],
                "<p>a</p>\r\n<p>b</p>",
            ],
            'порожні рядки довкола' => [
                "<p>a</p>\n\n{\$if}<p>{\$a}</p>{\$endif}\n\n<p>b</p>",
                [],
                "<p>a</p>\n\n<p>b</p>",
            ],
            'пробіл автор ставить у блок' => ['Купити {$if}{$brand} {$endif}онлайн', [], 'Купити онлайн'],
            'нічого не вставляється: кома' => ['Купити {$product}{$if}, {$brand}{$endif}. Ціна', ['{$product}' => 'A'], 'Купити A. Ціна'],
            'нічого не вставляється: дефіс' => ['{$sku}{$if}-{$size-value}{$endif}, купити', ['{$sku}' => 'S1'], 'S1, купити'],
            'нічого не вставляється: дужки' => ['{$product} ({$if}{$brand}{$endif})', ['{$product}' => 'A'], 'A ()'],
            'нічого не вставляється: сусідні змінні' => ['{$a}{$if}{$b}{$endif}{$c}', ['{$a}' => 'A', '{$c}' => 'C'], 'AC'],
            'у значенні атрибута' => ['<img alt="{$if}{$brand}{$endif}" src="x.png">', [], '<img alt="" src="x.png">'],
            'відступ рядка зникає з рядком' => ["<ul>\n  {\$if}<li>{\$x}</li>{\$endif}\n  <li>y</li>\n</ul>", [], "<ul>\n  <li>y</li>\n</ul>"],
            'пробіли значення — порожньо' => ['{$if}<p>{$c}</p>{$endif}<p>b</p>', ['{$c}' => "  \t"], '<p>b</p>'],
            "'0' — не порожньо" => ['{$if}<p>{$c}</p>{$endif}', ['{$c}' => '0'], '<p>0</p>'],
            'змінна з пробілом' => ['{$if}<p>{$a b}</p>{$endif}x', [], 'x'],
            'маркери з пробілом чи великими — не маркери' => ['{$IF}<p>{$c}</p>{$ endif}<p>z</p>', [], '<p></p><p>z</p>'],
            'маркер у значенні змінної' => ['{$if}<p>{$a}</p>{$endif}<p>{$b}</p>', ['{$a}' => 'X{$endif}Y{$if}Z', '{$b}' => 'B'], '<p>XYZ</p><p>B</p>'],
            'маркер у значенні не закриває блок' => ['{$if}<p>{$a} {$c}</p>{$endif}<p>{$b}</p>', ['{$a}' => 'X{$endif}', '{$b}' => 'B'], '<p>B</p>'],
            'назва {$if} перед порожнім блоком' => ['<h1>{$product}</h1>{$if}<p>{$c}</p>{$endif}', ['{$product}' => '{$if}'], '<h1></h1>'],
            'у мета-тексті' => ['{$product}{$if}, колір {$color-value}{$endif} — купити', ['{$product}' => 'A'], 'A — купити'],
            'незакритий {$if}' => ['<p>a {$if}<b>Розмір:</b> {$size-value}</p>', [], '<p>a <b>Розмір:</b> </p>'],
            'зайвий {$endif}' => ['<p>a</p>{$endif}<p>{$size-value}</p>', [], '<p>a</p><p></p>'],
            'вкладені маркери' => [
                '{$if}<p>{$a}{$if}<b>{$b}</b>{$endif}</p>{$endif}<p>c</p>',
                ['{$a}' => 'A'],
                '<p>A<b></b></p><p>c</p>',
            ],
            'блок без змінних' => ['{$if}<p>Доставка по Україні</p>{$endif}', [], '<p>Доставка по Україні</p>'],
            'невідома змінна — порожня' => ['{$if}<p>{$unknown}</p>{$endif}<p>b</p>', [], '<p>b</p>'],
            'id і атрибути — частина блоку' => ['{$if}<p id="color" class="x">{$color}</p>{$endif}', [], ''],
            'кілька блоків поспіль' => [
                "{\$if}<p>{\$a}</p>{\$endif}\n{\$if}<p>{\$b}</p>{\$endif}\n<p>c</p>",
                ['{$b}' => 'B'],
                "<p>B</p>\n<p>c</p>",
            ],
        ];
    }

    #[DataProvider('blocks')]
    public function testBlock(string $template, array $parts, string $expected): void
    {
        $this->assertSame($expected, new MetadataTemplate()->compile($template, $parts));
    }

    public static function withoutMarkers(): array
    {
        $cases = [
            'опис із порожньою змінною' => ["<p><b>{\$color}</b> {\$color-value}</p>\n<p><b>{\$size}</b> {\$size-value}</p>"],
            'мета'                      => ['{$product} {$sku} — купити, ціна {$price} | {$sitename}'],
            'авторські порожні теги'    => ['<p></p><p>&nbsp;</p><div class="spacer"></div>{$a}'],
            'сутності й мови'           => ['<p>Ціна &lt; 100 &amp; &#8372; — «Ґ» пʼять 🙂 {$a}</p>'],
            // Стара жадібна регулярка з'їдає `{$a}</p><p>b}` — так було й лишається.
            'жадібна стара регулярка'   => ['<p>{$a}</p><p>b}</p>'],
        ];
        foreach (self::stockDumpTemplates() as $i => $template) {
            $cases['дамп стоку #' . $i] = [$template];
        }

        return $cases;
    }

    /** Рядки зі змінними з чистого дампу OkayCMS, що лежить у репозиторії. */
    private static function stockDumpTemplates(): array
    {
        $templates = [];
        foreach (file(__DIR__ . '/../../1DB_changes/okay_clean.sql') as $line) {
            if (!str_contains($line, '{$')) {
                continue;
            }
            preg_match_all("~'((?:[^'\\\\]|\\\\.)*)'~s", $line, $m);
            foreach ($m[1] as $value) {
                if (str_contains($value, '{$')) {
                    $templates[] = stripcslashes($value);
                }
            }
        }

        return array_values(array_unique($templates));
    }

    #[DataProvider('withoutMarkers')]
    public function testTemplateWithoutMarkersIsLegacySubstitution(string $template): void
    {
        $this->assertSame(self::legacy($template, self::FILLED), new MetadataTemplate()->compile($template, self::FILLED));
        $this->assertSame(self::legacy($template, []), new MetadataTemplate()->compile($template, []));
    }

    /** Старе ядро не знає блоків: його регулярка стирає маркери, і шаблон стає звичайним. */
    public function testOlderCoreDegradesToPlainContent(): void
    {
        $template = "<h2>{\$product}</h2>\n{\$if}<p><b>Розмір:</b> {\$size-value}</p>{\$endif}";

        $this->assertSame("<h2>A</h2>\n<p><b>Розмір:</b> XL</p>", self::legacy($template, ['{$product}' => 'A', '{$size-value}' => 'XL']));
        $this->assertSame("<h2>A</h2>\n<p><b>Розмір:</b> </p>", self::legacy($template, ['{$product}' => 'A']));
    }

    /** Службовий байт у шаблоні: блоки не розбираються, маркери просто стираються. */
    public function testMarkerByteInTemplateFallsBackToStrippedMarkers(): void
    {
        $this->assertSame("\x01<p></p>", new MetadataTemplate()->compile("\x01{\$if}<p>{\$a}</p>{\$endif}", []));
    }

    public function testPageMetadataGoesThroughBlocks(): void
    {
        $reflector = new ReflectionClass(CommonMetadataHelper::class);
        $helper = $reflector->newInstanceWithoutConstructor();
        $reflector->getProperty('parts')->setValue($helper, ['{$a}' => 'A']);
        $reflector->getProperty('metadataTemplate')->setValue($helper, new MetadataTemplate());

        $this->assertSame(
            '<p>A</p>',
            $reflector->getMethod('compileMetadata')->invoke($helper, "<p>{\$a}</p>\n{\$if}<p><b>Розмір:</b> {\$b}</p>{\$endif}")
        );
    }

    public function testFeedDescriptionGoesThroughBlocks(): void
    {
        $reflector = new ReflectionClass(XmlFeedHelper::class);
        $helper = $reflector->newInstanceWithoutConstructor();
        $reflector->getProperty('metadataTemplate')->setValue($helper, new MetadataTemplate());
        $product = (object)['description' => ''];

        $helper->attachDescriptionByTemplate($product, ['{$a}' => 'A'], "<p>{\$a}</p>\n{\$if}<p><b>Розмір:</b> {\$b}</p>{\$endif}", 'description');

        $this->assertSame('<p>A</p>', $product->description);
    }

    /** Модулі створюють хелпер через new з трьома аргументами; служба береться з ServiceLocator. */
    public function testXmlFeedHelperBuiltWithThreeArgumentsUsesBlocks(): void
    {
        // mappedBy() у Entity фінальний, тож замість заглушок сутностей — прості об'єкти.
        $entities = new class {
            public function find(): array
            {
                return [];
            }

            public function getMainCurrency(): null
            {
                return null;
            }

            public function mappedBy(string $column): static
            {
                return $this;
            }
        };
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($entities);

        $helper = new XmlFeedHelper($this->createStub(Languages::class), $this->createStub(Settings::class), $entityFactory);
        $product = (object)['description' => ''];
        $helper->attachDescriptionByTemplate($product, ['{$a}' => 'A'], "<p>{\$a}</p>\n{\$if}<p>{\$b}</p>{\$endif}", 'description');

        $this->assertSame('<p>A</p>', $product->description);
    }

    /** Підкласи метаданих у модулях кличуть parent::__construct() без аргументів. */
    public function testMetadataSubclassWithoutArgumentsUsesBlocks(): void
    {
        try {
            $helper = new class extends CommonMetadataHelper {
                public function __construct()
                {
                    parent::__construct();
                    $this->parts = ['{$a}' => 'A'];
                }

                public function compile(string $pattern): string
                {
                    return $this->compileMetadata($pattern);
                }
            };
        } catch (\PDOException $e) {
            $this->markTestSkipped('конструктор хелпера читає налаштування з бази: ' . $e->getMessage());
        }

        $this->assertSame('<p>A</p>', $helper->compile("<p>{\$a}</p>\n{\$if}<p>{\$b}</p>{\$endif}"));
    }
}
