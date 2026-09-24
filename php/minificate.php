<?php

/*
 * Single-Liner v1 compatibility layer.
 *
 * The three global functions of the first release are kept so existing
 * `require_once "php/minificate.php"; ob_start('minify_html');` setups keep
 * working, but they are now thin wrappers over TrinsyCa\SingleLiner\SingleLiner
 * and inherit its guarantees: inline <script>/<style>, <pre>, <textarea> and
 * attribute values are never rewritten, whitespace between inline elements is
 * never deleted, and a failure returns the input instead of null (an output
 * buffer callback that returns null blanks the whole page).
 */

if (! class_exists(\TrinsyCa\SingleLiner\SingleLiner::class)) {
    require_once __DIR__ . '/../src/SingleLiner.php';
}

if (! function_exists('minify_html')) {
    /**
     * Usable directly as an output buffer callback: ob_start('minify_html').
     */
    function minify_html($buffer): string
    {
        if (! is_string($buffer) || $buffer === '') {
            return (string) $buffer;
        }

        return \TrinsyCa\SingleLiner\SingleLiner::minifyHtml($buffer);
    }
}

if (! function_exists('minify_js')) {
    function minify_js($js): string
    {
        return \TrinsyCa\SingleLiner\SingleLiner::minifyJs((string) $js);
    }
}

if (! function_exists('minify_css')) {
    function minify_css($css): string
    {
        return \TrinsyCa\SingleLiner\SingleLiner::minifyCss((string) $css);
    }
}
