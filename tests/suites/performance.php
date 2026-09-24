<?php

declare(strict_types=1);

/** @var TrinsyCa\SingleLiner\Tests\Harness $h */

use TrinsyCa\SingleLiner\SingleLiner;
use TrinsyCa\SingleLiner\Tests\DomCompare;

// A ~3 MB page shaped like a real admin list: a head with inline config,
// then thousands of indented table rows full of attributes, icons and spans.
$row = static fn (int $i): string => <<<HTML
                <tr class="job-row" data-href="/dash/jobs/$i" data-id="$i">
                    <td data-filter="2026-09-25 09:00">
                        <span class="date">25/09/2026 - Cuma</span>
                        <span class="time">09:00</span>
                    </td>
                    <td>
                        <span class="driver-name" data-hc="drivers" data-hc-key="d-$i">Ali Veli $i</span>
                        <span class="driver-wp">+90 555 000 00 00</span>
                    </td>
                    <td class="money" data-filter="1234.56" data-money-round="1.235₺">
                        1.234,56₺
                    </td>
                    <td>
                        <i class="fa-solid fa-plane"></i>
                        <span title="İstanbul  →  Antalya">IST → AYT</span>
                    </td>
                    <td data-copy="Satır 1
Satır 2">
                        <button type="button"
                                class="btn"
                                onclick="copyBox(this)">Kopyala</button>
                    </td>
                </tr>

HTML;

$head = "<!DOCTYPE html>\n<html lang=\"tr\">\n    <head>\n        <meta charset=\"UTF-8\">\n        <title>İş Listesi</title>\n"
    . "        <script>\n            window.__cfg = " . json_encode(array_fill(0, 500, 'değer  ✓')) . ";\n            // queue\n            window.q = [];\n        </script>\n"
    . "        <style>\n            .a { color: red; }\n        </style>\n    </head>\n    <body>\n        <table>\n            <tbody>\n";
$html = $head;
for ($i = 0; strlen($html) < 3_000_000; $i++) {
    $html .= $row($i);
}
$html .= "            </tbody>\n        </table>\n    </body>\n</html>\n";

$times = [];
$out = '';
for ($r = 0; $r < 7; $r++) {
    $t = hrtime(true);
    $out = SingleLiner::minifyHtml($html);
    $times[] = (hrtime(true) - $t) / 1e6;
}
sort($times);
$best = $times[0];
$median = $times[3];

$h->note(sprintf(
    'synthetic page: %s bytes -> %s bytes (%.1f%% smaller), best %.1f ms, median %.1f ms, %.0f MB/s',
    number_format(strlen($html)), number_format(strlen($out)), 100 * (1 - strlen($out) / strlen($html)), $best, $median, strlen($html) / 1e6 / ($best / 1000),
));
$h->ok('a 3 MB page stays well under the budget (best < 60 ms, generous for CI)', $best < 60, "best $best ms");
$h->same('no fallback', null, SingleLiner::lastError());

if (DomCompare::available()) {
    $diffs = DomCompare::diff($html, $out);
    $h->ok('the synthetic page keeps its DOM', $diffs === [], implode("\n       ", $diffs));
}
