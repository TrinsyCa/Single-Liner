<?php

declare(strict_types=1);

namespace TrinsyCa\SingleLiner\Tests;

/**
 * Parses two HTML strings with the HTML5 parser of PHP 8.4 (Dom\HTMLDocument)
 * and reports every difference a browser could observe after minification:
 *
 *  - element structure, names, namespaces and attribute lists (byte-exact),
 *  - text inside protected elements (script, style, pre, textarea, title,
 *    listing, xmp and anything carrying the keep attribute) byte-exact,
 *  - every other text node equal once ASCII whitespace runs are collapsed,
 *    and never emptied.
 *
 * Comments are ignored (the minifier removes most of them) and adjacent text
 * nodes are merged, which is what removing a comment between them does.
 */
final class DomCompare
{
    // <noscript> is not listed: this parser runs with scripting disabled and
    // builds real elements from its content, which then compare as markup.
    private const PROTECTED = ['script', 'style', 'pre', 'textarea', 'title', 'listing', 'xmp', 'plaintext'];

    public static function available(): bool
    {
        return class_exists(\Dom\HTMLDocument::class);
    }

    /**
     * @return list<string> human readable differences (empty = equivalent)
     */
    public static function diff(string $a, string $b, string $keepAttribute = 'data-ws-keep', int $limit = 5): array
    {
        $x = self::flatten($a, $keepAttribute);
        $y = self::flatten($b, $keepAttribute);
        $diffs = [];

        if (count($x) !== count($y)) {
            $diffs[] = 'node count ' . count($x) . ' vs ' . count($y);
        }

        $n = min(count($x), count($y));
        for ($i = 0; $i < $n && count($diffs) < $limit; $i++) {
            [$kindA, $pathA, $valA, $keep] = $x[$i];
            [$kindB, $pathB, $valB] = $y[$i];

            if ($kindA !== $kindB || $pathA !== $pathB) {
                $diffs[] = "structure at #$i: $kindA $pathA vs $kindB $pathB";
                break;
            }
            if ($kindA === '#text') {
                if ($keep ? $valA !== $valB : self::collapse($valA) !== self::collapse($valB)) {
                    $diffs[] = "text at #$i in $pathA: " . Harness::show($valA) . ' vs ' . Harness::show($valB);
                } elseif ($valA !== '' && $valB === '') {
                    $diffs[] = "text emptied at #$i in $pathA";
                }
            } elseif ($valA !== $valB) {
                $diffs[] = "attributes of $kindA at #$i in $pathA: " . Harness::show($valA) . ' vs ' . Harness::show($valB);
            }
        }

        return $diffs;
    }

    public static function collapse(string $s): string
    {
        return (string) preg_replace('~[\t\n\f\r ]++~', ' ', $s);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    private static function flatten(string $html, string $keepAttribute): array
    {
        $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $out = [];
        self::walk($doc, $out, false, '', $keepAttribute);

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: bool}>  $out
     */
    private static function walk(\Dom\Node $node, array &$out, bool $keep, string $path, string $keepAttribute): void
    {
        $lastText = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \Dom\Comment) {
                continue;
            }
            if ($child instanceof \Dom\Text) {
                if ($lastText !== null) {
                    $out[$lastText][2] .= $child->data;
                } else {
                    $out[] = ['#text', $path, $child->data, $keep];
                    $lastText = array_key_last($out);
                }

                continue;
            }
            $lastText = null;

            if ($child instanceof \Dom\Element) {
                $attrs = [];
                foreach ($child->attributes as $attr) {
                    $attrs[] = $attr->name . '=' . $attr->value;
                }
                $isKeep = $keep
                    || in_array(strtolower($child->localName), self::PROTECTED, true)
                    || ($keepAttribute !== '' && $child->hasAttribute($keepAttribute));
                $name = ($child->namespaceURI ?? '') . ':' . $child->localName;
                $out[] = ['<' . $name, $path, implode("\x1F", $attrs), $isKeep];
                self::walk($child, $out, $isKeep, $path . '/' . $child->localName, $keepAttribute);
                $out[] = ['/' . $name, $path, '', $isKeep];
            } elseif ($child instanceof \Dom\DocumentType) {
                $out[] = ['!doctype', $path, $child->name . '|' . $child->publicId . '|' . $child->systemId, $keep];
            } else {
                $out[] = ['?' . get_class($child), $path, (string) $child->textContent, $keep];
            }
        }
    }
}
