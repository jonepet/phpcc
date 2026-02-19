#!/usr/bin/env php
<?php

declare(strict_types=1);

// Find autoloader
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

$autoloaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require $path;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    fwrite(STDERR, "Error: Could not find autoloader. Run 'composer install' or build the Docker image.\n");
    exit(1);
}

use Cppc\Compiler;
use Cppc\CompileError;
use Cppc\ContainerFactory;

function colorGreen(string $text): string
{
    return "\033[32m{$text}\033[0m";
}

function colorRed(string $text): string
{
    return "\033[31m{$text}\033[0m";
}

function colorYellow(string $text): string
{
    return "\033[33m{$text}\033[0m";
}

function colorCyan(string $text): string
{
    return "\033[36m{$text}\033[0m";
}

/**
 * Parse metadata comments from the top of a test file.
 *
 * Recognized directives (must appear in leading // comment lines):
 *   // expect: N          — expected exit code (required)
 *   // toolchain: true    — requires system as+gcc (compileToObject + link)
 *   // libs: m,pthread    — comma-separated -l libraries (implies toolchain)
 *
 * @return array{expect: int|null, toolchain: bool, libs: string[]}
 */
function extractMeta(string $filePath): array
{
    $meta = ['expect' => null, 'toolchain' => false, 'libs' => []];

    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return $meta;
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if (!str_starts_with($line, '//')) {
            break;
        }
        $rest = ltrim(substr($line, 2));

        if (str_starts_with($rest, 'expect:')) {
            $val = trim(substr($rest, 7));
            if ($val !== '' && is_numeric($val)) {
                $meta['expect'] = (int) $val;
            }
        } elseif (str_starts_with($rest, 'toolchain:')) {
            $meta['toolchain'] = trim(substr($rest, 10)) === 'true';
        } elseif (str_starts_with($rest, 'libs:')) {
            $meta['libs'] = array_map('trim', explode(',', trim(substr($rest, 5))));
            $meta['toolchain'] = true;
        }
    }

    fclose($handle);
    return $meta;
}

/**
 * @param string[] $libs  Libraries to link (-l flags), empty for standalone mode.
 * @return array{ok: bool, exitCode: int|null, stdout: string, error: string}
 */
function runTest(string $cppFile, Compiler $compiler, bool $toolchain = false, array $libs = []): array
{
    $source    = file_get_contents($cppFile);
    $sourceDir = dirname(realpath($cppFile));
    $binFile   = sys_get_temp_dir() . '/cppc_test_' . uniqid('', true) . '.out';
    $tempFiles = [];

    try {
        if ($toolchain) {
            // System-toolchain mode: as → .o → gcc → binary
            $objPath = $compiler->compileToObject($source, $cppFile, $sourceDir);
            $tempFiles[] = $objPath;

            $objects = [$objPath];

            // Include runtime compat object if available.
            $runtimeCandidates = [
                __DIR__ . '/../runtime/runtime_compat.o',
                '/app/runtime/runtime_compat.o',
            ];
            foreach ($runtimeCandidates as $c) {
                if (file_exists($c)) {
                    $objects[] = $c;
                    break;
                }
            }

            $compiler->link($objects, $binFile, $libs);
        } else {
            // Standalone mode: compile directly to ELF binary.
            $binary = $compiler->compileToElf($source, $cppFile, $sourceDir);
            if (file_put_contents($binFile, $binary) === false) {
                return ['ok' => false, 'exitCode' => null, 'stdout' => '', 'error' => "Failed to write binary: {$binFile}"];
            }
            chmod($binFile, 0755);
        }
    } catch (CompileError $e) {
        foreach ($tempFiles as $f) { @unlink($f); }
        return ['ok' => false, 'exitCode' => null, 'stdout' => '', 'error' => "Compile error: {$e->getMessage()}"];
    } catch (\Throwable $e) {
        foreach ($tempFiles as $f) { @unlink($f); }
        return ['ok' => false, 'exitCode' => null, 'stdout' => '', 'error' => "Internal compiler error: {$e->getMessage()}\n{$e->getTraceAsString()}"];
    }

    // Run and capture exit code.
    $runLines = [];
    exec(escapeshellarg($binFile) . ' 2>&1; echo "EXIT:$?"', $runLines);

    $exitCode = null;
    $stdout = '';
    foreach ($runLines as $line) {
        if (str_starts_with($line, 'EXIT:')) {
            $exitCode = (int) substr($line, 5);
        } else {
            $stdout .= $line . "\n";
        }
    }

    // Clean up.
    @unlink($binFile);
    foreach ($tempFiles as $f) { @unlink($f); }

    return ['ok' => true, 'exitCode' => $exitCode, 'stdout' => $stdout, 'error' => ''];
}

// Parse --category filter from CLI args.
$categoryFilter = null;
$cliArgs = array_slice($argv, 1);
for ($i = 0; $i < count($cliArgs); $i++) {
    if ($cliArgs[$i] === '--category' && isset($cliArgs[$i + 1])) {
        $categoryFilter = $cliArgs[++$i];
    }
}

$programsDir = __DIR__ . '/programs';
$cppFiles    = array_merge(
    glob($programsDir . '/*.cpp') ?: [],
    glob($programsDir . '/*.c')   ?: [],
);

if (empty($cppFiles)) {
    echo colorYellow("No .cpp/.c test files found in {$programsDir}\n");
    exit(0);
}

sort($cppFiles);

$container = ContainerFactory::create();
$compiler = $container->get(Compiler::class);

$passed  = 0;
$failed  = 0;
$skipped = 0;
$total   = count($cppFiles);
$results = [];

$label = $categoryFilter !== null ? "{$categoryFilter} " : '';
echo colorCyan("Running {$label}test(s)...\n\n");

foreach ($cppFiles as $cppFile) {
    $name = basename($cppFile);
    $meta = extractMeta($cppFile);

    if ($meta['expect'] === null) {
        echo colorYellow("  SKIP  {$name}") . " (no // expect: N comment)\n";
        $skipped++;
        continue;
    }

    // Category filtering: --category toolchain → only toolchain tests,
    // --category all → everything, no flag → only standalone tests.
    $isToolchain = $meta['toolchain'];
    if ($categoryFilter === 'toolchain' && !$isToolchain) {
        continue;
    }
    if ($categoryFilter !== 'toolchain' && $categoryFilter !== 'all' && $isToolchain) {
        continue;
    }

    $result = runTest($cppFile, $compiler, $isToolchain, $meta['libs']);

    if (!$result['ok']) {
        echo colorRed("  FAIL  {$name}") . "\n";
        echo "         " . colorRed("Error: ") . $result['error'] . "\n";
        $failed++;
        $results[] = ['name' => $name, 'pass' => false, 'detail' => $result['error']];
        continue;
    }

    $actual = $result['exitCode'];

    if ($actual === $meta['expect']) {
        echo colorGreen("  PASS  {$name}") . " (exit {$actual})\n";
        $passed++;
        $results[] = ['name' => $name, 'pass' => true, 'detail' => ''];
    } else {
        echo colorRed("  FAIL  {$name}")
            . " — expected exit {$meta['expect']}, got exit " . ($actual ?? 'null') . "\n";
        $failed++;
        $results[] = [
            'name'   => $name,
            'pass'   => false,
            'detail' => "expected exit {$meta['expect']}, got " . ($actual ?? 'null'),
        ];
    }
}

$ran = $passed + $failed;

echo "\n";
echo str_repeat('-', 50) . "\n";

$color = $failed === 0 ? 'colorGreen' : 'colorRed';
echo $color("{$passed} passed, {$failed} failed out of {$ran} run") . "\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($results as $r) {
        if (!$r['pass']) {
            echo "  - " . colorRed($r['name']) . ": {$r['detail']}\n";
        }
    }
    exit(1);
}

exit(0);
