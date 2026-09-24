<?php

declare(strict_types=1);

/** @var TrinsyCa\SingleLiner\Tests\Harness $h */

use TrinsyCa\SingleLiner\SingleLiner;
use TrinsyCa\SingleLiner\Tests\DomCompare;
use TrinsyCa\SingleLiner\Tests\Fuzz;

if (! DomCompare::available()) {
    $h->skip('dom', 'needs PHP 8.4+ (Dom\\HTMLDocument)');

    return;
}

// Every fixture parses to the same DOM before and after minification.
foreach (glob(__DIR__ . '/../fixtures/*.html') ?: [] as $file) {
    $html = (string) file_get_contents($file);
    $min = SingleLiner::minifyHtml($html);
    $diffs = DomCompare::diff($html, $min);
    $h->ok('fixture ' . basename($file) . ' keeps its DOM', $diffs === [], implode("\n       ", $diffs));
    $h->ok('fixture ' . basename($file) . ' shrinks', strlen($min) < strlen($html));
}

// Random sloppy markup: same DOM, idempotent, and no whitespace run left
// in ordinary text.
$seeds = (int) (getenv('SINGLE_LINER_FUZZ') ?: 3000);
$failed = 0;
for ($seed = 1; $seed <= $seeds && $failed < 5; $seed++) {
    $html = (new Fuzz($seed))->document();
    $min = SingleLiner::minifyHtml($html);
    $diffs = DomCompare::diff($html, $min);
    if ($diffs !== []) {
        $failed++;
        $h->ok("fuzz seed $seed keeps its DOM", false, implode("\n       ", $diffs) . "\n       input " . TrinsyCa\SingleLiner\Tests\Harness::show($html));

        continue;
    }
    $again = SingleLiner::minifyHtml($min);
    if ($again !== $min) {
        $failed++;
        $h->same("fuzz seed $seed is idempotent", $min, $again);
    }
}
$h->ok("fuzz: $seeds random documents", $failed === 0);
