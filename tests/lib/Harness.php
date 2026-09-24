<?php

declare(strict_types=1);

namespace TrinsyCa\SingleLiner\Tests;

/**
 * The smallest assertion harness that still gives useful failure output.
 */
final class Harness
{
    private int $passed = 0;

    private int $skipped = 0;

    /** @var list<string> */
    private array $failures = [];

    private string $suite = '';

    public function suite(string $name): void
    {
        $this->suite = $name;
        echo "\n== $name\n";
    }

    public function ok(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;

            return;
        }
        $this->failures[] = "[{$this->suite}] $name" . ($detail !== '' ? "\n      $detail" : '');
        echo "  FAIL $name\n" . ($detail !== '' ? "       $detail\n" : '');
    }

    public function same(string $name, mixed $expected, mixed $actual): void
    {
        $this->ok($name, $expected === $actual, $expected === $actual ? '' : 'expected ' . self::show($expected) . "\n       actual   " . self::show($actual));
    }

    public function skip(string $name, string $why): void
    {
        $this->skipped++;
        echo "  skip $name: $why\n";
    }

    public function note(string $text): void
    {
        echo "  $text\n";
    }

    /**
     * Runs a block and records an uncaught exception as a failure.
     */
    public function run(string $name, callable $block): void
    {
        try {
            $block();
        } catch (\Throwable $e) {
            $this->ok($name, false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    public function finish(): int
    {
        echo "\n" . $this->passed . ' passed, ' . count($this->failures) . ' failed, ' . $this->skipped . " skipped\n";
        foreach ($this->failures as $f) {
            echo "  - $f\n";
        }

        return $this->failures === [] ? 0 : 1;
    }

    public static function show(mixed $v): string
    {
        if (is_string($v)) {
            $s = strlen($v) > 400 ? substr($v, 0, 400) . '…(' . strlen($v) . ' bytes)' : $v;

            return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: var_export($s, true);
        }

        return var_export($v, true);
    }

    /**
     * Runs a command, returns [exit code, stdout+stderr].
     *
     * @param  list<string>  $argv
     * @return array{0: int, 1: string}
     */
    public static function exec(array $argv, ?string $stdin = null): array
    {
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return [127, 'proc_open failed'];
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }

    public static function hasNode(): bool
    {
        static $has = null;
        if ($has === null) {
            [$code] = self::exec(['node', '--version']);
            $has = $code === 0;
        }

        return $has;
    }
}
