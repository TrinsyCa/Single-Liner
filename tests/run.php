<?php

/*
 * Zero-dependency test runner for Single-Liner.
 *
 *   php tests/run.php            all suites
 *   php tests/run.php html js    only the named suites
 *
 * Suites that need an optional tool skip themselves when it is missing:
 * the DOM-equivalence checks need PHP 8.4's Dom\HTMLDocument, the JavaScript
 * checks need `node` on PATH.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/SingleLiner.php';
require __DIR__ . '/../php/minificate.php';
require __DIR__ . '/lib/Harness.php';
require __DIR__ . '/lib/DomCompare.php';
require __DIR__ . '/lib/Fuzz.php';

use TrinsyCa\SingleLiner\Tests\Harness;

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$suites = [
    'html' => __DIR__ . '/suites/html.php',
    'dom' => __DIR__ . '/suites/dom.php',
    'robustness' => __DIR__ . '/suites/robustness.php',
    'css' => __DIR__ . '/suites/css.php',
    'js' => __DIR__ . '/suites/js.php',
    'legacy' => __DIR__ . '/suites/legacy.php',
    'performance' => __DIR__ . '/suites/performance.php',
];

$only = array_slice($argv, 1);
foreach ($only as $name) {
    if (! isset($suites[$name])) {
        fwrite(STDERR, "Unknown suite: $name (known: " . implode(', ', array_keys($suites)) . ")\n");
        exit(2);
    }
}

$h = new Harness();
foreach ($suites as $name => $file) {
    if ($only !== [] && ! in_array($name, $only, true)) {
        continue;
    }
    $h->suite($name);
    (static function (Harness $h) use ($file): void {
        require $file;
    })($h);
}

exit($h->finish());
