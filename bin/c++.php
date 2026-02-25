#!/usr/bin/env php
<?php
/**
 * g++-compatible compiler driver for cppc.
 *
 * Accepts standard g++ command-line flags so it can be used as CXX in a
 * Makefile.  Flags the compiler doesn't support are silently ignored.
 *
 * Two compilation modes:
 *   1. Standalone (default when no -l flags): custom assembler + linker → self-contained ELF
 *   2. System toolchain (when -l flags present): as → .o → gcc → linked binary (can use external libs)
 *
 * Runs inside the Docker container (invoked by the bin/c++ bash wrapper).
 */

declare(strict_types=1);

ini_set('memory_limit', '1G');

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    '/app/vendor/autoload.php',
];
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require $path;
        break;
    }
}

use Cppc\Compiler;
use Cppc\CompileError;
use Cppc\ContainerFactory;

// ── Early-exit informational flags ──────────────────────────────────────────

$args = array_slice($argv, 1);

foreach ($args as $arg) {
    if ($arg === '--version') {
        echo "cppc 0.1.0 (PHP C/C++ Compiler)\nTarget: x86_64-linux-gnu\n";
        exit(0);
    }
    if ($arg === '-dumpversion') {
        echo "0.1.0\n";
        exit(0);
    }
    if ($arg === '-dumpmachine') {
        echo "x86_64-linux-gnu\n";
        exit(0);
    }
}

// ── Argument parsing ─────────────────────────────────────────────────────────

$inputFiles      = [];
$outputFile      = null;
$compileOnly     = false;   // -c
$emitAsm         = false;   // -S
$preprocessOnly  = false;   // -E
$forceToolchain  = false;   // --toolchain
$libraries       = [];      // -l flags
$libPaths        = [];      // -L flags
$defines         = [];      // -D flags: ['name' => 'value']
$includePaths    = [];      // -I flags
$linkerFlags     = [];      // -Wl,... flags
$depFile         = null;    // -MF file
$genDeps         = false;   // -M / -MD / -MMD
$extraLinkFlags  = [];      // -shared, -static, -fPIC passthrough
$extraAsmFlags   = [];      // -fPIC passthrough to assembler
$strictUndeclared = false;  // -Werror=implicit-function-declaration

// Auto-add host library paths (mounted to /host-libs/ to avoid conflicts with container libs).
foreach (['/host-libs/x86_64-linux-gnu'] as $_sysLib) {
    if (is_dir($_sysLib)) {
        $libPaths[] = $_sysLib;
    }
}

$argc = count($args);

for ($i = 0; $i < $argc; $i++) {
    $arg = $args[$i];

    // ── Flags we handle ──────────────────────────────────────────────────
    if ($arg === '-o') {
        if (!isset($args[$i + 1])) {
            fwrite(STDERR, "c++: error: missing filename after '-o'\n");
            exit(1);
        }
        $outputFile = $args[++$i];
        continue;
    }
    if ($arg === '-c') { $compileOnly = true; continue; }
    if ($arg === '-S') { $emitAsm     = true; continue; }
    if ($arg === '-E') { $preprocessOnly = true; continue; }
    if ($arg === '--toolchain') { $forceToolchain = true; continue; }

    // ── -D flags ────────────────────────────────────────────────────────
    if ($arg === '-D' && isset($args[$i + 1])) {
        $def = $args[++$i];
        $eqPos = strpos($def, '=');
        if ($eqPos !== false) {
            $defines[substr($def, 0, $eqPos)] = substr($def, $eqPos + 1);
        } else {
            $defines[$def] = '1';
        }
        continue;
    }
    if (str_starts_with($arg, '-D')) {
        $def = substr($arg, 2);
        $eqPos = strpos($def, '=');
        if ($eqPos !== false) {
            $defines[substr($def, 0, $eqPos)] = substr($def, $eqPos + 1);
        } else {
            $defines[$def] = '1';
        }
        continue;
    }

    // ── -I flags ────────────────────────────────────────────────────────
    if ($arg === '-I' && isset($args[$i + 1])) {
        $includePaths[] = $args[++$i];
        continue;
    }
    if (str_starts_with($arg, '-I')) {
        $includePaths[] = substr($arg, 2);
        continue;
    }

    // ── -l and -L flags ──────────────────────────────────────────────────
    if ($arg === '-L' && isset($args[$i + 1])) {
        $libPaths[] = $args[++$i];
        continue;
    }
    if (str_starts_with($arg, '-L')) {
        $libPaths[] = substr($arg, 2);
        continue;
    }
    if ($arg === '-l' && isset($args[$i + 1])) {
        $libraries[] = $args[++$i];
        continue;
    }
    if (str_starts_with($arg, '-l')) {
        $libraries[] = substr($arg, 2);
        continue;
    }

    // ── -Werror=implicit-function-declaration ─────────────────────────────
    if ($arg === '-Werror=implicit-function-declaration') {
        $strictUndeclared = true;
        continue;
    }

    // ── -Wl,... linker flag passthrough ─────────────────────────────────
    // Pass the entire -Wl,... flag through to gcc unchanged.
    if (str_starts_with($arg, '-Wl,')) {
        $linkerFlags[] = $arg;
        continue;
    }

    // ── Linker passthrough flags ────────────────────────────────────────
    if ($arg === '-shared' || $arg === '-static') {
        $extraLinkFlags[] = $arg;
        continue;
    }
    if ($arg === '-fPIC' || $arg === '-fpic' || $arg === '-fPIE' || $arg === '-fpie') {
        $extraLinkFlags[] = $arg;
        $extraAsmFlags[] = $arg;
        continue;
    }

    // ── Dependency generation flags ─────────────────────────────────────
    if ($arg === '-M' || $arg === '-MD' || $arg === '-MMD') {
        $genDeps = true;
        continue;
    }
    if ($arg === '-MF' && isset($args[$i + 1])) {
        $depFile = $args[++$i];
        $genDeps = true;
        continue;
    }
    if (str_starts_with($arg, '-MF')) {
        $depFile = substr($arg, 3);
        $genDeps = true;
        continue;
    }

    // ── Flags that take a separate next-arg value ────────────────────────
    if (in_array($arg, ['-MT', '-MQ', '-include',
                         '-isystem', '-x', '-target', '-arch'], true)) {
        $i++; // consume value
        continue;
    }

    // ── Flags (with optional attached value) we silently ignore ──────────
    if (matchIgnoredFlag($arg)) {
        continue;
    }
    if ($arg === '-pthread') {
        $extraLinkFlags[] = '-pthread';
        continue;
    }
    if (in_array($arg, ['-pie', '-no-pie', '-rdynamic',
                         '-s', '-w', '-v', '-pipe', '-pedantic'], true)) {
        continue;
    }

    // ── Catch-all: skip any remaining dash flag ──────────────────────────
    if (str_starts_with($arg, '-')) {
        continue;
    }

    // ── Input file ───────────────────────────────────────────────────────
    $inputFiles[] = $arg;
}

if (empty($inputFiles)) {
    fwrite(STDERR, "c++: fatal error: no input files\n");
    exit(1);
}

// ── Classify inputs ──────────────────────────────────────────────────────────

$sourceFiles = [];
$objectFiles = [];

foreach ($inputFiles as $f) {
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if ($ext === 'o' || $ext === 'obj') {
        $objectFiles[] = $f;
    } else {
        $sourceFiles[] = $f;
    }
}

// Default to system toolchain mode for drop-in g++/gcc compatibility.
// The custom assembler/linker is only used via bin/cppc for standalone ELF output.
$useSystemToolchain = true;

// ── Configure compiler with -D/-I and linker flags ──────────────────────────

$container = ContainerFactory::create();
$compiler = $container->get(Compiler::class);

foreach ($defines as $name => $value) {
    $compiler->addDefine($name, $value);
}
foreach ($includePaths as $path) {
    $compiler->addIncludePath($path);
}
foreach ($linkerFlags as $f) {
    $compiler->addLinkerFlag($f);
}
if ($strictUndeclared) {
    $compiler->setStrictUndeclared(true);
}
foreach ($extraLinkFlags as $f) {
    $compiler->addLinkerFlag($f);
}
foreach ($extraAsmFlags as $f) {
    $compiler->addAsmFlag($f);
}

// ── Link-only mode: all inputs are object files ──────────────────────────────

if ($sourceFiles === [] && $objectFiles !== []) {
    $outPath = $outputFile ?? 'a.out';
    try {
        $compiler->link($objectFiles, $outPath, $libraries, $libPaths);
    } catch (CompileError $e) {
        fwrite(STDERR, "c++: error: {$e->getMessage()}\n");
        exit(1);
    }
    exit(0);
}

// ── Compile mode ─────────────────────────────────────────────────────────────

$exitCode  = 0;
$tempObjFiles = [];

foreach ($sourceFiles as $file) {
    $result = compileSingle(
        $file,
        $compiler,
        compileOnly:        $compileOnly,
        emitAsm:            $emitAsm,
        preprocessOnly:     $preprocessOnly,
        useSystemToolchain: $useSystemToolchain,
        outputFile:         (count($sourceFiles) === 1) ? $outputFile : null,
        tempObjFiles:       $tempObjFiles,
    );
    if ($result !== 0) {
        $exitCode = $result;
        break;
    }
}

// ── Dependency file stub ─────────────────────────────────────────────────────

if ($genDeps && $exitCode === 0) {
    foreach ($sourceFiles as $file) {
        $target = $outputFile ?? replaceExtension(basename($file), '.o');
        $depContent = "{$target}: {$file}\n";
        if ($depFile !== null) {
            file_put_contents($depFile, $depContent);
        } else {
            echo $depContent;
        }
    }
}

// ── Link step (system toolchain mode, not compile-only) ──────────────────────

if ($exitCode === 0 && $useSystemToolchain && !$compileOnly && !$emitAsm && !$preprocessOnly) {
    $outPath    = $outputFile ?? 'a.out';
    $allObjects = array_merge($tempObjFiles, $objectFiles);

    // Include the runtime compat object if it exists.
    $runtimeCompat = findRuntimeCompat();
    if ($runtimeCompat !== null) {
        $allObjects[] = $runtimeCompat;
    }

    try {
        $compiler->link($allObjects, $outPath, $libraries, $libPaths);
    } catch (CompileError $e) {
        fwrite(STDERR, "c++: error: {$e->getMessage()}\n");
        $exitCode = 1;
    }
}

// ── Clean up temp object files ───────────────────────────────────────────────

foreach ($tempObjFiles as $tmp) {
    @unlink($tmp);
}

exit($exitCode);

// ── Helpers ──────────────────────────────────────────────────────────────────

function findRuntimeCompat(): ?string
{
    $candidates = [
        __DIR__ . '/../runtime/runtime_compat.o',
        '/app/runtime/runtime_compat.o',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) {
            return $c;
        }
    }

    // Auto-assemble from .asm source if .o doesn't exist
    $asmCandidates = [
        __DIR__ . '/../runtime/runtime_compat.asm',
        '/app/runtime/runtime_compat.asm',
    ];
    foreach ($asmCandidates as $asmPath) {
        if (file_exists($asmPath)) {
            $container = \Cppc\ContainerFactory::create();
            $compiler = $container->get(\Cppc\Compiler::class);
            $asmSource = file_get_contents($asmPath);
            $oPath = dirname($asmPath) . '/runtime_compat.o';
            try {
                $compiler->assembleToObject($asmSource, $oPath);
                return $oPath;
            } catch (\Throwable $e) {
                // Fallback: write to temp dir
                $tmpPath = sys_get_temp_dir() . '/cppc_runtime_compat.o';
                $compiler->assembleToObject($asmSource, $tmpPath);
                return $tmpPath;
            }
        }
    }

    return null;
}

function compileSingle(
    string   $file,
    Compiler $compiler,
    bool     $compileOnly,
    bool     $emitAsm,
    bool     $preprocessOnly,
    bool     $useSystemToolchain,
    ?string  $outputFile,
    array    &$tempObjFiles,
): int {
    if (!file_exists($file)) {
        fwrite(STDERR, "c++: error: {$file}: No such file or directory\n");
        return 1;
    }

    try {
        $source    = file_get_contents($file);
        $sourceDir = dirname(realpath($file));

        // -E: preprocess only
        if ($preprocessOnly) {
            $out = $compiler->preprocess($source, $file, $sourceDir);
            if ($outputFile !== null) {
                file_put_contents($outputFile, $out);
            } else {
                echo $out;
            }
            return 0;
        }

        // -S: emit assembly
        if ($emitAsm) {
            $assembly = $compiler->compile($source, $file, $sourceDir);
            $outPath  = $outputFile ?? replaceExtension(basename($file), '.s');
            file_put_contents($outPath, $assembly);
            return 0;
        }

        // -c: compile only (produce .o) — always use system assembler
        // to produce proper ELF relocatable objects that can be linked later.
        if ($compileOnly) {
            $outPath = $outputFile ?? replaceExtension(basename($file), '.o');
            $compiler->compileToObject($source, $file, $sourceDir, $outPath);
            return 0;
        }

        // Full compile + link: produce a .o, collect for linking later.
        $objPath = $compiler->compileToObject($source, $file, $sourceDir);
        $tempObjFiles[] = $objPath;

        return 0;

    } catch (CompileError $e) {
        fwrite(STDERR, "{$file}: error: {$e->getMessage()}\n");
        return 1;
    } catch (\Throwable $e) {
        fwrite(STDERR, "c++: internal compiler error: {$e->getMessage()}\n");
        return 1;
    }
}

/**
 * Replace file extension (no regex).
 */
function replaceExtension(string $filename, string $newExt): string
{
    $dot = strrpos($filename, '.');
    if ($dot === false) {
        return $filename . $newExt;
    }
    return substr($filename, 0, $dot) . $newExt;
}

/**
 * Match flags we silently ignore (no regex).
 * Covers: -W*, -O[0-3gs], -g*, -std=*, -f*, -m*, -M*, -include*,
 *         -isystem*, -target*, -arch*, -rdynamic
 */
function matchIgnoredFlag(string $arg): bool
{
    $prefixes = [
        '-W', '-g', '-std=', '-f', '-m', '-M',
        '-include', '-isystem', '-target', '-arch', '-rdynamic',
    ];
    foreach ($prefixes as $p) {
        if (str_starts_with($arg, $p)) {
            return true;
        }
    }
    // -O with optional level: -O, -O0, -O1, -O2, -O3, -Os, -Og
    if ($arg === '-O') {
        return true;
    }
    if (str_starts_with($arg, '-O') && strlen($arg) === 3) {
        $level = $arg[2];
        if ($level >= '0' && $level <= '3' || $level === 's' || $level === 'g') {
            return true;
        }
    }
    return false;
}
