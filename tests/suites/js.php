<?php

declare(strict_types=1);

/** @var TrinsyCa\SingleLiner\Tests\Harness $h */

use TrinsyCa\SingleLiner\SingleLiner;
use TrinsyCa\SingleLiner\Tests\Harness;

$j = static fn (string $js): string => SingleLiner::minifyJs($js);

// [name, input, expected] — expected null means "must come back unchanged".
$golden = [
    ['line comment does not swallow the next line', "var a = 1; // set a\nvar b = 2;", 'var a=1;var b=2;'],
    ['asi between statements keeps the line break', "var a = 1\nvar b = a", "var a=1\nvar b=a"],
    ['return + line break keeps the line break', "function f() {\n  return\n  42\n}", "function f(){return\n42}"],
    ['postfix/prefix ++ across a line break', "x\n++y", "x\n++y"],
    ['break and continue labels', "a: for (;;) { break\n a }", "a:for(;;){break\na}"],
    ['template literal verbatim, substitutions minified', 'var t = `a    b ${ c  +  1 }  d`', 'var t=`a    b ${c+1}  d`'],
    ['nested template literals', 'var t = `x ${ `y ${ z } ` }  w`', 'var t=`x ${`y ${z} `}  w`'],
    ['object literal inside a substitution', 'var t = `${ { a: 1 }.a }`', 'var t=`${{a:1}.a}`'],
    ['regex literal verbatim', 'var r = /a  b\\/[/]c/g.test(x)', 'var r=/a  b\\/[/]c/g.test(x)'],
    ['division across a line break', "a = b\n/c/g.exec(d)", 'a=b/c/g.exec(d)'],
    ['regex after if (...)', "if (a) /x/.test(s)", 'if(a)/x/.test(s)'],
    ['regex after typeof', 'var k = typeof /x/', 'var k=typeof/x/'],
    ['unary plus and minus keep their space', 'a + +b; a - -b; a+ ++b; a - --b', 'a+ +b;a- -b;a+ ++b;a- --b'],
    ['number followed by a dot', 'var n = 1 .toString()', 'var n=1 .toString()'],
    ['conditional with a leading-dot number', 'x = a ? .5 : b', 'x=a? .5:b'],
    ['optional chaining', 'x = a ?. b', 'x=a?.b'],
    ['strings verbatim', "var s = \"a  // b\"; var t = 'c /* d */  e'", "var s=\"a  // b\";var t='c /* d */  e'"],
    ['directive prologue', "'use strict'\nvar a", "'use strict'\nvar a"],
    ['non-ascii inside strings is fine', 'var s = "İş  yeri"', 'var s="İş  yeri"'],
    ['multi-line block comment counts as a line break', "a /* x\n y */ b", "a\nb"],
    ['closing script is never produced', 'if (a < /script/.source.length) {}', 'if(a< /script/.source.length){}'],
    ['keywords stay apart', 'return typeof x instanceof Y', 'return typeof x instanceof Y'],
    ['class fields keep their line breaks', "class C {\n  a = 1\n  b = 2\n  static c\n  d\n}", "class C{a=1\nb=2\nstatic c\nd}"],
    ['arrow body on the next line', "var f = (p) =>\n  p * 2", 'var f=(p)=>p*2'],

    // Undecidable input comes back untouched.
    ['regex or division after } is undecidable', "if (a) {}\n/re/.test(s)", null],
    ['html-like comment', "<!-- x\nvar a = 1", null],
    ['non-ascii identifier', 'var ş = 1', null],
    ['unterminated string', "var s = 'abc", null],
    ['unterminated template', 'var s = `abc', null],
    ['unbalanced braces', 'function f() {', null],
    ['hashbang', "#!/usr/bin/env node\nvar a = 1", null],
    ['yield before a slash is ambiguous', "function* g() { yield /x/ }", null],
];
foreach ($golden as [$name, $in, $out]) {
    $h->same($name, $out ?? $in, $j($in));
}

if (! Harness::hasNode()) {
    $h->skip('node checks', 'node is not on PATH');

    return;
}

$tmp = sys_get_temp_dir() . '/single-liner-' . getmypid();
@mkdir($tmp);

$run = static function (string $code, string $name, bool $execute = true) use ($tmp): array {
    $file = $tmp . '/' . preg_replace('~\W+~', '-', $name) . '-' . md5($code) . '.js';
    file_put_contents($file, $code);
    [$checkCode, $checkOut] = Harness::exec(['node', '--check', $file]);
    [$runCode, $runOut] = $execute ? Harness::exec(['node', $file]) : [0, ''];
    @unlink($file);

    return [$checkCode, $checkOut, $runCode, $runOut];
};

// Every golden output parses.
foreach ($golden as [$name, $in]) {
    $out = $j($in);
    if ($out === $in) {
        continue;
    }
    [$code, $msg] = $run($out, 'golden', false);
    $h->ok("node --check: $name", $code === 0, $msg);
}

// Behaviour equivalence: the same program prints the same thing before and after.
foreach (glob(__DIR__ . '/../fixtures/js/*.js') ?: [] as $file) {
    $name = basename($file);
    $src = (string) file_get_contents($file);
    $min = $j($src);
    $h->ok("$name was minified", $min !== $src && strlen($min) < strlen($src));
    [$c1, $o1, $r1, $out1] = $run($src, $name . '-orig');
    [$c2, $o2, $r2, $out2] = $run($min, $name . '-min');
    $h->ok("$name original runs", $c1 === 0 && $r1 === 0, $o1 . $out1);
    $h->ok("$name minified passes node --check", $c2 === 0, $o2);
    $h->ok("$name minified runs", $r2 === 0, $out2);
    $h->same("$name prints the same", $out1, $out2);
    $h->same("$name is idempotent", $min, $j($min));
}

@rmdir($tmp);
