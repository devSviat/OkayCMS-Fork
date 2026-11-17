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

    /** Шаблонів на сторінку чи фід одиниці; межа — лише запобіжник для довгих CLI-процесів. */
    private const int CACHE_LIMIT = 512;

    /** @var array<string, list<array{0: string, 1: list<string>|null}>> */
    private array $parsed = [];

    /**
     * Підставляє змінні. Блок `{$if}…{$endif}` виводиться, лише якщо всі змінні в ньому
     * непорожні (значення з самих пробілів — порожнє, '0' — ні); інакше зникає разом із
     * підписами, нічого не додаючи на своє місце. Блоки не вкладаються: вкладені, зайві чи
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
                static fn(string $placeholder) => trim((string)($parts[$placeholder] ?? '')) !== ''
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
            if ($isBlock) {
                // Усе, що стара регулярка стерла б як змінну ({$a b} теж), рахується змінною.
                preg_match_all(self::STRICT_PLACEHOLDER, $text, $strict);
                preg_match_all(self::PLACEHOLDER, preg_replace(self::STRICT_PLACEHOLDER, '', $text) ?? '', $loose);
                $found = array_values(array_unique([...$strict[0], ...$loose[0]]));
                $placeholders = $found === [] ? null : $found;
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
     * Прибраний блок не лишає на своєму місці нічого. Рядок, що після цього став порожнім,
     * зникає разом із переносом, і порожніх рядків довкола лишається не більше, ніж було з
     * того боку, де їх більше.
     */
    private function dropRemovedBlocks(string $html): string
    {
        $out = [];
        $blankToSkip = 0;
        foreach (explode("\n", $html) as $line) {
            if (!str_contains($line, "\x01")) {
                $isBlank = trim($line, " \t\r") === '';
                if ($isBlank && $blankToSkip > 0) {
                    $blankToSkip--;
                    continue;
                }
                $blankToSkip = 0;
                $out[] = $line;
                continue;
            }

            $kept = str_replace("\x01", '', $line);
            if (trim($kept, " \t\r") !== '') {
                $out[] = $kept;
                $blankToSkip = 0;
                continue;
            }

            for ($blankBefore = 0, $i = count($out) - 1; $i >= 0 && trim($out[$i], " \t\r") === ''; $i--) {
                $blankBefore++;
            }
            $blankToSkip = max($blankToSkip, $blankBefore);
        }

        return implode("\n", $out);
    }
}
