<?php

namespace App\Helpers;

class HtmlSanitizer
{
    protected const ALLOWED_TAGS = [
        'p',
        'br',
        'strong',
        'b',
        'em',
        'i',
        'u',
        's',
        'ul',
        'ol',
        'li',
        'blockquote',
        'a',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'span',
        'div',
    ];

    protected const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
        'p' => ['class'],
        'div' => ['class'],
        'span' => ['class'],
        'h1' => ['class'],
        'h2' => ['class'],
        'h3' => ['class'],
        'h4' => ['class'],
        'h5' => ['class'],
        'h6' => ['class'],
        'li' => ['class'],
        'ul' => ['class'],
        'ol' => ['class'],
        'blockquote' => ['class'],
    ];

    protected const DROP_CONTENT_TAGS = [
        'script',
        'style',
        'iframe',
        'object',
        'embed',
        'svg',
        'math',
        'form',
        'input',
        'button',
        'textarea',
        'select',
        'option',
        'link',
        'meta',
        'base',
    ];

    public static function sanitizeQuillHtml(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrappedHtml = '<!DOCTYPE html><html><body><div id="sanitizer-root">'.$html.'</div></body></html>';

        // DOMDocument::loadHTML() assumes ISO-8859-1 unless the input encoding is made explicit.
        // Without this, UTF-8 characters like tildes and bullets become mojibake on save/reload.
        $encodedHtml = '<?xml encoding="UTF-8">' . $wrappedHtml;

        $dom->loadHTML($encodedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('sanitizer-root');

        if (!$root) {
            return '';
        }

        self::sanitizeNode($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    protected static function sanitizeNode(\DOMNode $node): void
    {
        if ($node instanceof \DOMComment) {
            $node->parentNode?->removeChild($node);
            return;
        }

        if (!($node instanceof \DOMElement)) {
            self::sanitizeChildren($node);
            return;
        }

        $tagName = strtolower($node->tagName);

        if (in_array($tagName, self::DROP_CONTENT_TAGS, true)) {
            $node->parentNode?->removeChild($node);
            return;
        }

        if (!in_array($tagName, self::ALLOWED_TAGS, true)) {
            self::unwrapNode($node);
            return;
        }

        self::sanitizeAttributes($node, $tagName);
        self::sanitizeChildren($node);
    }

    protected static function sanitizeChildren(\DOMNode $node): void
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            self::sanitizeNode($child);
        }
    }

    protected static function sanitizeAttributes(\DOMElement $node, string $tagName): void
    {
        $allowedAttributes = self::ALLOWED_ATTRIBUTES[$tagName] ?? [];
        $toRemove = [];

        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name);

            if (str_starts_with($name, 'on')) {
                $toRemove[] = $attribute->name;
                continue;
            }

            if (!in_array($name, $allowedAttributes, true)) {
                $toRemove[] = $attribute->name;
                continue;
            }

            $value = trim($attribute->value);

            if ($name === 'href' && !self::isSafeHref($value)) {
                $toRemove[] = $attribute->name;
                continue;
            }

            if ($name === 'target' && !in_array($value, ['_blank', '_self'], true)) {
                $toRemove[] = $attribute->name;
                continue;
            }

            if ($name === 'rel') {
                $safeRel = self::sanitizeRel($value);

                if ($safeRel === '') {
                    $toRemove[] = $attribute->name;
                    continue;
                }

                $node->setAttribute('rel', $safeRel);
            }

            if ($name === 'class') {
                $safeClass = self::sanitizeClassList($value);

                if ($safeClass === '') {
                    $toRemove[] = $attribute->name;
                    continue;
                }

                $node->setAttribute('class', $safeClass);
            }
        }

        foreach ($toRemove as $attributeName) {
            $node->removeAttribute($attributeName);
        }

        if ($tagName === 'a') {
            $href = trim($node->getAttribute('href'));

            if ($href === '') {
                $node->removeAttribute('href');
                $node->removeAttribute('target');
                $node->removeAttribute('rel');
                return;
            }

            if ($node->getAttribute('target') === '_blank') {
                $node->setAttribute('rel', self::mergeRel($node->getAttribute('rel'), [
                    'noopener',
                    'noreferrer',
                ]));
            }
        }
    }

    protected static function isSafeHref(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (preg_match('/^\s*(javascript|vbscript|data):/i', $value)) {
            return false;
        }

        if (preg_match('/^(https?:\/\/)/i', $value)) {
            return true;
        }

        if (str_starts_with($value, '/')) {
            return true;
        }

        if (str_starts_with($value, '#')) {
            return true;
        }

        return false;
    }

    protected static function sanitizeRel(string $value): string
    {
        $allowed = ['noopener', 'noreferrer', 'nofollow'];
        $tokens = preg_split('/\s+/', strtolower($value)) ?: [];
        $tokens = array_values(array_unique(array_intersect($tokens, $allowed)));

        return implode(' ', $tokens);
    }

    protected static function mergeRel(string $value, array $required): string
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: [];
        $tokens = array_filter($tokens);

        foreach ($required as $item) {
            $tokens[] = $item;
        }

        $tokens = array_values(array_unique($tokens));

        return implode(' ', $tokens);
    }

    protected static function sanitizeClassList(string $value): string
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $safe = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (preg_match('/^ql-(align|direction|font|size|indent|syntax|formula)(-[a-z0-9_-]+)?$/i', $token)) {
                $safe[] = $token;
                continue;
            }

            if (preg_match('/^ql-ui$/i', $token)) {
                $safe[] = $token;
            }
        }

        $safe = array_values(array_unique($safe));

        return implode(' ', $safe);
    }

    protected static function unwrapNode(\DOMElement $node): void
    {
        $parent = $node->parentNode;

        if (!$parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}
