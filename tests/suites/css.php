<?php

declare(strict_types=1);

/** @var TrinsyCa\SingleLiner\Tests\Harness $h */

use TrinsyCa\SingleLiner\SingleLiner;

$c = static fn (string $css): string => SingleLiner::minifyCss($css);

$golden = [
    ['comments and indentation', "/* header */\n.box {\n    width: 100%;\n    /* inline */\n    height: 50%;\n}\n", '.box{width: 100%;height: 50%;}'],
    ['license comments stay', "/*! MIT */\na { b: c }", '/*! MIT */ a{b: c}'],
    ['descendant combinator keeps its space', "a   :hover { x: y }", 'a :hover{x: y}'],
    ['strings untouched', "a::after { content: \"a   b\\\"  c\"; }", "a::after{content: \"a   b\\\"  c\";}"],
    ['url untouched', "a { background: url( \"a  b.png\" ); }", 'a{background: url( "a  b.png" );}'],
    ['bare url untouched', "a { background: url( a.png ) }", 'a{background: url( a.png )}'],
    ['calc keeps spaces around operators', "a { width: calc( 100%  -  2px ) }", 'a{width: calc( 100% - 2px )}'],
    ['comment separating tokens', "a { margin: 1px/**/2px }", 'a{margin: 1px/**/2px}'],
    ['custom property verbatim', "a { --x:  { a  b }  ; --y:1px }", 'a{--x:  { a  b }  ;--y:1px }'],
    ['selector list', "a ,\n b ,\n c { x: y }", 'a,b,c{x: y}'],
    ['at-rule', "@media (max-width: 900px) {\n  a { x: y }\n}", '@media (max-width: 900px){a{x: y}}'],
    ['non-ascii identifiers', ".ü  { content: 'İş' }", ".ü{content: 'İş'}"],
    ['unterminated comment returns input', "a { x: y } /* open", "a { x: y } /* open"],
    ['unterminated string returns input', "a { content: \"open\n }", "a { content: \"open\n }"],
    ['empty', '', ''],
];
foreach ($golden as [$name, $in, $out]) {
    $h->same($name, $out, $c($in));
    $h->same("idempotent: $name", $c($in), $c($c($in)));
}
