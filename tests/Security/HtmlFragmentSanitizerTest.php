<?php

namespace Security;

use Okay\Core\Security\HtmlFragmentSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Історію замовлення менеджер пише у візуальному редакторі, тож розмітку треба
 * лишити. Але в той самий текст ядро вставляє прізвище покупця як є, а strip_tags
 * з білим списком тегів не чіпає атрибути: <a onmouseover=…> проходив.
 */
class HtmlFragmentSanitizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        yield 'event handler on an allowed tag' => ['<b onmouseover="alert(1)">x</b>', '<b>x</b>'];
        yield 'disallowed tag is unwrapped' => ['a "<img src=x onerror=alert(1)>" b', 'a "" b'];
        yield 'script content is dropped' => ['a<script>alert(1)</script>b', 'ab'];
        yield 'javascript link loses href' => ['<a href=" javascript:alert(1)">x</a>', '<a>x</a>'];
        yield 'http link keeps href only' => ['<a href="https://e.x/?a=1&amp;b=2" style="x" onclick="y">x</a>', '<a href="https://e.x/?a=1&amp;b=2">x</a>'];
        yield 'editor markup survives' => ['<p>Дзвонили</p><ul><li><strong>двічі</strong></li></ul>', '<p>Дзвонили</p><ul><li><strong>двічі</strong></li></ul>'];
        yield 'line breaks from core and modules' => ['a<br/>b<br>c<br data-mce-bogus="1">', 'a<br>b<br>c<br>'];
        yield 'plain text with ampersand' => ['Чек: https://e.x/?a=1&b=2', 'Чек: https://e.x/?a=1&amp;b=2'];
        yield 'escaped text stays text' => ['&lt;img src=x onerror=alert(1)&gt;', '&lt;img src=x onerror=alert(1)&gt;'];
        yield 'unclosed tag cannot swallow the page' => ['<b>x', '<b>x</b>'];
        yield 'entity-encoded scheme' => ['<a href="&#106;ava&#x09;script&colon;alert(1)">x</a>', '<a>x</a>'];
        yield 'mixed-case scheme' => ['<a href="JaVaScRiPt:alert(1)">x</a>', '<a>x</a>'];
        yield 'data url' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>', '<a>x</a>'];
        yield 'image inside an allowed tag' => ['<b><img src=x onerror=alert(1)>y</b>', '<b>y</b>'];
        yield 'svg and math are dropped whole' => ['<svg><a href="javascript:1"><text>x</text></a></svg><math><mi xlink:href="javascript:1">y</mi></math>z', 'z'];
        yield 'comment cannot hide markup' => ['a<!--<img src=x onerror=alert(1)>-->b', 'ab'];
        yield 'style tag text is not kept' => ['<style>*{x:y}</style>t', 't'];
    }

    #[DataProvider('cases')]
    public function testSanitize(string $input, string $expected): void
    {
        $this->assertSame($expected, new HtmlFragmentSanitizer()->sanitize($input));
    }

    public function testEmptyAndNull(): void
    {
        $this->assertSame('', new HtmlFragmentSanitizer()->sanitize(null));
        $this->assertSame('', new HtmlFragmentSanitizer()->sanitize(''));
    }
}
