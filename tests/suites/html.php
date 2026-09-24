<?php

declare(strict_types=1);

/** @var TrinsyCa\SingleLiner\Tests\Harness $h */

use TrinsyCa\SingleLiner\SingleLiner;

$m = static fn (string $html, array $options = []): string => SingleLiner::minifyHtml($html, $options);

// [name, input, expected] — expected null means "must come back byte-identical".
$golden = [
    // --- whitespace between and inside elements (v1 deleted it) ---
    ['inline siblings keep one space', '<b>Ali</b> <i>Veli</i>', null],
    ['newline between inline siblings becomes a space', "<span>100</span>\n<span>EUR</span>", '<span>100</span> <span>EUR</span>'],
    ['icon and label keep their gap', "<i class=\"fa-clock\"></i>\n    <span data-live-time>09:00</span>", '<i class="fa-clock"></i> <span data-live-time>09:00</span>'],
    ['indentation collapses to one space', "<div>\n    <p>  a  \n  b </p>\n</div>\n", '<div> <p> a b </p> </div> '],
    ['a run is never removed, only shortened', "<td>\n\n\t\t</td>", '<td> </td>'],
    ['CRLF counts as whitespace', "a\r\n\r\nb", 'a b'],
    ['form feed in text is not white space for CSS, so it stays', "a\f\t b  \f", "a\f b \f"],
    ['form feed inside a tag is a separator', "<a\fhref=\"x\"\f>t</a>", '<a href="x" >t</a>'],
    ['vertical tab is not HTML whitespace', "a\x0B\x0Bb", null],
    ['text with a lone less-than', "a  <  b  and  c  >  d", 'a < b and c > d'],
    ['empty input', '', ''],
    ['whitespace only', " \n\t ", ' '],

    // --- inside tags ---
    ['whitespace inside a tag becomes one space', "<div\n   class=\"a\"\n   id=x\t>", '<div class="a" id=x >'],
    ['whitespace around = collapses but stays', "<a href =  \"x\">", '<a href = "x">'],
    ['self-closing slash', "<br\n/>", '<br />'],
    ['end tag whitespace', "</div\n>", '</div >'],

    // --- attribute values are byte-identical ---
    ['double spaces in a value', '<p title="two  spaces">x</p>', null],
    ['newlines in a value', "<button data-copy=\"line1\nline2\n\n   line3\">Kopyala</button>", null],
    ['quoted > in a value', "<a title=\"x > y   z\">  t  </a>", '<a title="x > y   z"> t </a>'],
    ['single quotes', "<a title='a  \"b\"\n c'>  t</a>", "<a title='a  \"b\"\n c'> t</a>"],
    ['quote inside an unquoted value does not open a value', "<a b=x'y c=\"p  q\">  t</a>", "<a b=x'y c=\"p  q\"> t</a>"],
    ['quote inside an attribute name', "<a \"q c=\"p  q\"\n>  t</a>", "<a \"q c=\"p  q\" > t</a>"],
    ['svg path data', "<svg><path d=\"M0 0\n   L1 1\"/></svg>", null],

    // --- protected elements ---
    ['pre keeps everything', "<pre>  a\n\n    b</pre>", null],
    ['pre with attributes and upper case', "<PRE class=\"x\">\n  a  </PRE >  x", "<PRE class=\"x\">\n  a  </PRE > x"],
    ['nested pre', "<pre><pre>  a  </pre>  b  </pre>  c  ", "<pre><pre>  a  </pre>  b  </pre> c "],
    ['pre-x is not pre', "<pre-x>  a  </pre-x>", '<pre-x> a </pre-x>'],
    ['preview is not pre', "<preview>  a  </preview>", '<preview> a </preview>'],
    ['listing', "<listing>  a\n b</listing>", null],
    ['textarea value', "<textarea name=\"n\">\n a\n\n b  </textarea>", null],
    ['textarea holding markup', "<textarea>  <p>  x  </p>  </textarea>  y", "<textarea>  <p>  x  </p>  </textarea> y"],
    ['title', "<title>  A   B </title>", null],
    ['script attributes survive', "<script type=\"module\" nonce=\"abc\" id=\"s1\">import x from \"./x.js\";\n  x()</script>", null],
    ['json data block', "<script type=\"application/json\" id=\"d\">{\"a\": \"b  c\",\n \"d\": 1}</script>", null],
    ['line comments in a script', "<script>var a = 1; // set a\nvar b = 2;</script>", null],
    ['asi in a script', "<script>var a = 1\nvar b = a\n</script>", null],
    ['template literal in a script', "<script>var t = `a    b\n  \${c}`;</script>", null],
    ['markup inside a script string', "<script>var s = \"</div>   <p>\";</script>  <p>  x</p>", "<script>var s = \"</div>   <p>\";</script> <p> x</p>"],
    ['upper-case script end tag', "<script>\n  a  </SCRIPT >  b", "<script>\n  a  </SCRIPT > b"],
    ['script end tag needs a boundary', "<script>x = '</scripts>  y'</script>", null],
    ['style', "<style>\n  a  {  color :  red  }\n</style>", null],
    ['noscript', "<noscript>  <p>  x  </p>  </noscript>", null],
    ['iframe body', "<iframe>  x  <b> y </b></iframe>", null],
    ['xmp', "<xmp>  <b>  x </b></xmp>", null],
    ['plaintext keeps the rest', "<p>  a  </p><plaintext>  b\n\n  c", "<p> a </p><plaintext>  b\n\n  c"],
    ['double-escaped script keeps the rest', "<p>  a  </p><script><!-- <script> x </script>  y --></script>  z", "<p> a </p><script><!-- <script> x </script>  y --></script>  z"],

    // --- the keep attribute ---
    ['keep attribute', "<div data-ws-keep>\n a\n\n b <span>  c </span>\n</div>  x", "<div data-ws-keep>\n a\n\n b <span>  c </span>\n</div> x"],
    ['keep attribute with a value and upper case', "<ul class=\"jd-people-list\" DATA-WS-KEEP=\"\">\n<li>Ali\n Veli</li>\n</ul>\n", "<ul class=\"jd-people-list\" DATA-WS-KEEP=\"\">\n<li>Ali\n Veli</li>\n</ul> "],
    ['nested element of the same name inside a keep zone', "<div data-ws-keep><div>  x  </div>  y  </div>  z  ", "<div data-ws-keep><div>  x  </div>  y  </div> z "],
    ['keep zone skips comments and scripts when counting', "<div data-ws-keep><!-- </div> --><script>'</div>'</script>  a  </div>  b", "<div data-ws-keep><!-- </div> --><script>'</div>'</script>  a  </div> b"],
    ['keep zone ignores end tags in attribute values', "<span data-ws-keep title=\"</span>\">  a  </span>  b", "<span data-ws-keep title=\"</span>\">  a  </span> b"],
    ['void element with the keep attribute', "<br data-ws-keep>  a  ", '<br data-ws-keep> a '],
    ['keep attribute needs an exact name', "<div data-ws-keeper>  a  </div>", '<div data-ws-keeper> a </div>'],
    ['keep attribute text inside a value is not the attribute', "<div title=\"data-ws-keep\">  a  </div>", '<div title="data-ws-keep"> a </div>'],
    ['unclosed keep element keeps the rest', "<p>  a  </p><div data-ws-keep>  b  <p>  c", "<p> a </p><div data-ws-keep>  b  <p>  c"],

    // --- comments ---
    ['comments are removed', "<p>a</p>\n<!-- secret TODO -->\n<p>b</p>", '<p>a</p> <p>b</p>'],
    ['multi-line comment', "<p>a</p>\n<!--\n  x\n-->\n<p>b</p>", '<p>a</p> <p>b</p>'],
    ['conditional comment kept', "<!--[if IE]><p>  x  </p><![endif]-->", null],
    ['livewire morph markers kept', "<div>\n<!--[if BLOCK]><![endif]-->\n  <p>x</p>\n<!--[if ENDBLOCK]><![endif]-->\n</div>", '<div> <!--[if BLOCK]><![endif]--> <p>x</p> <!--[if ENDBLOCK]><![endif]--> </div>'],
    ['bang comment kept', "<!--! license -->", null],
    ['comment glued to text is kept', "a<!-- x -->b", null],
    ['comment that would glue an entity is kept', "&<!-- -->amp;", null],
    ['comment that would glue a tag is kept', "<<!-- -->div>", null],
    ['abrupt comments', "<p>a</p> <!--> <!---> <p>b</p>", '<p>a</p> <p>b</p>'],
    ['comment ended by --!>', "<p>a</p> <!-- x --!> <p>b</p>", '<p>a</p> <p>b</p>'],
    ['comment containing markup', "<p>a</p> <!-- <div>  </div> --> <p>b</p>", '<p>a</p> <p>b</p>'],
    ['comments can be kept', "<p>a</p>\n<!--  x  -->\n<p>b</p>", "<p>a</p> <!--  x  --> <p>b</p>", ['remove_comments' => false]],
    ['bogus comment copied', "<?php  x  ?>  a", '<?php  x  ?> a'],
    ['cdata copied', "<svg><![CDATA[ a    b ]]></svg>", null],

    // --- doctype ---
    ['whitespace before the doctype goes', "\n\n  <!DOCTYPE html>\n<html>\n<head></head>\n</html>\n", '<!DOCTYPE html> <html> <head></head> </html> '],
    ['lower-case doctype', "  <!doctype html>", '<!doctype html>'],
    ['bom stays in front of the doctype', "\xEF\xBB\xBF \n<!DOCTYPE html>", "\xEF\xBB\xBF<!DOCTYPE html>"],
    ['leading whitespace without a doctype stays one space', "\n\n<p>x</p>", ' <p>x</p>'],

    // --- bytes, not characters ---
    ['nbsp untouched', "a\xC2\xA0\xC2\xA0b  c", "a\xC2\xA0\xC2\xA0b c"],
    ['narrow nbsp untouched', "1\xE2\x80\xAF000  TL", "1\xE2\x80\xAF000 TL"],
    ['ideographic space untouched', "a\xE3\x80\x80\xE3\x80\x80b", null],
    ['0x85 inside a UTF-8 character untouched', "\xC4\x85  \xC4\x85", "\xC4\x85 \xC4\x85"],
    ['nbsp entity untouched', "a&nbsp;&nbsp; b", null],
    ['invalid UTF-8', "<p>\xFF\xFE  x\xC3</p>", "<p>\xFF\xFE x\xC3</p>"],

    // --- unfinished markup ---
    ['unfinished tag keeps the rest', "<p>a  b</p>  <div class=\"x", "<p>a b</p> <div class=\"x"],
    ['unfinished comment keeps the rest', "<p>a  b</p>  <!-- x  y", "<p>a b</p> <!-- x  y"],
    ['unfinished script keeps the rest', "<p>a  b</p>  <script>  x  ", "<p>a b</p> <script>  x  "],
    ['end tag without a name is bogus', "a  </ x  >  b", 'a </ x  > b'],
];

foreach ($golden as $case) {
    [$name, $input, $expected] = $case;
    $options = $case[3] ?? [];
    $h->same($name, $expected ?? $input, $m($input, $options));
}

$h->run('keep_attribute option', static function () use ($h, $m): void {
    $h->same('custom keep attribute', "<div data-raw>\n  a </div> ", $m("<div data-raw>\n  a </div>\n", ['keep_attribute' => 'data-raw']));
    $h->same('keep attribute switched off', '<div data-ws-keep> a </div>', $m("<div data-ws-keep>\n  a </div>", ['keep_attribute' => null]));
    $threw = false;
    try {
        $m('x', ['keep_attribute' => 'bad attr']);
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    $h->ok('an invalid keep attribute is rejected', $threw);
    $threw = false;
    try {
        $m('x', ['no_such_option' => true]);
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    $h->ok('an unknown option is rejected', $threw);
});

$h->run('inline css and js are opt-in', static function () use ($h, $m): void {
    $page = "<style>\n  a  {  color :  red ;  }\n</style>\n<script>\n  var a = 1; // one\n  var b = 2\n</script>\n"
        . "<script type=\"application/json\">{\"a\":  1}</script><script src=\"x.js\">  </script><script type=\"module\">\n  import x from './x.js'\n  x()\n</script>";
    $plain = $m($page);
    preg_match_all('~<(script|style)\b[^>]*>.*?</\1>~s', $page, $blocks);
    foreach ($blocks[0] as $i => $block) {
        $h->ok("block $i untouched by default", str_contains($plain, $block), $plain);
    }
    $out = $m($page, ['minify_css' => true, 'minify_js' => true]);
    $h->ok('style minified when asked', str_contains($out, '<style>a{color : red;}</style>'), $out);
    $h->ok('classic script minified when asked', str_contains($out, "<script>var a=1;var b=2</script>"), $out);
    $h->ok('json script untouched', str_contains($out, '<script type="application/json">{"a":  1}</script>'), $out);
    $h->ok('script with src untouched', str_contains($out, '<script src="x.js">  </script>'), $out);
    $h->ok('module script minified', str_contains($out, "<script type=\"module\">import x from'./x.js'\nx()</script>"), $out);
});

$h->run('single line', static function () use ($h, $m): void {
    $html = "<!DOCTYPE html>\n<html>\n  <head>\n    <title>T</title>\n  </head>\n  <body class=\"a\"\n        id=\"b\">\n"
        . "    <p>\n      Hello\n      <b>world</b>\n    </p>\n    <!-- note -->\n  </body>\n</html>\n";
    $out = $m($html);
    $h->same('a normal page ends up on one line', 0, substr_count($out, "\n"));
    $h->same('and looks like this', '<!DOCTYPE html> <html> <head> <title>T</title> </head> <body class="a" id="b"> <p> Hello <b>world</b> </p> </body> </html> ', $out);
});

$h->run('idempotent', static function () use ($h, $m, $golden): void {
    foreach ($golden as $case) {
        $once = $m($case[1], $case[3] ?? []);
        $h->same('idempotent: ' . $case[0], $once, $m($once, $case[3] ?? []));
    }
});
