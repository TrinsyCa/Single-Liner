<?php

declare(strict_types=1);

/** @var TrinsyCa\SingleLiner\Tests\Harness $h */

use TrinsyCa\SingleLiner\SingleLiner;
use TrinsyCa\SingleLiner\Tests\Harness;

$src = realpath(__DIR__ . '/../../src/SingleLiner.php');

// A PCRE failure must hand the input back — never null, never a half result.
// Run in a child process so the crippled PCRE limits cannot leak.
$script = <<<'PHP'
    require $argv[1];
    $html = str_repeat("<div class=\"a\"\n   id=\"b\">\n  <p>  text  </p>\n</div>\n", 2000)
        . '<script>' . str_repeat("var a = 1;\n", 5000) . '</script>';
    $out = TrinsyCa\SingleLiner\SingleLiner::minifyHtml($html);
    echo json_encode([
        'same' => $out === $html,
        'error' => TrinsyCa\SingleLiner\SingleLiner::lastError(),
    ]);
    PHP;

[$code, $out] = Harness::exec([PHP_BINARY, '-n', '-d', 'pcre.jit=0', '-d', 'pcre.backtrack_limit=1', '-d', 'pcre.recursion_limit=1', '-r', $script, $src]);
$result = json_decode(trim(substr($out, (int) strrpos($out, '{'))), true);
$h->ok('forced PCRE failure returns the input unchanged', $code === 0 && is_array($result) && $result['same'] === true, $out);
$h->ok('and reports why', is_array($result) && is_string($result['error']) && $result['error'] !== '', $out);

$h->same('no error after a normal run', null, (static function () {
    SingleLiner::minifyHtml('<p>  a  </p>');

    return SingleLiner::lastError();
})());

// Pathological inputs finish quickly (possessive quantifiers, no nested
// backtracking) and keep their bytes where they must.
$cases = [
    'many unterminated quotes' => '<a b="' . str_repeat('x ', 200000),
    'tag soup' => str_repeat('<a <b <c ', 50000),
    'deep nesting' => str_repeat('<div>  ', 20000) . str_repeat('</div>  ', 20000),
    'long attribute value' => '<p title="' . str_repeat("a \n", 300000) . '">  x</p>',
    'many comments' => str_repeat("<!-- c -->\n", 50000),
    'unterminated comment' => '<p>  x</p><!--' . str_repeat('- ', 200000),
    'huge script' => '<script>' . str_repeat("  var a = '</div>';\n", 100000) . '</script>',
    'lonely less-than' => str_repeat('< ', 300000),
    'lots of keep zones' => str_repeat("<span data-ws-keep>\n a </span>\n", 20000),
];
foreach ($cases as $name => $html) {
    $t = hrtime(true);
    $out = SingleLiner::minifyHtml($html);
    $ms = (hrtime(true) - $t) / 1e6;
    $h->ok("$name finishes fast (" . round($ms, 1) . ' ms)', $ms < 500);
    $h->ok("$name did not fall back", SingleLiner::lastError() === null, (string) SingleLiner::lastError());
}
$h->same('long attribute value kept', '<p title="' . str_repeat("a \n", 300000) . '"> x</p>', SingleLiner::minifyHtml($cases['long attribute value']));
$h->same('huge script kept', $cases['huge script'], SingleLiner::minifyHtml($cases['huge script']));
$h->same('keep zones kept', str_repeat("<span data-ws-keep>\n a </span> ", 20000), SingleLiner::minifyHtml($cases['lots of keep zones']));
