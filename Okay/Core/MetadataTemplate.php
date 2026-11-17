<?php

namespace Okay\Core;

/**
 * Підстановка змінних у SEO-шаблони (`{$product}`, `{$color-value}` …) — спільна для
 * метаданих сторінок і описів у фідах.
 */
class MetadataTemplate
{
    public const string BLOCK_START = '{$if}';
    public const string BLOCK_END = '{$endif}';

    private const string PLACEHOLDER = '/{\$[^$]*}/';
    private const string STRICT_PLACEHOLDER = '/\{\$[^{}$\s]*\}/';
    private const string REMOVED = "~([ \t]*)((?:\x01[ \t]*)+)(\r?\n)?((?:[ \t]*\r?\n)*)~";

    /** Шаблонів на сторінку чи фід одиниці; межа — лише запобіжник для довгих CLI-процесів. */
    private const int CACHE_LIMIT = 512;

    /** @var array<string, list<array{0: string, 1: list<string>|null}>> */
    private array $parsed = [];

    /**
     * Підставляє змінні. Блок `{$if}…{$endif}` виводиться, лише якщо всі змінні в ньому
     * непорожні; інакше зникає разом із підписами. Блоки не вкладаються: вкладені, зайві чи
     * незакриті маркери прибираються, а текст між ними лишається. Шаблон без маркерів іде
     * старою підстановкою без змін.
     *
     * @param array<string, string|int|float|null> $parts
     */
    public function compile(string $template, array $parts): string
    {
        // Один пошук 'if}' покриває обидва маркери: звичайний шаблон платить лише за нього.
        if (str_contains($template, 'if}')
            && (str_contains($template, self::BLOCK_START) || str_contains($template, self::BLOCK_END))) {
            $template = $this->resolveBlocks($template, $parts)
                ?? str_replace([self::BLOCK_START, self::BLOCK_END], '', $template);
        }

        $substituted = strtr($template, $parts);

        return trim(preg_replace(self::PLACEHOLDER, '', $substituted) ?? $substituted);
    }

    /** Null — помилка PCRE або в шаблоні є службовий байт \x01. */
    private function resolveBlocks(string $template, array $parts): ?string
    {
        if (str_contains($template, "\x01")) {
            return null;
        }

        $segments = $this->parse($template);
        if ($segments === null) {
            return null;
        }

        $html = '';
        foreach ($segments as [$text, $placeholders]) {
            $isShown = $placeholders === null || array_all(
                $placeholders,
                static fn(string $placeholder) => (string)($parts[$placeholder] ?? '') !== ''
            );
            $html .= $isShown ? $text : "\x01";
        }

        return str_contains($html, "\x01") ? $this->dropRemovedBlocks($html) : $html;
    }

    /**
     * Текст між маркерами. Placeholders = null — звичайний текст; список — блок, що
     * показується лише з усіма непорожніми змінними. Блок без змінних — звичайний текст.
     *
     * @return list<array{0: string, 1: list<string>|null}>|null
     */
    private function parse(string $template): ?array
    {
        if (array_key_exists($template, $this->parsed)) {
            return $this->parsed[$template];
        }

        $pieces = preg_split('~(\{\$(?:if|endif)\})~', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($pieces === false) {
            return null;
        }

        $segments = [];
        $group = [];
        $depth = 0;
        $isNested = false;
        $flushGroup = static function (bool $isBlock) use (&$segments, &$group): void {
            $text = implode('', $group);
            $placeholders = null;
            if ($isBlock && preg_match_all(self::STRICT_PLACEHOLDER, $text, $m)) {
                $placeholders = array_values(array_unique($m[0]));
            }
            $segments[] = [$text, $placeholders];
            $group = [];
        };

        // Парні індекси $pieces — текст, непарні — маркери. Блоком стає лише група рівно з
        // одного {$if} і одного {$endif}; решта маркерів зникає, текст лишається.
        foreach ($pieces as $i => $piece) {
            if ($i % 2 === 0) {
                if ($depth === 0) {
                    $segments[] = [$piece, null];
                } else {
                    $group[] = $piece;
                }
                continue;
            }

            if ($piece === self::BLOCK_START) {
                $isNested = $isNested || $depth > 0;
                $depth++;
            } elseif ($depth > 0) {
                $depth--;
                if ($depth === 0) {
                    $flushGroup(!$isNested);
                    $isNested = false;
                }
            }
        }
        if ($depth > 0) {
            $flushGroup(false);
        }

        if (count($this->parsed) >= self::CACHE_LIMIT) {
            $this->parsed = [];
        }

        return $this->parsed[$template] = $segments;
    }

    /**
     * Блок, що стояв сам на рядку, забирає рядок, і порожніх рядків довкола лишається
     * не більше, ніж було з того боку, де їх більше; між словами лишає пробіл, щоб їх не
     * склеїти; між тегами не лишає нічого.
     */
    private function dropRemovedBlocks(string $html): ?string
    {
        $isText = static fn(string $c): bool => !in_array($c, ['', '<', '>', ' ', "\t", "\r", "\n"], true);

        return preg_replace_callback(
            self::REMOVED,
            static function (array $m) use ($html, $isText): string {
                [$whole, $start] = $m[0];
                $prev = $start > 0 ? $html[$start - 1] : '';
                $next = $html[$start + strlen($whole)] ?? '';
                $lineBreak = $m[3][1] >= 0 ? $m[3][0] : '';
                $blankAfter = $m[4][1] >= 0 ? $m[4][0] : '';

                if (in_array($prev, ['', "\n"], true) && ($lineBreak !== '' || in_array($next, ['', "\r", "\n"], true))) {
                    $blankBefore = 0;
                    for ($i = $start - 1; $i > 0; $i = $j) {
                        for ($j = $i - 1; $j >= 0 && in_array($html[$j], [' ', "\t", "\r"], true); $j--);
                        if ($j < 0 || $html[$j] !== "\n") {
                            break;
                        }
                        $blankBefore++;
                    }
                    preg_match_all('~[ \t]*\r?\n~', $blankAfter, $after);

                    return implode('', array_slice($after[0], 0, max(0, count($after[0]) - $blankBefore)));
                }

                $hadSpace = $m[1][0] !== '' || rtrim($m[2][0], " \t") !== $m[2][0];
                $gap = $lineBreak === '' && ($hadSpace || ($isText($prev) && $isText($next))) ? ' ' : '';

                return $gap . $lineBreak . $blankAfter;
            },
            $html,
            flags: PREG_OFFSET_CAPTURE
        );
    }
}
