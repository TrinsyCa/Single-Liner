<?php

declare(strict_types=1);

namespace TrinsyCa\SingleLiner\Tests;

/**
 * Seeded random HTML generator. It deliberately produces sloppy markup:
 * omitted end tags, quotes in attribute names and unquoted values, `>` inside
 * values, stray `<`, comments of every shape, raw-text elements holding
 * markup, non-ASCII spaces and invalid UTF-8.
 *
 * <noscript> is left out: Dom\HTMLDocument parses with scripting disabled,
 * where its content is markup, while browsers (scripting enabled) read it as
 * raw text like the minifier does. A <select> is always closed and only gets
 * options and text:
 * lexbor still implements the old "in select" insertion mode, which ignores
 * <style>/<title>/<xmp> start tags there instead of switching to raw text,
 * while current browsers parse select content like body content.
 */
final class Fuzz
{
    // No form feed: lexbor (Dom\HTMLDocument) treats it as a non-space
    // character in tree construction, unlike browsers, so the DOM reference
    // itself would be wrong. The golden tests cover form feeds.
    private const WS = [' ', '  ', "\n", "\n    ", "\t", "\r\n", " \n\t ", "\r", '', '', ''];

    private const TEXT = [
        'a', 'Ali Veli', '100', 'EUR', '&amp;', '&nbsp;', '&lt;b&gt;', "\xC2\xA0", "\xE2\x80\xAF", "1\xE2\x80\xAF000", 'ą',
        "\xFF", '<', ' < ', '>', '"', "'", '=', '&', '/', '-->', '<3', 'x < y', '&amp', 'İş', "\x0B",
    ];

    private const NAMES = [
        'div', 'span', 'b', 'i', 'a', 'p', 'pre', 'textarea', 'script', 'style', 'title', 'li', 'ul',
        'table', 'tr', 'td', 'svg', 'path', 'template', 'select', 'option', 'br', 'img', 'input', 'listing',
        'button', 'code', 'PRE', 'Span', 'pre-x', 'xmp', 'iframe', 'h1', 'em', 'label', 'math', 'mi',
    ];

    private const ATTR_NAMES = ['class', 'id', 'title', 'data-x', 'data-ws-keep', 'DATA-WS-KEEP', 'data-ws-keeper', 'href', '"q', "a'b", 'x/y', 'type', 'value'];

    private const VALUES = [
        '"a  b"', "'c\n  d'", '"x > y"', '"it\'s"', "'say \"hi\"'", 'plain', "x'y", 'x"y', '"data-ws-keep"', '""', "''",
        '"line1&#10;line2"', "\"multi\n\n   line\"", '"</script>"', '"<!-- -->"', 'a=b', 'module', 'application/json', '"text/javascript"',
    ];

    private const RAW = [
        '</div>', '<p>', '<!--', '-->', 'var a = 1;', '  ', "\n", 'x < y', '</scrip', '</script ', '</style', '<b>b</b>',
        '"a  b"', '{"k": "v  w"}', "// c\n", '`t ${x}`', '</textarea', '</title>',
    ];

    private int $depth = 0;

    public function __construct(int $seed)
    {
        mt_srand($seed);
    }

    public function document(): string
    {
        $this->depth = 0;
        $html = '';
        if (mt_rand(0, 3) === 0) {
            $html .= $this->pick(self::WS) . '<!DOCTYPE html>' . $this->pick(self::WS);
        }
        if (mt_rand(0, 2) === 0) {
            $html .= '<html>' . $this->pick(self::WS) . '<head>' . $this->pick(self::WS) . $this->nodes(3) . '</head>'
                . $this->pick(self::WS) . '<body>' . $this->nodes(8) . '</body>' . $this->pick(self::WS) . '</html>';
        } else {
            $html .= $this->nodes(10);
        }

        return $html;
    }

    private function nodes(int $max): string
    {
        $out = '';
        $count = mt_rand(0, $max);
        for ($i = 0; $i < $count; $i++) {
            $out .= $this->node();
        }

        return $out;
    }

    private function node(): string
    {
        $r = mt_rand(0, 99);
        if ($r < 30) {
            return $this->pick(self::WS) . $this->pick(self::TEXT) . $this->pick(self::WS);
        }
        if ($r < 38) {
            return $this->comment();
        }
        if ($r < 40) {
            return $this->pick(['<!DOCTYPE html>', '<?php x ?>', '</ >', '<![CDATA[ a  b ]]>', '<!x>', '</3>']);
        }
        if ($this->depth > 5) {
            return $this->pick(self::TEXT);
        }

        $name = $this->pick(self::NAMES);
        $lower = strtolower($name);
        $open = '<' . $name . $this->attributes() . $this->pick(['>', '>', '>', ' >', "\n>", '/>', ' />']);

        if (in_array($lower, ['script', 'style', 'textarea', 'title', 'xmp', 'iframe'], true)) {
            $body = '';
            for ($i = mt_rand(0, 5); $i > 0; $i--) {
                $body .= $this->pick(self::RAW) . $this->pick(self::WS);
            }
            // Keep the sample well-formed enough that the raw element ends.
            $body = str_ireplace('</' . $lower, '<\\/' . $lower, $body);

            return $open . $body . '</' . $name . $this->pick(['>', ' >', "\n>"]);
        }

        $this->depth++;
        $inner = $lower === 'select' ? $this->options() : $this->nodes(4);
        $this->depth--;
        $close = mt_rand(0, 9) === 0 && $lower !== 'select' ? '' : '</' . $name . $this->pick(['>', '>', ' >', "\n>"]);

        return $open . $inner . $close;
    }

    private function options(): string
    {
        $out = '';
        for ($i = mt_rand(0, 3); $i > 0; $i--) {
            $out .= $this->pick(self::WS) . '<option' . $this->attributes() . '>' . $this->pick(self::TEXT)
                . $this->pick(self::WS) . $this->pick(['</option>', '', "</option\n>"]);
        }

        return $out;
    }

    private function attributes(): string
    {
        $out = '';
        for ($i = mt_rand(0, 3); $i > 0; $i--) {
            $out .= $this->pick([' ', ' ', "\n    ", "\t", '  ']) . $this->pick(self::ATTR_NAMES);
            if (mt_rand(0, 3) > 0) {
                $out .= $this->pick(['=', '=', ' = ', "\n=\n"]) . $this->pick(self::VALUES);
            }
        }

        return $out;
    }

    private function comment(): string
    {
        return $this->pick([
            '<!-- c -->', '<!--[if BLOCK]><![endif]-->', '<!--! keep -->', '<!---->', '<!-->', '<!--->',
            '<!-- a -- b -->', "<!--\n multi\n-->", '<!--x--!>', '<!-- <div>  x  </div> -->', '<!--[if IE]><p>  x  </p><![endif]-->',
        ]);
    }

    /**
     * @template T
     *
     * @param  list<T>  $list
     * @return T
     */
    private function pick(array $list): mixed
    {
        return $list[mt_rand(0, count($list) - 1)];
    }
}
