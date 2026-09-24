/* Strings, template literals and regular expressions are copied verbatim,
   whatever whitespace and comment-like text they hold. */
var out = [];

var name = 'Ali   Veli';
var t = `Merhaba    ${ name  +  '!' }
    ikinci satır ${ `iç ${ 1  +  2 } içerik` }   // not a comment
  ${ { a: 1 }.a }`;
out.push(t);

var tagged = String.raw`a\n   b ${ 1 }`;
out.push(tagged);

var r1 = /a  b\/c[/ ]+/g;
var r2 = /[/*]  x/;
var r3 = /\s+/gi;
out.push(r1.source, r1.flags, r2.source, r3.test('a  b'));

var s = "line \
continuation";
out.push(s);

var e = 'it\'s "quoted"   ok';
out.push(e);

var div = 10 / 2 / 1;
var paren = (8) / 2;
var member = [4][0] / 2;
out.push(div, paren, member);

var html = '<div class="x">   </div>';
out.push(html);

var json = {"a": "b  c", "d": [1, 2, 3], "e": {"f": null}};
out.push(JSON.stringify(json));

var i = 0;
var ok = i++ < 1 && i-- > 0;
out.push(ok, i);

console.log(JSON.stringify(out));
