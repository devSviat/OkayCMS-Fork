<?php

namespace Core;

use Okay\Core\MetadataTemplate;
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
            'між словами — пробіл' => ['Купити{$if} {$brand}{$endif}онлайн', [], 'Купити онлайн'],
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
        foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/stock-template-strings.json'), true) as $i => $template) {
            $cases['дамп стоку #' . $i] = [$template];
        }

        return $cases;
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
}
