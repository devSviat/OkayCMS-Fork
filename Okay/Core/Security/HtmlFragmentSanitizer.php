<?php

namespace Okay\Core\Security;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Чистить фрагмент HTML за білим списком тегів. Усі атрибути прибираються, крім
 * href у посилань з безпечною схемою. Теги поза списком розгортаються до тексту,
 * як у strip_tags, а вміст script, style та подібних викидається цілком.
 *
 * Розбір іде парсером HTML5 браузерного рівня, тож незакритий чи кривий тег
 * читається так само, як його прочитав би браузер.
 */
class HtmlFragmentSanitizer
{
    private const array ALLOWED = [
        'a', 'p', 'b', 'u', 's', 'strong', 'i', 'em', 'br', 'span', 'div',
        'ol', 'ul', 'li', 'table', 'tbody', 'tr', 'td', 'blockquote',
    ];

    private const array DROPPED_WITH_CONTENT = [
        'script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed',
        'textarea', 'title', 'svg', 'math', 'select', 'xmp', 'noembed', 'noframes',
    ];

    public function sanitize(?string $html): string
    {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR,
            'UTF-8'
        );
        $body = $document->body;
        if ($body === null) {
            return '';
        }

        $this->clean($body);

        return $body->innerHTML;
    }

    private function clean(Node $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof Element) {
                $name = strtolower($child->localName);
                if (in_array($name, self::DROPPED_WITH_CONTENT, true)) {
                    $child->remove();
                    continue;
                }

                $this->clean($child);

                if (!in_array($name, self::ALLOWED, true) || $child->namespaceURI !== 'http://www.w3.org/1999/xhtml') {
                    $child->replaceWith(...iterator_to_array($child->childNodes));
                    continue;
                }

                $this->keepSafeAttributes($child, $name);
                continue;
            }

            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $parent->removeChild($child);
            }
        }
    }

    private function keepSafeAttributes(Element $element, string $name): void
    {
        $href = $name === 'a' ? $element->getAttribute('href') : null;

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttribute($attribute->name);
        }

        if ($href !== null && self::isSafeHref($href)) {
            $element->setAttribute('href', $href);
        }
    }

    /** Відносна адреса або http(s), mailto, tel. Браузер ігнорує пробіли й керівні символи перед схемою. */
    private static function isSafeHref(string $href): bool
    {
        $href = preg_replace('~[\x00-\x20]+~', '', $href) ?? '';
        if ($href === '') {
            return false;
        }
        if (!preg_match('~^([a-z][a-z0-9+.\-]*):~i', $href, $m)) {
            return true;
        }

        return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true);
    }
}
