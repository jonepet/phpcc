<?php

declare(strict_types=1);

namespace Cppc;

use Cppc\Lexer\Lexer;
use Cppc\Lexer\Preprocessor;
use Cppc\Lexer\Token;
use Cppc\Parser\Parser;
use Cppc\AST\TranslationUnit;
use Cppc\Semantic\Analyzer;
use Cppc\IR\IRGenerator;
use Cppc\IR\IRModule;
use Cppc\CodeGen\X86Generator;
use Cppc\Assembler\Parser as AsmParser;
use Cppc\Assembler\Encoder;
use Cppc\Assembler\Linker;
use Cppc\Assembler\ElfWriter;
use Cppc\Assembler\ElfReader;
use Cppc\Assembler\DynSymReader;
use Cppc\Assembler\GotPltBuilder;

class Compiler
{
    /** @var array<string, string> User-defined macros from -D flags */
    private array $defines = [];

    /** @var string[] User include paths from -I flags */
    private array $includePaths = [];

    /** @var string[] Extra flags to pass to the linker */
    private array $extraLinkerFlags = [];

    /** When true, undeclared function calls cause a compile error (C99+ strict mode). */
    private bool $strictUndeclared = false;

    /**
     * Add a user-defined macro (from -D flag).
     */
    public function addDefine(string $name, string $value = '1'): void
    {
        $this->defines[$name] = $value;
    }

    /**
     * Add a user include path (from -I flag).
     */
    public function addIncludePath(string $path): void
    {
        $this->includePaths[] = $path;
    }

    /**
     * Add extra linker flags (from -Wl, -shared, -static, etc.).
     */
    public function addLinkerFlag(string $flag): void
    {
        $this->extraLinkerFlags[] = $flag;
    }

    /**
     * Enable strict undeclared function detection (-Werror=implicit-function-declaration).
     */
    public function setStrictUndeclared(bool $strict): void
    {
        $this->strictUndeclared = $strict;
    }

    /**
     * Create and configure a Preprocessor with user defines and include paths.
     */
    private function createPreprocessor(string $file = ''): Preprocessor
    {
        $pp = new Preprocessor();
        $pp->setCppMode(!$this->isCFile($file));
        foreach ($this->defines as $name => $value) {
            $pp->addDefine($name, $value);
        }
        foreach ($this->includePaths as $path) {
            $pp->addIncludePath($path);
        }
        return $pp;
    }

    /**
     * Preprocess source and return the expanded text (for -E mode).
     */
    public function preprocess(string $source, string $file = '', string $sourceDir = ''): string
    {
        return $this->createPreprocessor($file)->process($source, $file, $sourceDir);
    }

    /** @return Token[] */
    public function tokenize(string $source, string $file = '', string $sourceDir = ''): array
    {
        $expanded = $this->createPreprocessor($file)->process($source, $file, $sourceDir);
        return (new Lexer())->tokenize($expanded, $file);
    }

    public function parse(string $source, string $file = '', string $sourceDir = ''): TranslationUnit
    {
        return (new Parser($this->tokenize($source, $file, $sourceDir)))->parse();
    }

    /**
     * Returns true when the given filename has a plain C extension (.c),
     * meaning all functions should default to C linkage (no name mangling).
     */
    private function isCFile(string $file): bool
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return $ext === 'c' || $ext === 'i';
    }

    public function generateIr(string $source, string $file = '', string $sourceDir = ''): string
    {
        $ast = $this->parse($source, $file, $sourceDir);
        $analyzer = new Analyzer($this->isCFile($file));
        $analyzer->analyze($ast);
        $this->checkFatalErrors($analyzer, $file);

        $module = new IRModule();
        return (string) (new IRGenerator($analyzer, $module))->generate($ast);
    }

    public function compile(string $source, string $file = '', string $sourceDir = ''): string
    {
        $ast = $this->parse($source, $file, $sourceDir);
        $analyzer = new Analyzer($this->isCFile($file));
        $analyzer->analyze($ast);
        $this->checkFatalErrors($analyzer, $file);

        $module = new IRModule();
        $irModule = (new IRGenerator($analyzer, $module))->generate($ast);

        return (new X86Generator())->generate($irModule);
    }

    /**
     * Check semantic analysis errors and throw on fatal ones.
     *
     * Undeclared identifier references are fatal for regular source files
     * (C99+ compliance, required by autoconf's undeclared-builtins test).
     * They are tolerated in preprocessed .i files because these may contain
     * comma-separated function declarations our parser doesn't fully handle.
     *
     * Undeclared function *calls* are only fatal in strict mode
     * (-Werror=implicit-function-declaration).
     */
    private function checkFatalErrors(Analyzer $analyzer, string $file): void
    {
        $isPreprocessed = str_ends_with(strtolower($file), '.i');

        foreach ($analyzer->getErrors() as $err) {
            $msg = $err->getMessage();
            if (!$isPreprocessed && str_contains($msg, 'Undeclared identifier')) {
                throw $err;
            }
            if ($this->strictUndeclared && str_contains($msg, 'undeclared function')) {
                throw $err;
            }
        }
    }

    /**
     * Compile source to a static ELF64 binary.
     */
    public function compileToElf(string $source, string $file = '', string $sourceDir = '', string $runtimePath = ''): string
    {
        $assembly = $this->compile($source, $file, $sourceDir);

        if ($runtimePath === '') {
            // Try standard locations
            $candidates = [
                __DIR__ . '/../runtime/runtime.asm',
                '/app/runtime/runtime.asm',
            ];
            foreach ($candidates as $c) {
                if (file_exists($c)) {
                    $runtimePath = $c;
                    break;
                }
            }
        }

        $runtimeAsm = file_get_contents($runtimePath);
        if ($runtimeAsm === false) {
            throw new CompileError("Cannot read runtime: {$runtimePath}");
        }

        $parser = new AsmParser();
        $encoder = new Encoder();
        $linker = new Linker();

        // Parse and encode compiler output
        $parsed = $parser->parse($assembly);
        $encodedSections = [];
        foreach ($parsed['sections'] as $secName => $lines) {
            if ($lines !== []) {
                $encodedSections[] = $encoder->encode($secName, $lines);
            }
        }
        $linker->addModule($encodedSections, $parsed['globals']);

        // Parse and encode runtime
        $parsedRt = $parser->parse($runtimeAsm);
        $encodedRt = [];
        foreach ($parsedRt['sections'] as $secName => $lines) {
            if ($lines !== []) {
                $encodedRt[] = $encoder->encode($secName, $lines);
            }
        }
        $linker->addModule($encodedRt, $parsedRt['globals']);

        // Compute layout and resolve relocations
        $elfWriter = new ElfWriter();
        $layout = ElfWriter::computeLayout($linker);
        $linker->resolve($layout['vaddrs']);

        if ($layout['entry'] === null) {
            throw new CompileError('No _start symbol found');
        }

        return $elfWriter->build($linker->getSections(), $layout['entry'], $layout['vaddrs']);
    }

    /**
     * Compile to .o via system assembler (`as`).
     * Returns the path to the created object file.
     */
    public function compileToObject(string $source, string $file = '', string $sourceDir = '', string $outputPath = ''): string
    {
        $assembly = $this->compile($source, $file, $sourceDir);

        if ($outputPath === '') {
            $outputPath = tempnam(sys_get_temp_dir(), 'cppc_') . '.o';
        }

        $asmPath = tempnam(sys_get_temp_dir(), 'cppc_') . '.s';
        file_put_contents($asmPath, $assembly);

        $cmd = sprintf('as -o %s %s 2>&1', escapeshellarg($outputPath), escapeshellarg($asmPath));
        exec($cmd, $output, $exitCode);
        unlink($asmPath);

        if ($exitCode !== 0) {
            throw new CompileError("Assembler failed: " . implode("\n", $output));
        }

        return $outputPath;
    }

    /**
     * Link with dynamic linking (requires CRT objects from system).
     *
     * @param string[] $objectFiles
     * @param string[] $libraries  e.g. ['m', 'pthread']
     * @param string[] $libPaths   e.g. ['/usr/local/lib']
     */
    public function link(array $objectFiles, string $outputPath, array $libraries = [], array $libPaths = []): void
    {
        $elfReader = new ElfReader();
        $dynSymReader = new DynSymReader();
        $linker = new Linker();

        // Read CRT objects for proper _start entry
        $crtPaths = $this->findCrtObjects();
        $crtOrder = ['crt1.o', 'crti.o']; // prefix
        $crtSuffix = ['crtn.o'];           // suffix

        foreach ($crtOrder as $crtName) {
            if (isset($crtPaths[$crtName])) {
                $module = $elfReader->readFile($crtPaths[$crtName]);
                $linker->addModule($module['sections'], $module['globals']);
            }
        }

        // Read user object files
        foreach ($objectFiles as $objPath) {
            $module = $elfReader->readFile($objPath);
            $linker->addModule($module['sections'], $module['globals']);
        }

        // Read CRT suffix
        foreach ($crtSuffix as $crtName) {
            if (isset($crtPaths[$crtName])) {
                $module = $elfReader->readFile($crtPaths[$crtName]);
                $linker->addModule($module['sections'], $module['globals']);
            }
        }

        // Resolve library symbols
        $neededLibs = ['libc.so.6']; // Always need libc
        $availableExternals = [];

        foreach ($libraries as $lib) {
            $soPath = $dynSymReader->findLibrary($lib, $libPaths);
            if ($soPath !== null) {
                $exports = $dynSymReader->readFile($soPath);
                foreach ($exports as $sym) {
                    $availableExternals[$sym] = true;
                }
                // Determine .so name for DT_NEEDED
                $soName = basename($soPath);
                // Extract SONAME pattern: libfoo.so.N
                if (!in_array($soName, $neededLibs, true) && $soName !== 'libc.so.6') {
                    $neededLibs[] = $soName;
                }
            }
        }

        // Also read libc exports
        $libcPath = $dynSymReader->findLibrary('c', $libPaths);
        if ($libcPath !== null) {
            $exports = $dynSymReader->readFile($libcPath);
            foreach ($exports as $sym) {
                $availableExternals[$sym] = true;
            }
        }

        // Find undefined symbols and classify as dynamic
        $undefined = $linker->findUndefinedSymbols();
        $gotPlt = new GotPltBuilder();
        
        // Scan for GOTPCREL relocations — these need GOT entries (PIC code)
        // Both defined and undefined symbols referenced via GOTPCREL need GOT entries
        $gotpcrelSymbols = [];
        foreach ($linker->getSections() as $sec) {
            foreach ($sec->relocs as $reloc) {
                if ($reloc->type === 'GOTPCREL') {
                    $gotpcrelSymbols[$reloc->target] = true;
                    // Only mark undefined symbols as dynamic
                    if (!isset($linker->getSymbols()[$reloc->target])) {
                        $linker->markDynamic($reloc->target);
                    }
                }
            }
        }

        // Add GOT entries for all GOTPCREL symbols (both defined and undefined)
        foreach (array_keys($gotpcrelSymbols) as $name) {
            $gotPlt->addGotDataSymbol($name);
        }

        // Add PLT entries for other undefined symbols (function calls via jmp, not GOTPCREL)
        foreach ($undefined as $name) {
            if (!isset($gotpcrelSymbols[$name])) {
                // Regular PLT for function calls
                $linker->markDynamic($name);
                $gotPlt->addPltSymbol($name);
            }
        }

        // Compute layout
        $layout = ElfWriter::computeDynLayout($linker, $gotPlt);

        // Build PLT address map for relocation resolution
        $pltAddrs = [];
        foreach ($gotPlt->getPltSymbols() as $name) {
            $pltAddrs[$name] = $gotPlt->getPltEntryAddr($name, $layout['pltVAddr']);
        }

        // Build GOT address map for relocation resolution
        $gotAddrs = [];
        foreach ($gotPlt->getGotDataSymbols() as $name) {
            $gotAddrs[$name] = $gotPlt->getGotEntryAddr($name, $layout['gotVAddr']);
        }

        // Resolve relocations (local symbols + PLT + GOT redirects)
        $linker->resolve($layout['vaddrs'], $pltAddrs, $gotAddrs);

        // Build final ELF
        $elfWriter = new ElfWriter();
        $binary = $elfWriter->buildDynExecutable(
            $linker->getSections(),
            $layout['entry'] ?? throw new CompileError('No entry point found'),
            $layout['vaddrs'],
            $gotPlt,
            $neededLibs,
        );

        file_put_contents($outputPath, $binary);
        chmod($outputPath, 0755);
    }

    /**
     * Find CRT object files in standard system directories.
     *
     * @return array<string, string> CRT name → full path
     */
    private function findCrtObjects(): array
    {
        $crtNames = ['crt1.o', 'crti.o', 'crtn.o'];
        $searchPaths = [
            '/usr/lib/x86_64-linux-gnu',
            '/usr/lib64',
            '/usr/lib',
            '/lib/x86_64-linux-gnu',
            '/lib64',
        ];

        $found = [];
        foreach ($crtNames as $name) {
            foreach ($searchPaths as $dir) {
                $path = "{$dir}/{$name}";
                if (file_exists($path)) {
                    $found[$name] = $path;
                    break;
                }
            }
        }

        return $found;
    }

    public function dumpAst(TranslationUnit $ast): string
    {
        return $ast->dump();
    }
}
