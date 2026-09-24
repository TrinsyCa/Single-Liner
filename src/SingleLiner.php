<?php

declare(strict_types=1);

namespace TrinsyCa\SingleLiner;

/**
 * Single-Liner v2 — a whitespace minifier for server-rendered HTML.
 *
 * Guarantees of minifyHtml() with the default options:
 *
 *  - Outside protected zones every run of whitespace in text (tab, LF, CR,
 *    space) becomes exactly ONE space — never zero. Deleting the whitespace
 *    between two inline elements would change the rendering and the
 *    textContent; collapsing it to one space changes neither under the
 *    default `white-space: normal`. A form feed in text is left alone: the
 *    HTML parser calls it whitespace, but CSS does not (it is drawn as a
 *    control character), so replacing it could change what is shown.
 *  - Whitespace inside a tag, outside attribute values, becomes one space
 *    (form feed included: there it is only a separator). Attribute values
 *    are byte-identical.
 *  - <script>, <style>, <textarea>, <title>, <pre>, <listing>, <xmp>,
 *    <iframe>, <noembed>, <noframes>, <noscript>, inline <svg> and <math>
 *    (foreign content, where the raw-text rules differ) and every element
 *    that carries the keep attribute (default `data-ws-keep`) pass through
 *    byte-identical, start and end tags included. <plaintext> keeps the rest
 *    of the document.
 *  - A <pre>, <listing>, <svg>, <math> or keep zone ends at its matching end
 *    tag only when everything inside it is properly nested and closed;
 *    otherwise the HTML parser might ignore that end tag and keep the element
 *    open, so the rest of the document is copied verbatim instead.
 *  - HTML comments are removed, except those starting with `[` (conditional
 *    comments, Livewire morph markers) or `!`, and except a comment right
 *    after a character other than `>` or whitespace: removing that one could
 *    glue `<` + `div>` or `&` + `amp;` into new markup.
 *  - Whitespace in front of a leading <!DOCTYPE> is dropped.
 *  - Only ASCII whitespace is touched and the code works on bytes (no /u), so
 *    NBSP, U+202F and invalid UTF-8 pass through untouched.
 *  - On a PCRE failure the input comes back unchanged — never null.
 *  - Markup that runs to the end of the input unfinished (a tag, a comment)
 *    stops minification there: the rest is copied verbatim.
 *
 * The tokenizer follows the HTML standard's tag grammar: a quote opens an
 * attribute value only right after `=`, so a quote inside an attribute name
 * or an unquoted value can never shift it into or out of a value.
 */
final class SingleLiner
{
    public const VERSION = '2.0.0';

    public const DEFAULT_OPTIONS = [
        // Elements with this attribute keep their whole content verbatim.
        // null switches the escape hatch off.
        'keep_attribute' => 'data-ws-keep',
        'remove_comments' => true,
        // Opt-in: run classic/module inline scripts through minifyJs().
        'minify_js' => false,
        // Opt-in: run inline <style> blocks through minifyCss().
        'minify_css' => false,
    ];

    /* ------------------------------------------------------------------
     * Grammar building blocks (HTML standard, "Tokenization").
     * ------------------------------------------------------------------ */

    /** Attribute value after `=`: quoted, unquoted, or missing before `>`. */
    private const VAL = '(?:"[^"]*+"|\'[^\']*+\'|[^\t\n\f\r >"\'][^\t\n\f\r >]*+|(?=>))';

    /** The `= value` part, mandatory once an `=` follows the attribute name. */
    private const ATTR_TAIL = '(?(?=[\t\n\f\r ]*+=)[\t\n\f\r ]*+=[\t\n\f\r ]*+' . self::VAL . ')';

    /** One attribute. Its first character may be `=`, later ones may not. */
    private const ATTR = '[^\t\n\f\r />][^\t\n\f\r />=]*+' . self::ATTR_TAIL;

    /** Everything after the tag name, up to and including `>`. */
    private const REST = '(?:[\t\n\f\r ]++|/|' . self::ATTR . ')*+>';

    /** A complete start or end tag. */
    private const TAG = '</?[a-zA-Z][^\t\n\f\r />]*+' . self::REST;

    /** The same grammar, matching only if every whitespace run outside values is one space. */
    private const CLEAN_TAG = '</?[a-zA-Z][^\t\n\f\r />]*+(?: (?![\t\n\f\r ])|/|[^\t\n\f\r />][^\t\n\f\r />=]*+'
        . '(?(?= ?+=) ?+= ?+' . self::VAL . '))*+>';

    /** A comment, including the abrupt `<!-->` / `<!--->` forms and `--!>`. */
    private const COMMENT = '<!--(?:-?>|(?:[^-]++|-(?!-!?>))*+--!?>)';

    /** Copied as a unit: CDATA, declarations, bogus comments. */
    private const OPAQUE = '<!\[CDATA\[[\s\S]*?\]\]>|<!(?!--)[^>]*+>|<\?[^>]*+>|</(?![a-zA-Z])[^>]*+>';

    /** Raw-text and RCDATA elements: content ends at the first matching end tag. */
    private const RAW_NAMES = 'script|style|textarea|title|xmp|iframe|noembed|noframes|noscript';

    private const RAW_LIST = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'];

    /** Start tags that make the parser leave SVG/MathML (`font` only with some attributes; always, to be safe). */
    private const BREAKOUT = [
        'b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed',
        'font', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta',
        'nobr', 'ol', 'p', 'pre', 'ruby', 's', 'small', 'span', 'strong', 'strike', 'sub', 'sup', 'table', 'tt',
        'u', 'ul', 'var',
    ];

    /** SVG/MathML elements whose children are read by HTML rules again. */
    private const INTEGRATION_POINTS = ['foreignobject', 'desc', 'title', 'mi', 'mo', 'mn', 'ms', 'mtext'];

    /**
     * Normal elements copied verbatim as a whole: the two the UA lays out as
     * preformatted text, and the two that open foreign content. Inside SVG
     * and MathML the tokenizer rules differ (<title>, <style>, <textarea> are
     * not raw text there, CDATA sections exist), so a byte-level reading of
     * foreign content could end a "raw" element where the parser does not.
     */
    private const ZONE_NAMES = 'pre|listing|svg|math';

    /** Where the tokenizer ends a tag name. */
    private const BOUNDARY = '(?=[\t\n\f\r />])';

    private const VOID_ELEMENTS = [
        'area', 'base', 'basefont', 'bgsound', 'br', 'col', 'embed', 'frame', 'hr', 'image',
        'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private const JS_MIME = [
        'application/ecmascript', 'application/javascript', 'application/x-ecmascript',
        'application/x-javascript', 'text/ecmascript', 'text/javascript', 'text/javascript1.0',
        'text/javascript1.1', 'text/javascript1.2', 'text/javascript1.3', 'text/javascript1.4',
        'text/javascript1.5', 'text/jscript', 'text/livescript', 'text/x-ecmascript',
        'text/x-javascript', 'module',
    ];

    /** Internal exception codes (never leave this class). */
    private const E_PCRE = 0x51;

    private const E_BAIL = 0x52;

    /** @var array<string, array<string, string>> compiled pattern sets per keep attribute */
    private static array $patterns = [];

    private static ?string $lastError = null;

    /**
     * Minifies an HTML document or fragment; see the class comment for the
     * guarantees. On a PCRE failure the input comes back unchanged.
     *
     * @param  array{keep_attribute?: ?string, remove_comments?: bool, minify_js?: bool, minify_css?: bool}  $options
     */
    public static function minifyHtml(string $html, array $options = []): string
    {
        $options = self::options($options);
        self::$lastError = null;

        if ($html === '') {
            return $html;
        }

        try {
            return self::html($html, $options);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== self::E_PCRE) {
                throw $e;
            }
            self::$lastError = $e->getMessage();

            return $html;
        }
    }

    /**
     * The PCRE error that made the last minifyHtml() call return its input,
     * or null when it did not fall back.
     */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /* ==================================================================
     * HTML
     * ================================================================== */

    /**
     * @param  array{keep_attribute: ?string, remove_comments: bool, minify_js: bool, minify_css: bool}  $options
     */
    private static function html(string $html, array $options): string
    {
        $p = self::patterns($options['keep_attribute']);
        $len = strlen($html);
        $parts = [];

        // Whitespace before a leading doctype is ignored by the parser (the
        // "initial" insertion mode), so it goes. A BOM stays in front.
        $pos = str_starts_with($html, "\xEF\xBB\xBF") ? 3 : 0;
        $lead = strspn($html, "\t\n\r ", $pos);
        if ($lead > 0 && $pos + $lead + 9 <= $len && substr_compare($html, '<!doctype', $pos + $lead, 9, true) === 0) {
            $parts[] = substr($html, 0, $pos);
            $pos += $lead;
        } else {
            $pos = 0;
        }

        $chunkStart = $pos;
        $removeComments = $options['remove_comments'];

        while ($pos < $len) {
            $found = preg_match($p['find'], $html, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $pos);
            if ($found === false) {
                self::pcreFailed();
            }
            if ($found === 0) {
                break;
            }

            $start = $m[0][1];
            $afterTag = $start + strlen($m[0][0]);

            if ($m['bad'][0] !== null || $m['plain'][0] !== null) {
                // Unfinished markup or <plaintext>: the rest is copied as is.
                $parts[] = self::chunk(substr($html, $chunkStart, $start - $chunkStart), $p, $removeComments);
                $parts[] = substr($html, $start);

                return implode('', $parts);
            }

            if ($m['raw'][0] !== null) {
                $name = strtolower($m['rawname'][0]);
                $end = self::rawTextEnd($html, $afterTag, $name, $p);
            } else {
                // <pre>/<listing>/<svg>/<math>, or an element carrying the
                // keep attribute.
                $name = strtolower($m['prename'][0] ?? $m['keepname'][0]);
                if (in_array($name, self::VOID_ELEMENTS, true)
                    || ($m['prename'][0] !== null && $m['psc'][0] !== null && ($name === 'svg' || $name === 'math'))) {
                    $pos = $afterTag; // no content to protect (<svg/> closes itself)

                    continue;
                }
                $end = self::balancedEnd($html, $afterTag, $name, $p);
            }

            $parts[] = self::chunk(substr($html, $chunkStart, $start - $chunkStart), $p, $removeComments);

            if ($end === null) {
                $parts[] = substr($html, $start);

                return implode('', $parts);
            }

            $parts[] = self::zone($html, $start, $afterTag, $end[0], $end[1], $m['raw'][0] !== null ? $name : null, $options);
            $pos = $chunkStart = $end[1];
        }

        $parts[] = self::chunk(substr($html, $chunkStart), $p, $removeComments);

        return implode('', $parts);
    }

    /**
     * Minifies a stretch of ordinary markup. The finder guarantees it holds
     * no protected zone and no unfinished construct.
     *
     * @param  array<string, string>  $p
     */
    private static function chunk(string $chunk, array $p, bool $removeComments): string
    {
        if ($chunk === '') {
            return '';
        }

        // Pass 1: comments go, tags with loose whitespace are tightened.
        $chunk = preg_replace_callback(
            $removeComments ? $p['tidy'] : $p['tidy_keep_comments'],
            static fn (array $m): string => $m[0][1] === '!' ? '' : self::normalizeTag($m[0]),
            $chunk,
        );
        if ($chunk === null) {
            self::pcreFailed();
        }

        // Pass 2: every whitespace run in text becomes one space.
        $chunk = preg_replace($p['space'], ' ', $chunk);
        if ($chunk === null) {
            self::pcreFailed();
        }

        return $chunk;
    }

    /**
     * A protected zone: copied verbatim, unless the caller opted into inline
     * script/style minification.
     *
     * @param  array{keep_attribute: ?string, remove_comments: bool, minify_js: bool, minify_css: bool}  $options
     */
    private static function zone(string $html, int $start, int $contentStart, int $closeStart, int $closeEnd, ?string $rawName, array $options): string
    {
        $open = substr($html, $start, $contentStart - $start);
        $content = substr($html, $contentStart, $closeStart - $contentStart);
        $close = substr($html, $closeStart, $closeEnd - $closeStart);

        if ($rawName === 'script' && $options['minify_js'] && self::isJavaScript($open)) {
            $min = self::minifyJs($content);
            // The new body must not change where the HTML parser ends the element.
            if (! self::introduces($content, $min, ['</script', '<!--', '<script'])) {
                $content = $min;
            }
        } elseif ($rawName === 'style' && $options['minify_css'] && self::isCss($open)) {
            $min = self::minifyCss($content);
            if (! self::introduces($content, $min, ['</style'])) {
                $content = $min;
            }
        }

        return $open . $content . $close;
    }

    /**
     * End of a raw-text/RCDATA element: the first `</name` + boundary, then
     * the rest of that end tag.
     *
     * @param  array<string, string>  $p
     * @return array{0: int, 1: int}|null [end tag start, end tag end]; null = copy the rest verbatim
     */
    private static function rawTextEnd(string $html, int $from, string $name, array $p): ?array
    {
        $len = strlen($html);
        $needle = '</' . $name;
        $at = $from;

        while (($i = stripos($html, $needle, $at)) !== false) {
            $after = $i + strlen($needle);
            if ($after >= $len) {
                return null;
            }
            if (strpos("\t\n\f\r />", $html[$after]) === false) {
                $at = $after;

                continue;
            }

            if ($name === 'script' && self::mayBeDoubleEscaped(substr($html, $from, $i - $from))) {
                // `<!--` ... `<script` puts the tokenizer in the "double
                // escaped" state, where `</script>` does not end the element.
                return null;
            }

            $found = preg_match($p['rest'], $html, $m, 0, $after);
            if ($found === false) {
                self::pcreFailed();
            }

            return $found === 1 ? [$i, $after + strlen($m[0])] : null;
        }

        return null;
    }

    private static function mayBeDoubleEscaped(string $script): bool
    {
        $comment = strpos($script, '<!--');
        if ($comment === false) {
            return false;
        }
        $found = preg_match('~<script[\t\n\f\r />]~i', $script, $m, 0, $comment);
        if ($found === false) {
            self::pcreFailed();
        }

        return $found === 1;
    }

    /**
     * End of a normal element whose content is protected (<pre>, <listing>,
     * <svg>, <math>, keep attribute). The zone ends at the end tag that
     * closes the start tag, and only when every element opened inside it is
     * closed in order before that. For anything else — a stray or misnested
     * end tag, an element left open, an HTML tag that breaks out of SVG or
     * MathML — the tree builder may ignore that end tag and keep the element
     * open past it (an unclosed <div> inside a <span> does exactly that), so
     * the caller copies the rest of the input verbatim: more stays verbatim,
     * never less. Comments, attribute values and raw-text elements are
     * skipped as units.
     *
     * @param  array<string, string>  $p
     * @return array{0: int, 1: int}|null
     */
    private static function balancedEnd(string $html, int $from, string $name, array $p): ?array
    {
        // [lower-case name, true when the element is SVG/MathML]
        $stack = [[$name, $name === 'svg' || $name === 'math']];
        $pos = $from;

        while (true) {
            $found = preg_match($p['zone'], $html, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $pos);
            if ($found === false) {
                self::pcreFailed();
            }
            if ($found === 0 || $m['bad'][0] !== null || $m['plain'][0] !== null) {
                return null;
            }

            $start = $m[0][1];
            $end = $start + strlen($m[0][0]);
            $tag = strtolower($m['tn'][0]);
            $pos = $end;

            // Tags are read by HTML rules unless the current node is a
            // foreign element that is not an integration point.
            [$top, $topForeign] = $stack[count($stack) - 1];
            $htmlRules = ! $topForeign || in_array($top, self::INTEGRATION_POINTS, true);

            if ($m['close'][0] !== null) {
                if ($tag === 'br' || ($tag === 'p' && ! $htmlRules)) {
                    if ($htmlRules) {
                        continue; // the parser turns </br> into <br>
                    }

                    return null; // </br> and </p> break out of foreign content
                }
                if ($top !== $tag) {
                    return null;
                }
                array_pop($stack);
                if ($stack === []) {
                    return [$start, $end];
                }

                continue;
            }

            $selfClosing = $m['sc'][0] !== null;

            if (! $htmlRules) {
                if (in_array($tag, self::BREAKOUT, true)) {
                    return null; // the parser closes the SVG/MathML element here
                }
                if (! $selfClosing) {
                    $stack[] = [$tag, true]; // <style>, <title>… are markup here
                }

                continue;
            }

            if (in_array($tag, self::RAW_LIST, true)) {
                $raw = self::rawTextEnd($html, $end, $tag, $p);
                if ($raw === null) {
                    return null;
                }
                $pos = $raw[1];
            } elseif ($tag === 'svg' || $tag === 'math') {
                if (! $selfClosing) {
                    $stack[] = [$tag, true];
                }
            } elseif (! in_array($tag, self::VOID_ELEMENTS, true)) {
                $stack[] = [$tag, false]; // a trailing `/` means nothing in HTML
            }
        }
    }

    /**
     * Collapses the whitespace of one tag outside its attribute values.
     */
    private static function normalizeTag(string $tag): string
    {
        // Fast path: when quotes appear only as value delimiters, a plain
        // left-to-right scan pairs them exactly like the HTML tokenizer.
        $strict = preg_match(
            '~\A</?[a-zA-Z][^\t\n\f\r />"\']*+(?:[\t\n\f\r ]++|/|[^\t\n\f\r />"\'][^\t\n\f\r />="\']*+'
            . '(?(?=[\t\n\f\r ]*+=)[\t\n\f\r ]*+=[\t\n\f\r ]*+(?:"[^"]*+"|\'[^\']*+\'|[^\t\n\f\r >"\'][^\t\n\f\r >"\']*+|(?=>))))*+>\z~',
            $tag,
        );
        if ($strict === false) {
            self::pcreFailed();
        }

        if ($strict === 1) {
            $out = preg_replace('~(?:"[^"]*+"|\'[^\']*+\')(*SKIP)(*F)|[\t\n\f\r ]++~', ' ', $tag);
            if ($out === null) {
                self::pcreFailed();
            }

            return $out;
        }

        // Slow path: attribute by attribute. Anything it cannot account for
        // leaves the tag untouched.
        $tokens = self::tagTokens($tag);
        if ($tokens === null) {
            return $tag;
        }

        $out = '';
        foreach ($tokens as $t) {
            if ($t['w'] !== null) {
                $out .= ' ';
            } elseif ($t['a'] !== null && $t['v'] !== null) {
                $out .= $t['a'] . ($t['w1'] !== '' ? ' ' : '') . '=' . ($t['w2'] !== '' ? ' ' : '') . $t['v'];
            } else {
                $out .= $t[0];
            }
        }

        return $out;
    }

    /**
     * Splits a complete tag into tokens: name, whitespace, `/`, `>` and
     * attributes (name, whitespace around `=`, value).
     *
     * @return list<array<int|string, ?string>>|null null when the tokens do not cover the tag
     */
    private static function tagTokens(string $tag): ?array
    {
        $count = preg_match_all(
            '~\G(?:(?<n>^</?[a-zA-Z][^\t\n\f\r />]*+)|(?<w>[\t\n\f\r ]++)|(?<o>[/>])'
            . '|(?<a>[^\t\n\f\r />][^\t\n\f\r />=]*+)(?:(?=[\t\n\f\r ]*+=)(?<w1>[\t\n\f\r ]*+)=(?<w2>[\t\n\f\r ]*+)'
            . '(?<v>"[^"]*+"|\'[^\']*+\'|[^\t\n\f\r >"\'][^\t\n\f\r >]*+|(?=>)))?)~',
            $tag,
            $tokens,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );
        if ($count === false) {
            self::pcreFailed();
        }

        $consumed = 0;
        foreach ($tokens as $t) {
            $consumed += strlen($t[0]);
        }

        return $consumed === strlen($tag) ? $tokens : null;
    }

    /**
     * @return array<string, string>
     */
    private static function patterns(?string $keep): array
    {
        $key = $keep ?? '';
        if (isset(self::$patterns[$key])) {
            return self::$patterns[$key];
        }

        $raw = '(?<raw><(?<rawname>(?i:' . self::RAW_NAMES . '))' . self::BOUNDARY . self::REST . ')';
        $plain = '(?<plain><(?i:plaintext)' . self::BOUNDARY . ')';
        $bad = '(?<bad><[a-zA-Z/!?])';
        $skip = '(?:' . self::COMMENT . '|' . self::OPAQUE . ')(*SKIP)(*F)';

        // Every tag is parsed once: end tags and start tags without the keep
        // attribute are skipped, a start tag with it opens a protected zone.
        $keepAlt = $keep === null
            ? ''
            : '|(?<k>(?i:' . preg_quote($keep, '~') . '))(?=[\t\n\f\r />=])' . self::ATTR_TAIL;
        $generic = '<(?<et>/)?(?<keepname>[a-zA-Z][^\t\n\f\r />]*+)(?:[\t\n\f\r ]++|/' . $keepAlt . '|' . self::ATTR . ')*+>'
            . ($keep === null ? '(*SKIP)(*F)' : '(?(et)(*SKIP)(*F)|(?(k)|(*SKIP)(*F)))');

        $find = '~' . $skip
            . '|' . $raw
            . '|<(?<prename>(?i:' . self::ZONE_NAMES . '))' . self::BOUNDARY . '(?:[\t\n\f\r ]++|/(?!>)|' . self::ATTR . ')*+(?<psc>/)?>'
            . '|' . $plain
            . '|' . $generic
            . '|' . $bad . '~';

        // Inside a protected zone every tag counts: the name, whether it
        // closes, and whether it ends with a self-closing `/`.
        $zone = '~' . $skip
            . '|' . $plain
            . '|<(?<close>/)?(?<tn>[a-zA-Z][^\t\n\f\r />]*+)(?:[\t\n\f\r ]++|/(?!>)|' . self::ATTR . ')*+(?<sc>/)?>'
            . '|' . $bad . '~';

        // A comment is removed only after `>`, whitespace or the chunk start.
        $tidy = '~(?:' . self::CLEAN_TAG . '|<!--(?=[\[!])' . substr(self::COMMENT, 4) . '|' . self::OPAQUE . ')(*SKIP)(*F)'
            . '|(?<![^>\t\n\f\r ])' . self::COMMENT
            . '|' . self::COMMENT . '(*SKIP)(*F)'
            . '|' . self::TAG . '~';

        $tidyKeepComments = '~(?:' . self::CLEAN_TAG . '|' . self::COMMENT . '|' . self::OPAQUE . ')(*SKIP)(*F)|' . self::TAG . '~';

        $space = '~(?:' . self::TAG . '|' . self::COMMENT . '|' . self::OPAQUE . ')(*SKIP)(*F)|[\t\n\r ]{2,}+|[\t\n\r]~';

        return self::$patterns[$key] = [
            'find' => $find,
            'zone' => $zone,
            'tidy' => $tidy,
            'tidy_keep_comments' => $tidyKeepComments,
            'space' => $space,
            'rest' => '~\G' . self::REST . '~',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{keep_attribute: ?string, remove_comments: bool, minify_js: bool, minify_css: bool}
     */
    private static function options(array $options): array
    {
        $unknown = array_diff_key($options, self::DEFAULT_OPTIONS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown Single-Liner option(s): ' . implode(', ', array_keys($unknown)));
        }

        $options += self::DEFAULT_OPTIONS;
        $keep = $options['keep_attribute'];
        if ($keep !== null && (! is_string($keep) || ! self::isAttributeName($keep))) {
            throw new \InvalidArgumentException('keep_attribute must be an attribute name or null.');
        }

        return [
            'keep_attribute' => $keep,
            'remove_comments' => (bool) $options['remove_comments'],
            'minify_js' => (bool) $options['minify_js'],
            'minify_css' => (bool) $options['minify_css'],
        ];
    }

    /**
     * `[a-zA-Z_:][-a-zA-Z0-9_:.]*`, checked without PCRE so that option
     * validation cannot fail for PCRE's reasons.
     */
    private static function isAttributeName(string $name): bool
    {
        $first = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_:';

        return $name !== ''
            && strspn($name, $first, 0, 1) === 1
            && strspn($name, $first . '0123456789-.') === strlen($name);
    }

    /**
     * @param  list<string>  $needles
     */
    private static function introduces(string $before, string $after, array $needles): bool
    {
        $before = strtolower($before);
        $after = strtolower($after);
        foreach ($needles as $needle) {
            if (substr_count($after, $needle) > substr_count($before, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function isJavaScript(string $openTag): bool
    {
        $attrs = self::attributes($openTag);
        if ($attrs === null || array_key_exists('src', $attrs)) {
            return false; // with src the body is ignored and may hold anything
        }
        $type = self::mimeEssence($attrs['type'] ?? '');

        return $type === '' || in_array($type, self::JS_MIME, true);
    }

    private static function isCss(string $openTag): bool
    {
        $attrs = self::attributes($openTag);
        if ($attrs === null) {
            return false;
        }
        $type = self::mimeEssence($attrs['type'] ?? '');

        return $type === '' || $type === 'text/css';
    }

    private static function mimeEssence(string $type): string
    {
        $type = strtolower(html_entity_decode($type, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim(explode(';', $type, 2)[0], "\t\n\f\r ");
    }

    /**
     * @return array<string, string>|null lower-cased name => unquoted raw value (first wins)
     */
    private static function attributes(string $tag): ?array
    {
        $tokens = self::tagTokens($tag);
        if ($tokens === null) {
            return null;
        }

        $attrs = [];
        foreach ($tokens as $t) {
            if ($t['a'] === null) {
                continue;
            }
            $value = $t['v'] ?? '';
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $value = substr($value, 1, -1);
            }
            $attrs[strtolower($t['a'])] ??= $value;
        }

        return $attrs;
    }

    private static function pcreFailed(): never
    {
        throw new \RuntimeException(preg_last_error_msg(), self::E_PCRE);
    }

    private static function bail(): never
    {
        throw new \RuntimeException('Single-Liner: not provably safe', self::E_BAIL);
    }

    /* ==================================================================
     * CSS (opt-in)
     * ================================================================== */

    /**
     * Removes comments (`/*!` ones stay) and collapses whitespace outside
     * strings and url(). Whitespace next to `{`, `}`, `;` or `,` is dropped;
     * anywhere else a run becomes one space, because a space is a combinator
     * in selectors (`a :hover` is not `a:hover`) and is required around
     * `+`/`-` in calc(). Custom property values (`--x: …;`) stay verbatim.
     * Returns the input unchanged when it cannot tokenize it (an unterminated
     * string or comment, a stray backslash).
     */
    public static function minifyCss(string $css): string
    {
        try {
            return self::css($css);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== self::E_PCRE && $e->getCode() !== self::E_BAIL) {
                throw $e;
            }

            return $css;
        }
    }

    private static function css(string $css): string
    {
        $count = preg_match_all(
            '~\G(?:'
            . '(?<s>"(?:[^"\\\\\n\r\f]|\\\\[\s\S])*+"|\'(?:[^\'\\\\\n\r\f]|\\\\[\s\S])*+\')'
            . '|(?<c>/\*[\s\S]*?\*/)'
            . '|(?<u>[uU][rR][lL]\([\t\n\f\r ]*+(?:[^()"\'\\\\\t\n\f\r ]|\\\\[^\n\r\f])*+[\t\n\f\r ]*+\))'
            . '|(?<w>[\t\n\f\r ]++)'
            . '|(?<e>\\\\(?:[0-9a-fA-F]{1,6}(?:\r\n|[\t\n\f\r ])?|[^\n\r\f0-9a-fA-F]))'
            . '|(?<n>-?-?[a-zA-Z_\x80-\xFF][a-zA-Z0-9_\x80-\xFF-]*+)'
            . '|(?<p>[{}()\[\];:,])'
            . '|(?<o>[^"\'/\\\\\t\n\f\r a-zA-Z_\x80-\xFF{}()\[\];:,-]++|-|/(?!\*))'
            . ')~',
            $css,
            $tokens,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );
        if ($count === false) {
            self::pcreFailed();
        }

        $consumed = 0;
        foreach ($tokens as $t) {
            $consumed += strlen($t[0]);
        }
        if ($consumed !== strlen($css)) {
            self::bail();
        }

        $out = '';
        $space = false;
        $comment = false;
        $declStart = true; // at the start of a declaration (after `{`, `;` or the beginning)
        $n = count($tokens);

        for ($k = 0; $k < $n; $k++) {
            $t = $tokens[$k];
            $text = $t[0];

            if ($t['w'] !== null) {
                $space = true;

                continue;
            }
            if ($t['c'] !== null) {
                if (str_starts_with($text, '/*!')) {
                    self::cssSeparate($out, $text, $space, $comment);
                    $out .= $text;
                } else {
                    $comment = true;
                }

                continue;
            }

            if ($declStart && $t['n'] !== null && str_starts_with($text, '--') && self::cssNextIsColon($tokens, $k)) {
                // Custom property: its value is copied token for token up to
                // the `;` (included) or the `}` that closes the block (not).
                self::cssSeparate($out, $text, $space, $comment);
                $depth = 0;
                for (; $k < $n; $k++) {
                    $x = $tokens[$k][0];
                    if ($tokens[$k]['p'] !== null) {
                        if ($x === '(' || $x === '[' || $x === '{') {
                            $depth++;
                        } elseif ($x === ')' || $x === ']' || $x === '}') {
                            if ($depth === 0) {
                                break;
                            }
                            $depth--;
                        } elseif ($x === ';' && $depth === 0) {
                            $out .= ';';

                            break;
                        }
                    }
                    $out .= $x;
                }
                if ($k < $n && $tokens[$k][0] !== ';') {
                    $k--; // the closer is processed normally
                }
                $declStart = true;

                continue;
            }

            self::cssSeparate($out, $text, $space, $comment);
            $out .= $text;
            $declStart = $t['p'] !== null && ($text === '{' || $text === ';');
        }

        return $out;
    }

    /**
     * Writes what a dropped whitespace run or comment must leave behind.
     */
    private static function cssSeparate(string &$out, string $next, bool &$space, bool &$comment): void
    {
        if ($out !== '' && ($space || $comment)) {
            $last = $out[-1];
            $first = $next[0];
            if ($space) {
                if (! str_contains('{};,', $last) && ! str_contains('{};,', $first)) {
                    $out .= ' ';
                }
            } elseif (self::cssGlue($last) && self::cssGlue($first)) {
                // The comment kept two tokens apart (`a/**/b`, `1/**/px`).
                $out .= '/**/';
            }
        }
        $space = $comment = false;
    }

    private static function cssGlue(string $ch): bool
    {
        return ctype_alnum($ch) || ord($ch) >= 0x80 || str_contains('_-\\#@.%+/*!<>', $ch);
    }

    /**
     * @param  list<array<int|string, ?string>>  $tokens
     */
    private static function cssNextIsColon(array $tokens, int $k): bool
    {
        for ($j = $k + 1, $n = count($tokens); $j < $n; $j++) {
            if ($tokens[$j]['w'] !== null || $tokens[$j]['c'] !== null) {
                continue;
            }

            return $tokens[$j][0] === ':';
        }

        return false;
    }

    /* ==================================================================
     * JavaScript (opt-in, conservative)
     * ================================================================== */

    /** Punctuators. */
    private const JS_PUNCT = [
        '>>>=', '...', '===', '!==', '**=', '<<=', '>>=', '>>>', '&&=', '||=', '??=',
        '=>', '==', '!=', '<=', '>=', '&&', '||', '??', '?.', '++', '--', '+=', '-=', '*=', '/=',
        '%=', '&=', '|=', '^=', '**', '<<', '>>',
        '{', '}', '(', ')', '[', ']', ';', ',', '<', '>', '+', '-', '*', '/', '%', '&', '|', '^',
        '!', '~', '?', ':', '=', '.', '@',
    ];

    /** A statement cannot end with these, so a line break after them is never an ASI point. */
    private const JS_CONTINUES = [
        '{', '(', '[', ',', ';', ':', '?', '.', '?.', '...', '=>', '=', '+=', '-=', '*=', '/=', '%=',
        '**=', '<<=', '>>=', '>>>=', '&=', '|=', '^=', '&&=', '||=', '??=', '==', '!=', '===',
        '!==', '<', '>', '<=', '>=', '<<', '>>', '>>>', '+', '-', '*', '/', '%', '**', '&', '|',
        '^', '&&', '||', '??', '!', '~',
    ];

    /** Neither a statement nor a class element can start with these. */
    private const JS_NEXT_SAFE = [
        '.', '?.', ')', ']', '}', ',', ';', ':', '?', '=', '==', '===', '!=', '!==', '<', '>',
        '<=', '>=', '<<', '>>', '>>>', '%', '**', '&', '|', '^', '&&', '||', '??', '+=', '-=',
        '*=', '/=', '%=', '**=', '<<=', '>>=', '>>>=', '&=', '|=', '^=', '&&=', '||=', '??=',
        '/',
    ];

    /** Keywords after which `/` starts a regular expression. */
    private const JS_REGEX_AFTER = [
        'return', 'typeof', 'instanceof', 'in', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else',
    ];

    /** Contextual words after which `/` could be either: the minifier gives up. */
    private const JS_AMBIGUOUS = ['of', 'yield', 'await', 'let', 'async', 'get', 'set', 'static'];

    /**
     * Conservative JavaScript whitespace and comment remover.
     *
     * Strings, template literals (with nested `${}`), regular expressions and
     * numbers are copied verbatim. A line break is dropped only where ASI
     * provably cannot apply: after a token that cannot end a statement, or in
     * front of a token that can start neither a statement nor a class element.
     * The restricted productions (`return`/`break`/`continue`/`throw`/`yield`
     * or `async` followed by a line break, postfix `++`/`--`) therefore keep
     * their line break. Whatever the lexer cannot decide for sure — a `/`
     * after `}`, non-ASCII outside literals, HTML-like comments, a hashbang,
     * unbalanced brackets — returns the input unchanged, and so does an output
     * that does not re-tokenize into exactly the same tokens.
     */
    public static function minifyJs(string $js): string
    {
        try {
            $tokens = self::jsTokens($js);
            $out = self::jsJoin($tokens);

            $again = self::jsTokens($out);
            if (array_column($again, 's') !== array_column($tokens, 's')
                || array_column($again, 't') !== array_column($tokens, 't')) {
                return $js;
            }

            return $out;
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== self::E_PCRE && $e->getCode() !== self::E_BAIL) {
                throw $e;
            }

            return $js;
        }
    }

    /**
     * @return list<array{t: string, s: string, gap: bool, nl: bool, ctl: bool, prop: bool}>
     */
    private static function jsTokens(string $s): array
    {
        $len = strlen($s);
        $i = 0;
        $tokens = [];
        $gap = false;
        $nl = false;
        $braces = [];   // 'b' block/object, 't' template substitution
        $parens = [];   // per '(' : does it wrap an if/while/for/with header?
        $brackets = 0;

        if (str_starts_with($s, '#!')) {
            self::bail();
        }

        $push = static function (string $type, string $text, bool $ctl = false) use (&$tokens, &$gap, &$nl): void {
            $prev = $tokens === [] ? null : $tokens[count($tokens) - 1];
            $tokens[] = [
                't' => $type,
                's' => $text,
                'gap' => $gap,
                'nl' => $nl,
                'ctl' => $ctl,
                // A word right after `.`/`?.` is a property name, not a keyword.
                'prop' => $type === 'word' && $prev !== null && $prev['t'] === 'punct' && ($prev['s'] === '.' || $prev['s'] === '?.'),
            ];
            $gap = $nl = false;
        };

        while ($i < $len) {
            $c = $s[$i];

            if ($c === ' ' || $c === "\t" || $c === "\f" || $c === "\x0B") {
                $gap = true;
                $i++;

                continue;
            }
            if ($c === "\n" || $c === "\r") {
                $gap = $nl = true;
                $i++;

                continue;
            }
            if (ord($c) >= 0x80) {
                self::bail(); // Unicode whitespace, line terminators or identifiers
            }

            $next = $s[$i + 1] ?? '';

            if ($c === '/' && $next === '/') {
                $end = $i + strcspn($s, "\n\r", $i);
                if (self::hasLineSeparator(substr($s, $i, $end - $i))) {
                    self::bail(); // U+2028/2029 would end the comment earlier
                }
                $gap = true;
                $i = $end;

                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($s, '*/', $i + 2);
                if ($end === false) {
                    self::bail();
                }
                $body = substr($s, $i, $end + 2 - $i);
                if (strpbrk($body, "\n\r") !== false || self::hasLineSeparator($body)) {
                    $nl = true; // a multi-line comment counts as a line terminator
                }
                $gap = true;
                $i = $end + 2;

                continue;
            }
            if (($c === '<' && substr($s, $i, 4) === '<!--') || ($c === '-' && substr($s, $i, 3) === '-->')) {
                self::bail(); // HTML-like comments (Annex B)
            }

            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while (true) {
                    if ($j >= $len) {
                        self::bail();
                    }
                    $ch = $s[$j];
                    if ($ch === $c) {
                        break;
                    }
                    if ($ch === "\n" || $ch === "\r") {
                        self::bail();
                    }
                    $j += $ch === '\\' ? 2 : 1;
                }
                $push('str', substr($s, $i, $j + 1 - $i));
                $i = $j + 1;

                continue;
            }

            if ($c === '`') {
                [$text, $i, $open] = self::jsTemplate($s, $i);
                $push($open ? 'tplhead' : 'tpl', $text);
                if ($open) {
                    $braces[] = 't';
                }

                continue;
            }

            if (ctype_digit($c) || ($c === '.' && ctype_digit($next))) {
                $j = $i + 1;
                while ($j < $len && (ctype_alnum($s[$j]) || $s[$j] === '_' || $s[$j] === '.')) {
                    $j++;
                }
                $push('num', substr($s, $i, $j - $i));
                $i = $j;

                continue;
            }

            if (ctype_alpha($c) || $c === '_' || $c === '$' || $c === '\\' || $c === '#') {
                $j = $i;
                while ($j < $len) {
                    $ch = $s[$j];
                    if (ctype_alnum($ch) || $ch === '_' || $ch === '$' || ($ch === '#' && $j === $i)) {
                        $j++;
                    } elseif ($ch === '\\') {
                        if (preg_match('~\G\\\\u(?:[0-9a-fA-F]{4}|\{[0-9a-fA-F]{1,6}\})~', $s, $m, 0, $j) !== 1) {
                            self::bail();
                        }
                        $j += strlen($m[0]);
                    } else {
                        break;
                    }
                }
                if ($c === '#' && $j === $i + 1) {
                    self::bail();
                }
                $push('word', substr($s, $i, $j - $i));
                $i = $j;

                continue;
            }

            if ($c === '/' && self::jsRegexAllowed($tokens)) {
                $j = $i + 1;
                $class = false;
                while (true) {
                    if ($j >= $len) {
                        self::bail();
                    }
                    $ch = $s[$j];
                    if ($ch === "\n" || $ch === "\r" || (ord($ch) >= 0x80 && self::hasLineSeparator(substr($s, $j, 3)))) {
                        self::bail();
                    }
                    if ($ch === '\\') {
                        $j += 2;

                        continue;
                    }
                    if ($ch === '[') {
                        $class = true;
                    } elseif ($ch === ']') {
                        $class = false;
                    } elseif ($ch === '/' && ! $class) {
                        break;
                    }
                    $j++;
                }
                $j++;
                while ($j < $len && (ctype_alnum($s[$j]) || $s[$j] === '_' || $s[$j] === '$')) {
                    $j++;
                }
                $push('regex', substr($s, $i, $j - $i));
                $i = $j;

                continue;
            }

            if ($c === '}' && $braces !== [] && end($braces) === 't') {
                // Closing a substitution continues the template.
                array_pop($braces);
                [$text, $i, $open] = self::jsTemplate($s, $i);
                $push($open ? 'tplhead' : 'tpl', $text);
                if ($open) {
                    $braces[] = 't';
                }

                continue;
            }

            $punct = self::jsPunctAt($s, $i);
            if ($punct === '') {
                self::bail();
            }

            $ctl = false;
            switch ($punct) {
                case '{':
                    $braces[] = 'b';
                    break;
                case '}':
                    if (array_pop($braces) !== 'b') {
                        self::bail();
                    }
                    break;
                case '(':
                    $prev = $tokens === [] ? null : $tokens[count($tokens) - 1];
                    $parens[] = $prev !== null && $prev['t'] === 'word' && ! $prev['prop']
                        && in_array($prev['s'], ['if', 'while', 'for', 'with'], true);
                    break;
                case ')':
                    if ($parens === []) {
                        self::bail();
                    }
                    $ctl = array_pop($parens);
                    break;
                case '[':
                    $brackets++;
                    break;
                case ']':
                    if (--$brackets < 0) {
                        self::bail();
                    }
                    break;
            }

            $push('punct', $punct, $ctl);
            $i += strlen($punct);
        }

        if ($braces !== [] || $parens !== [] || $brackets !== 0) {
            self::bail();
        }

        return $tokens;
    }

    /**
     * Reads template characters from the opening backtick (or the `}` that
     * closes a substitution) to the closing backtick or the next `${`.
     *
     * @return array{0: string, 1: int, 2: bool} [token text, next offset, ends with `${`]
     */
    private static function jsTemplate(string $s, int $start): array
    {
        $len = strlen($s);
        $i = $start + 1;
        while ($i < $len) {
            $ch = $s[$i];
            if ($ch === '\\') {
                $i += 2;

                continue;
            }
            if ($ch === '`') {
                return [substr($s, $start, $i + 1 - $start), $i + 1, false];
            }
            if ($ch === '$' && ($s[$i + 1] ?? '') === '{') {
                return [substr($s, $start, $i + 2 - $start), $i + 2, true];
            }
            $i++;
        }

        self::bail();
    }

    private static function jsPunctAt(string $s, int $i): string
    {
        foreach ([4, 3, 2, 1] as $n) {
            $cand = substr($s, $i, $n);
            if (strlen($cand) === $n && in_array($cand, self::JS_PUNCT, true)) {
                // `?.` is optional chaining only when no digit follows (a ? .5 : b).
                if ($cand === '?.' && ctype_digit($s[$i + 2] ?? '')) {
                    continue;
                }

                return $cand;
            }
        }

        return '';
    }

    /**
     * @param  list<array{t: string, s: string, gap: bool, nl: bool, ctl: bool, prop: bool}>  $tokens
     */
    private static function jsRegexAllowed(array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }
        $prev = $tokens[count($tokens) - 1];

        switch ($prev['t']) {
            case 'num':
            case 'str':
            case 'tpl':
            case 'regex':
                return false;
            case 'tplhead':
                return true;
            case 'word':
                if ($prev['prop']) {
                    return false;
                }
                if (in_array($prev['s'], self::JS_REGEX_AFTER, true)) {
                    return true;
                }
                if (in_array($prev['s'], self::JS_AMBIGUOUS, true)) {
                    self::bail();
                }

                return false;
        }

        return match ($prev['s']) {
            ')' => $prev['ctl'],
            ']', '++', '--' => false,
            '}' => self::bail(), // end of a block (regex) or of an expression (division)
            default => true,
        };
    }

    /**
     * @param  list<array{t: string, s: string, gap: bool, nl: bool, ctl: bool, prop: bool}>  $tokens
     */
    private static function jsJoin(array $tokens): string
    {
        $out = '';
        $prev = null;

        foreach ($tokens as $tok) {
            if ($prev !== null && $tok['gap']) {
                $removable = ($prev['t'] === 'punct' && in_array($prev['s'], self::JS_CONTINUES, true))
                    || $prev['t'] === 'tplhead'
                    || ($tok['t'] === 'punct' && in_array($tok['s'], self::JS_NEXT_SAFE, true))
                    || (($tok['t'] === 'tpl' || $tok['t'] === 'tplhead') && $tok['s'][0] === '}');

                $out .= $tok['nl'] && ! $removable ? "\n" : self::jsSeparator($prev, $tok);
            }
            $out .= $tok['s'];
            $prev = $tok;
        }

        return $out;
    }

    /**
     * '' when the two tokens can touch without merging into something else,
     * otherwise ' '.
     *
     * @param  array{t: string, s: string}  $a
     * @param  array{t: string, s: string}  $b
     */
    private static function jsSeparator(array $a, array $b): string
    {
        $x = $a['s'][-1];
        $y = $b['s'][0];
        $word = static fn (string $ch): bool => ctype_alnum($ch) || $ch === '_' || $ch === '$' || $ch === '\\' || $ch === '#';

        if (($word($x) || $a['t'] === 'regex') && $word($y)) {
            return ' '; // `var a`, and regex flags must not swallow a word
        }
        if ($a['t'] === 'num' && $y === '.') {
            return ' '; // `1 .toString()`
        }
        if ((($x === '+' || $x === '-') && $x === $y)
            || ($x === '/' && ($y === '/' || $y === '*'))
            || ($x === '<' && ($y === '!' || $y === '/'))
            || ($x === '-' && $y === '>')
            || ($x === '?' && $y === '.')) {
            return ' '; // `a + +b`, `a / /re/`, `<!--`, `</script`, `-->`, `? .5`
        }
        if ($a['t'] === 'punct' && $b['t'] === 'punct' && self::jsPunctAt($a['s'] . $b['s'], 0) !== $a['s']) {
            return ' ';
        }

        return '';
    }

    private static function hasLineSeparator(string $s): bool
    {
        return str_contains($s, "\xE2\x80\xA8") || str_contains($s, "\xE2\x80\xA9");
    }
}
