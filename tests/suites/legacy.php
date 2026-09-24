<?php

declare(strict_types=1);

/** @var TrinsyCa\SingleLiner\Tests\Harness $h */

use TrinsyCa\SingleLiner\Tests\Harness;

// The v1 functions still exist and now carry the v2 guarantees.
$h->same('minify_html keeps inline siblings apart', '<b>Ali</b> <i>Veli</i>', minify_html("<b>Ali</b>\n<i>Veli</i>"));
$h->same('minify_html keeps script attributes', '<script type="application/json" id="d">{"a":  1}</script>', minify_html('<script type="application/json" id="d">{"a":  1}</script>'));
$h->same('minify_html on an empty buffer', '', minify_html(''));
$h->same('minify_js', 'var a=1;var b=2', minify_js("var a = 1; // one\nvar b = 2"));
$h->same('minify_css', 'a{b: c}', minify_css("a {\n  b: c\n}"));

// An output buffer callback that returns null blanks the page; this one
// never does, even when PCRE gives up.
$src = realpath(__DIR__ . '/../../php/minificate.php');
$script = <<<'PHP'
    require $argv[1];
    ob_start('minify_html');
    echo str_repeat("<div class=\"a\"\n  id=\"b\">\n  <p>  text  </p>\n</div>\n", 500);
    ob_end_flush();
    PHP;
[$code, $out] = Harness::exec([PHP_BINARY, '-n', '-d', 'pcre.jit=0', '-d', 'pcre.backtrack_limit=1', '-r', $script, $src]);
$h->ok('ob callback returns the page when PCRE fails', $code === 0 && substr_count($out, '<p>  text  </p>') === 500, substr($out, 0, 200));

// The demo page still renders through ob_start('minify_html').
$demo = realpath(__DIR__ . '/../../index.php');
[$code, $out] = Harness::exec([PHP_BINARY, '-n', $demo]);
$h->ok('demo renders', $code === 0 && str_contains($out, '<h1>Hello world! 😊</h1>'), substr($out, 0, 300));
$withoutBlocks = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~s', '', $out);
$h->same('demo is one line outside script and style', 0, substr_count($withoutBlocks, "\n"));
$h->ok('demo keeps its style block byte for byte', str_contains($out, "<style>\n        *{\n            margin: 0;"), substr($out, 0, 300));
$h->ok('demo keeps its script block byte for byte', str_contains($out, "<script>\n    function LeavedPage() {"), $out);
