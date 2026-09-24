# Single-Liner

Single-Liner turns server-rendered HTML into one line without changing what
the browser builds from it. Indentation, line breaks and comments go. Scripts,
styles, preformatted text, form values and attribute values come out
byte-for-byte as they went in.

It is one PHP class with no dependencies and runs on PHP 8.1 or later. It
also ships an opt-in CSS minifier and a deliberately conservative JavaScript
minifier.

```html
<!-- before -->
<ul class="menu">
    <li>
        <a href="/jobs">
            <i class="fa-list"></i>
            <span>Jobs</span>
        </a>
    </li>
</ul>

<!-- after -->
<ul class="menu"> <li> <a href="/jobs"> <i class="fa-list"></i> <span>Jobs</span> </a> </li> </ul>
```

## Installation

```bash
composer require trinsyca/single-liner
```

You can also copy `src/SingleLiner.php` into your project. It has no other
files and no dependencies.

## Usage

```php
use TrinsyCa\SingleLiner\SingleLiner;

$html = SingleLiner::minifyHtml($html);

// Options (defaults shown)
$html = SingleLiner::minifyHtml($html, [
    'keep_attribute'  => 'data-ws-keep', // null turns the escape hatch off
    'remove_comments' => true,
    'minify_css'      => false,          // run inline <style> through minifyCss()
    'minify_js'       => false,          // run inline classic/module scripts through minifyJs()
]);

$css = SingleLiner::minifyCss($css);
$js  = SingleLiner::minifyJs($js);
```

`minifyHtml()` always returns a string. If PCRE fails (backtrack limit, JIT
stack), it returns the input unchanged. `SingleLiner::lastError()` then tells
you why.

### The v1 functions

`php/minificate.php` still provides `minify_html()`, `minify_js()` and
`minify_css()`, so this keeps working:

```php
require_once 'php/minificate.php';
ob_start('minify_html');
```

The functions now wrap the class, so they have the same guarantees. The
callback never returns `null`, because a `null` from an output buffer
callback blanks the page.

## What minifyHtml guarantees

- **Whitespace is shortened, never deleted.** Every run of spaces, tabs and
  line breaks in text and between tags becomes exactly one space.
  `<b>Ali</b> <i>Veli</i>` keeps its space. Deleting it would glue the words
  together on screen and in `textContent`.
- **Tags are tidied only between their attributes.** Line breaks and
  indentation inside a tag become one space. Attribute values are never
  touched, including multi-line `data-*` payloads, SVG path data and
  `title="two  spaces"`.
- **Protected elements are copied byte for byte**, start and end tags
  included:
  - `<script>`: all attributes survive, and JSON data blocks, `//` comments,
    ASI and template literals are left alone
  - `<style>`, `<pre>`, `<textarea>`, `<title>`, `<listing>`, `<xmp>`,
    `<iframe>`, `<noembed>`, `<noframes>` and `<noscript>`
  - inline `<svg>` and `<math>`: foreign content follows different tokenizer
    rules, so it is not rewritten
  - any element carrying the keep attribute (see below)
  - `<plaintext>` and everything after it
- **Comments are removed**, with three exceptions:
  - comments starting with `[`, such as IE conditionals and Livewire's
    `<!--[if BLOCK]><![endif]-->` morph markers
  - comments starting with `!`, such as `<!--! license -->`
  - a comment glued to text on its left, as in `&<!-- -->amp;`, because
    removing it would create new markup
- **Whitespace before `<!DOCTYPE>` is dropped.** A leading BOM stays.
- **It works on bytes.** Only ASCII whitespace is touched, and patterns
  never use `/u`. NBSP, U+202F and other Unicode spaces are not touched, and
  invalid UTF-8 passes through.
- **It never gives a half result.** Markup that is still open at the end of
  the input, such as a tag, comment or script, stops minification there, and
  the rest is copied verbatim.
- **Output is stable.** Minifying the output again changes nothing.

A form feed (U+000C) in text is left alone. HTML treats it as whitespace,
but CSS draws it as a control character, so replacing it could change what
is shown. Inside a tag it is only a separator and is collapsed like any other
whitespace.

### The keep attribute

Collapsing whitespace is invisible only under the default
`white-space: normal`. An element whose CSS says `white-space: pre`,
`pre-wrap`, `pre-line` or `break-spaces` shows its line breaks and spaces.
If the server renders its text, mark it:

```html
<p class="description" data-ws-keep>{{ $job->description }}</p>
```

The element's whole content is then copied verbatim. The attribute name is
configurable with `keep_attribute` and matched case-insensitively.

Keep zones follow the same rules as `<pre>`, `<listing>`, `<svg>` and
`<math>`. A zone ends at the end tag that closes it, and only when everything
inside it is properly nested and closed. If it is not, the HTML parser might
ignore that end tag and keep the element open. Examples are a stray end tag,
an unclosed `<div>` inside a `<span>`, or an HTML tag that breaks out of SVG.
In that case the rest of the document is copied verbatim, so more stays
untouched, never less. For the best result, put the attribute on elements
whose content is well-formed. On a void element (`<br data-ws-keep>`) the
attribute does nothing, because there is no content to keep.

## Limitations (read before enabling it site-wide)

- **Server-rendered `white-space: pre*` text needs `data-ws-keep`.** This is
  the one thing the minifier cannot know. Text built by JavaScript at runtime
  is not affected.
- **`textContent` of whitespace-only nodes changes.** A node such as
  `"\n        "` becomes `" "`. `innerText` and rendering do not change.
  Scripts that compare raw `innerHTML` against a fresh server response must
  see minified HTML on both sides. Minify every HTML response the same way,
  or none of them.
- **Raw-text elements are recognized by name,** as the tokenizer does in body
  content. Two legacy parser modes differ:
  - The old "in select" insertion mode ignored `<style>`, `<title>` and
    similar start tags inside `<select>`. Current browsers parse select
    content like body content.
  - Framesets.

  If your markup puts such elements inside `<select>`, test it. `<noscript>`
  is treated as raw text, which is how browsers with scripting enabled read
  it.
- **Inline JavaScript and CSS are left as they are** unless you opt in.
  Minify scripts and styles at build time (Vite, esbuild) instead.

## minifyJs (opt-in, conservative)

`minifyJs()` removes comments and whitespace from JavaScript, using a small
lexer:

- Strings, template literals (with nested `${}`), regular expressions and
  numbers are copied verbatim.
- A line break is dropped only where automatic semicolon insertion provably
  cannot apply: after a token that cannot end a statement, or before a token
  that cannot start one.
- The restricted productions keep their line break: `return`, `break`,
  `continue`, `throw`, `yield` and `async` followed by a line break, and
  postfix `++`/`--`.

It returns the input unchanged whenever it cannot decide:

- `/` after `}` (regex or division?)
- non-ASCII identifiers, HTML-like comments or a hashbang
- unbalanced brackets
- output that does not re-tokenize into exactly the same tokens

It never renames anything.

## minifyCss (opt-in)

`minifyCss()` removes comments and collapses whitespace:

- `/*! … */` comments stay.
- Whitespace next to `{ } ; ,` is dropped. Elsewhere a run becomes one space,
  because `a :hover` is not `a:hover` and `calc()` needs spaces around `+`
  and `-`.
- Strings, `url()` and custom property values are copied verbatim.

It returns the input unchanged if it meets an unterminated string or comment.

## Laravel

Register a response middleware in the `web` group only. Leave out API, JSON,
file downloads and Livewire component updates:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use TrinsyCa\SingleLiner\SingleLiner;

class MinifyHtml
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof Response
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            && ! $response->headers->has('Content-Encoding')
            && ! str_contains((string) $response->headers->get('Content-Disposition'), 'attachment')
            && ! in_array($response->getStatusCode(), [204, 304], true)
            && ! $request->hasHeader('X-Livewire')) {
            $response->setContent(SingleLiner::minifyHtml((string) $response->getContent()));
        }

        return $response;
    }
}
```

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [\App\Http\Middleware\MinifyHtml::class]);
})
```

Do not use `ob_start()` in a framework front controller. It would also run
over images, PDFs and JSON.

## Performance

The class uses possessive patterns only, so there is no catastrophic
backtracking. It handles about 150–200 MB/s. A 3 MB real admin page takes
about 15–20 ms, and a typical 200 KB page takes about 1 ms.
`php tests/run.php performance` prints the numbers for your machine.

## Tests

```bash
php tests/run.php              # everything
php tests/run.php html dom js  # selected suites
SINGLE_LINER_FUZZ=100000 php tests/run.php dom
```

The runner has no dependencies. Two suites need optional tools and skip
themselves when they are missing:

- The DOM suite needs PHP 8.4's `Dom\HTMLDocument`. It parses every fixture
  and thousands of random, deliberately broken documents before and after
  minification, and checks that the trees are equal.
- The JavaScript suite needs `node`. It runs `node --check` on every
  minified script, and runs sample programs before and after minification to
  compare their output.

## Changes in v2

v1 was a set of regular expressions with serious bugs:

- It rebuilt every inline `<script>` without its attributes. JSON blocks were
  executed as JavaScript and module scripts became classic scripts.
- It joined lines, so a `// comment` swallowed the rest of the script and
  ASI broke (`return\n42`).
- It deleted all whitespace between tags, gluing inline elements together.
- It rewrote `<pre>`, `<textarea>` and attribute values.
- It returned `null`, and so a blank page, when PCRE failed.

v2 is a rewrite with the guarantees above. The v1 functions remain as
wrappers.

## License

MIT, see [LICENSE](LICENSE).
