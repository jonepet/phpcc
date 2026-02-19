<?php

declare(strict_types=1);

namespace Cppc\Lexer;

use Cppc\CompileError;

/**
 * Full C preprocessor — supports the complete C11 preprocessing feature set
 * needed for parsing real system headers (stdio.h, stdlib.h, string.h, etc.).
 *
 * Supported directives:
 *   #include "file"            — local includes (relative to source, then system paths)
 *   #include <file>            — system includes (/usr/include, etc.)
 *   #define NAME value         — object-like macro
 *   #define NAME(a,b) body     — function-like macro
 *   #define NAME(a,...) body   — variadic macro (__VA_ARGS__)
 *   #undef NAME
 *   #ifdef NAME / #ifndef NAME / #if expr / #elif expr / #else / #endif
 *   #error message             — emit error and stop
 *   #warning message           — emit warning and continue
 *   #pragma once / #pragma ... — pragma handling
 *   #line N ["file"]           — line control
 *
 * Features:
 *   - Full #if expression evaluation (defined, &&, ||, !, comparisons, arithmetic, ternary)
 *   - Token pasting (##) and stringification (#)
 *   - Variadic macros with __VA_ARGS__
 *   - Multi-line macros with \ line continuation
 *   - Include guards detection
 *   - Macro recursion prevention (paint-blue algorithm)
 *   - Comment stripping (C and C++ style)
 *   - GCC built-in type macros and predefined macros
 *   - Pass-through of GCC extensions (__attribute__, __extension__, __asm__, etc.)
 */
class Preprocessor
{
    /** Canonical real-paths of files that have already been #pragma once'd or include-guarded. */
    private array $pragmaOnceFiles = [];

    /** Files currently being included (stack), used to detect circular includes. */
    private array $includeStack = [];

    /**
     * All macros (object-like and function-like).
     * name => [
     *   'params'   => null (object-like) | string[] (function-like),
     *   'variadic' => bool,
     *   'body'     => string,
     * ]
     * @var array<string, array{params: ?array<string>, variadic: bool, body: string}>
     */
    private array $macros = [];

    /** Include guard tracking: file realpath => guard macro name. */
    private array $includeGuards = [];

    /** User-supplied include paths (from -I flags). */
    private array $userIncludePaths = [];

    /** User-supplied defines (from -D flags). */
    private array $userDefines = [];

    /** Whether to define __cplusplus (true for .cpp/.cc/.cxx, false for .c/.i). */
    private bool $cppMode = true;

    /** System include search paths. */
    private array $systemIncludePaths = [];

    /** Current file being processed (for __FILE__). */
    private string $currentFile = '';

    /** Current line number (for __LINE__). */
    private int $currentLine = 0;

    /** Timestamp for __DATE__ and __TIME__ (frozen at process() entry). */
    private string $dateStr = '';
    private string $timeStr = '';

    /**
     * Add a user-defined macro (from -D flag).
     * Supports both -DFOO (value "1") and -DFOO=bar.
     */
    public function addDefine(string $name, string $value = '1'): void
    {
        $this->userDefines[$name] = $value;
    }

    /**
     * Set C++ mode (defines __cplusplus). Default is true.
     */
    public function setCppMode(bool $cppMode): void
    {
        $this->cppMode = $cppMode;
    }

    /**
     * Add a user include path (from -I flag).
     * These are searched before system paths.
     */
    public function addIncludePath(string $path): void
    {
        $this->userIncludePaths[] = $path;
    }

    public function process(string $source, string $file = '', string $sourceDir = ''): string
    {
        // Reset per-invocation state
        $this->pragmaOnceFiles = [];
        $this->includeStack = [];
        $this->macros = [];
        $this->includeGuards = [];

        // Freeze timestamp
        $this->dateStr = date('M d Y');
        $this->timeStr = date('H:i:s');

        // System include paths — user paths first, then system/stub paths.
        // C mode:   real system headers first (full type defs, no C++ syntax),
        //           our stubs as fallback (avoids typedef redefinition conflicts).
        // C++ mode: our stubs first (they're parser-friendly, no noexcept/throw),
        //           system headers as fallback.
        $stubPaths = [
            __DIR__ . '/../../runtime/include',
            '/app/runtime/include',
        ];
        $systemPaths = [
            '/usr/include/x86_64-linux-gnu',
            '/usr/include',
            '/usr/local/include',
        ];
        $this->systemIncludePaths = array_merge(
            $this->userIncludePaths,
            $this->cppMode ? [...$stubPaths, ...$systemPaths] : [...$systemPaths, ...$stubPaths],
        );

        // Register predefined macros
        $this->registerPredefinedMacros();

        // Apply user-defined macros (override predefined if needed)
        foreach ($this->userDefines as $name => $value) {
            $this->macros[$name] = [
                'params' => null,
                'variadic' => false,
                'body' => $value,
            ];
        }

        return $this->processSource($source, $file, $sourceDir);
    }

    private function registerPredefinedMacros(): void
    {
        $predefined = [
            // Standard
            '__STDC__' => '1',
            '__STDC_VERSION__' => '201710L',
            '__STDC_HOSTED__' => '1',

            // GCC version
            '__GNUC__' => '14',
            '__GNUC_MINOR__' => '0',
            '__GNUC_PATCHLEVEL__' => '0',

            // Platform / ABI
            '__USER_LABEL_PREFIX__' => '',
            '__REGISTER_PREFIX__' => '',
            '__x86_64__' => '1',
            '__x86_64' => '1',
            '__amd64__' => '1',
            '__amd64' => '1',
            '__linux__' => '1',
            '__linux' => '1',
            '__gnu_linux__' => '1',
            '__unix__' => '1',
            '__unix' => '1',
            '__LP64__' => '1',
            '_LP64' => '1',
            '__ELF__' => '1',

            // Type sizes
            '__SIZEOF_INT__' => '4',
            '__SIZEOF_LONG__' => '8',
            '__SIZEOF_LONG_LONG__' => '8',
            '__SIZEOF_SHORT__' => '2',
            '__SIZEOF_POINTER__' => '8',
            '__SIZEOF_FLOAT__' => '4',
            '__SIZEOF_DOUBLE__' => '8',
            '__SIZEOF_LONG_DOUBLE__' => '16',
            '__SIZEOF_SIZE_T__' => '8',
            '__SIZEOF_WCHAR_T__' => '4',
            '__SIZEOF_PTRDIFF_T__' => '8',

            // Type definitions
            '__SIZE_TYPE__' => 'unsigned long',
            '__PTRDIFF_TYPE__' => 'long',
            '__WCHAR_TYPE__' => 'int',
            '__WINT_TYPE__' => 'unsigned int',
            '__INT8_TYPE__' => 'signed char',
            '__INT16_TYPE__' => 'short',
            '__INT32_TYPE__' => 'int',
            '__INT64_TYPE__' => 'long',
            '__UINT8_TYPE__' => 'unsigned char',
            '__UINT16_TYPE__' => 'unsigned short',
            '__UINT32_TYPE__' => 'unsigned int',
            '__UINT64_TYPE__' => 'unsigned long',
            '__INTPTR_TYPE__' => 'long',
            '__UINTPTR_TYPE__' => 'unsigned long',
            '__INTMAX_TYPE__' => 'long',
            '__UINTMAX_TYPE__' => 'unsigned long',
            '__SIG_ATOMIC_TYPE__' => 'int',
            '__INT_FAST8_TYPE__' => 'signed char',
            '__INT_FAST16_TYPE__' => 'long',
            '__INT_FAST32_TYPE__' => 'long',
            '__INT_FAST64_TYPE__' => 'long',
            '__UINT_FAST8_TYPE__' => 'unsigned char',
            '__UINT_FAST16_TYPE__' => 'unsigned long',
            '__UINT_FAST32_TYPE__' => 'unsigned long',
            '__UINT_FAST64_TYPE__' => 'unsigned long',
            '__INT_LEAST8_TYPE__' => 'signed char',
            '__INT_LEAST16_TYPE__' => 'short',
            '__INT_LEAST32_TYPE__' => 'int',
            '__INT_LEAST64_TYPE__' => 'long',
            '__UINT_LEAST8_TYPE__' => 'unsigned char',
            '__UINT_LEAST16_TYPE__' => 'unsigned short',
            '__UINT_LEAST32_TYPE__' => 'unsigned int',
            '__UINT_LEAST64_TYPE__' => 'unsigned long',

            // Byte order
            '__BYTE_ORDER__' => '1234',
            '__ORDER_LITTLE_ENDIAN__' => '1234',
            '__ORDER_BIG_ENDIAN__' => '4321',
            '__ORDER_PDP_ENDIAN__' => '3412',
            '__FLOAT_WORD_ORDER__' => '1234',

            // Char properties
            '__CHAR_BIT__' => '8',
            '__CHAR_UNSIGNED__' => '0',

            // Limits
            '__INT_MAX__' => '2147483647',
            '__LONG_MAX__' => '9223372036854775807L',
            '__LONG_LONG_MAX__' => '9223372036854775807LL',
            '__SHRT_MAX__' => '32767',
            '__SCHAR_MAX__' => '127',
            '__WCHAR_MAX__' => '2147483647',
            '__WCHAR_MIN__' => '(-__WCHAR_MAX__ - 1)',
            '__SIZE_MAX__' => '18446744073709551615UL',
            '__PTRDIFF_MAX__' => '9223372036854775807L',
            '__INTMAX_MAX__' => '9223372036854775807L',
            '__UINTMAX_MAX__' => '18446744073709551615UL',

            // INT width macros
            '__INT8_MAX__' => '127',
            '__INT16_MAX__' => '32767',
            '__INT32_MAX__' => '2147483647',
            '__INT64_MAX__' => '9223372036854775807L',
            '__UINT8_MAX__' => '255',
            '__UINT16_MAX__' => '65535',
            '__UINT32_MAX__' => '4294967295U',
            '__UINT64_MAX__' => '18446744073709551615UL',

            // Suffix macros
            '__INT8_C' => '',
            '__INT16_C' => '',
            '__INT32_C' => '',
            '__UINT8_C' => '',
            '__UINT16_C' => '',
            '__UINT32_C' => '',

            // Feature flags
            '__STDC_UTF_16__' => '1',
            '__STDC_UTF_32__' => '1',
            '__STDC_IEC_559__' => '1',
            '__STDC_IEC_559_COMPLEX__' => '1',

            // GCC compatibility extras
            '__GNUC_STDC_INLINE__' => '1',
            '__GCC_HAVE_SYNC_COMPARE_AND_SWAP_1' => '1',
            '__GCC_HAVE_SYNC_COMPARE_AND_SWAP_2' => '1',
            '__GCC_HAVE_SYNC_COMPARE_AND_SWAP_4' => '1',
            '__GCC_HAVE_SYNC_COMPARE_AND_SWAP_8' => '1',
            '__GCC_ATOMIC_BOOL_LOCK_FREE' => '2',
            '__GCC_ATOMIC_CHAR_LOCK_FREE' => '2',
            '__GCC_ATOMIC_SHORT_LOCK_FREE' => '2',
            '__GCC_ATOMIC_INT_LOCK_FREE' => '2',
            '__GCC_ATOMIC_LONG_LOCK_FREE' => '2',
            '__GCC_ATOMIC_LLONG_LOCK_FREE' => '2',
            '__GCC_ATOMIC_POINTER_LOCK_FREE' => '2',

            // va_list
            '__BIGGEST_ALIGNMENT__' => '16',

            // C++ mode only (see also conditional block below)

            // Boolean
            '__bool_true_false_are_defined' => '1',
        ];

        foreach ($predefined as $name => $value) {
            $this->macros[$name] = [
                'params' => null,
                'variadic' => false,
                'body' => $value,
            ];
        }

        // Only define __cplusplus in C++ mode
        if ($this->cppMode) {
            $this->macros['__cplusplus'] = [
                'params' => null,
                'variadic' => false,
                'body' => '201703L',
            ];
        }

        // Function-like predefined macros
        // __INT64_C(c)
        $this->macros['__INT64_C'] = ['params' => ['c'], 'variadic' => false, 'body' => 'c ## L'];
        $this->macros['__UINT64_C'] = ['params' => ['c'], 'variadic' => false, 'body' => 'c ## UL'];
        $this->macros['__INTMAX_C'] = ['params' => ['c'], 'variadic' => false, 'body' => 'c ## L'];
        $this->macros['__UINTMAX_C'] = ['params' => ['c'], 'variadic' => false, 'body' => 'c ## UL'];

        // Note: __THROW, __nonnull, __BEGIN_DECLS, etc. come from <sys/cdefs.h>
        // which is included via <features.h> by system headers — no need to hardcode.
    }

    // ─── Main Processing Loop ──────────────────────────────────────────

    private function processSource(string $source, string $file, string $sourceDir): string
    {
        $prevFile = $this->currentFile;
        $prevLine = $this->currentLine;
        $this->currentFile = $file;

        // Strip comments first
        $source = $this->stripComments($source);

        // Handle line continuations (backslash-newline)
        $source = $this->joinContinuationLines($source);

        $lines = $this->splitLines($source);
        $output = [];

        // Conditional compilation stack
        // Each frame: ['active' => bool, 'seen_else' => bool, 'done' => bool, 'parent_emitting' => bool]
        $condStack = [];
        $emitting = true;

        $lineNum = 0;
        $totalLines = count($lines);

        // Include guard detection state
        $guardCandidate = null;
        $firstDirectiveSeen = false;

        while ($lineNum < $totalLines) {
            $rawLine = $lines[$lineNum];
            $lineNum++;
            $this->currentLine = $lineNum;
            $trimmed = ltrim($rawLine);

            // Empty lines pass through
            if ($trimmed === '') {
                $output[] = '';
                continue;
            }

            // Non-directive lines
            if (!str_starts_with($trimmed, '#')) {
                if ($emitting) {
                    // Join continuation lines for multi-line macro invocations.
                    // Two cases: (1) unclosed parentheses on the current line
                    // (e.g., glibc's __REDIRECT), and (2) the line ends with a
                    // function-like macro name whose '(' is on the next line
                    // (e.g., __attribute_deprecated_msg__\n  ("...")).
                    $joinedLine = $rawLine;
                    $extraLines = 0;
                    while ($lineNum < $totalLines) {
                        $needJoin = false;
                        if ($this->hasUnclosedParens($joinedLine)) {
                            $needJoin = true;
                        } elseif ($this->endsWithFunctionMacro($joinedLine)) {
                            // Peek: does the next line start with '('?
                            $peekTrimmed = ltrim($lines[$lineNum]);
                            if (str_starts_with($peekTrimmed, '(')) {
                                $needJoin = true;
                            }
                        }
                        if (!$needJoin) {
                            break;
                        }
                        $nextLine = $lines[$lineNum];
                        $nextTrimmed = ltrim($nextLine);
                        if (str_starts_with($nextTrimmed, '#')) {
                            break;
                        }
                        $lineNum++;
                        $extraLines++;
                        $this->currentLine = $lineNum;
                        $joinedLine .= ' ' . $nextTrimmed;
                    }
                    $output[] = $this->expandMacros($joinedLine);
                    // Emit blank lines to preserve line numbering
                    for ($el = 0; $el < $extraLines; $el++) {
                        $output[] = '';
                    }
                } else {
                    $output[] = ''; // preserve line count
                }
                continue;
            }

            [$directive, $rest] = $this->parseDirective($trimmed);

            // Handle empty # (null directive) - it's valid C
            if ($directive === '') {
                $output[] = '';
                continue;
            }

            // Conditional directives MUST be processed even when not emitting
            switch ($directive) {
                case 'ifdef':
                    $name = trim($rest);
                    $active = $emitting && $this->isDefined($name);
                    $condStack[] = [
                        'active' => $active,
                        'seen_else' => false,
                        'done' => $active,
                        'parent_emitting' => $emitting,
                    ];
                    $emitting = $active;
                    $output[] = '';
                    break;

                case 'ifndef':
                    $name = trim($rest);

                    // Include guard detection: if this is the very first directive
                    if (!$firstDirectiveSeen && $file !== '') {
                        $guardCandidate = $name;
                    }
                    $firstDirectiveSeen = true;

                    $active = $emitting && !$this->isDefined($name);
                    $condStack[] = [
                        'active' => $active,
                        'seen_else' => false,
                        'done' => $active,
                        'parent_emitting' => $emitting,
                    ];
                    $emitting = $active;
                    $output[] = '';
                    break;

                case 'if':
                    $firstDirectiveSeen = true;
                    if ($emitting) {
                        $val = $this->evaluateConditionExpression($rest, $file, $lineNum);
                        $active = $val !== 0;
                    } else {
                        $active = false;
                    }
                    $condStack[] = [
                        'active' => $active,
                        'seen_else' => false,
                        'done' => $active,
                        'parent_emitting' => $emitting,
                    ];
                    $emitting = $active;
                    $output[] = '';
                    break;

                case 'elif':
                    $firstDirectiveSeen = true;
                    if (empty($condStack)) {
                        throw new CompileError('#elif without #if', $file, $lineNum);
                    }
                    $frame = &$condStack[count($condStack) - 1];
                    if ($frame['seen_else']) {
                        throw new CompileError('#elif after #else', $file, $lineNum);
                    }
                    if ($frame['done'] || !$frame['parent_emitting']) {
                        $frame['active'] = false;
                        $emitting = false;
                    } else {
                        $val = $this->evaluateConditionExpression($rest, $file, $lineNum);
                        $active = $val !== 0;
                        $frame['active'] = $active;
                        $emitting = $active;
                        if ($active) {
                            $frame['done'] = true;
                        }
                    }
                    $output[] = '';
                    break;

                case 'else':
                    if (empty($condStack)) {
                        throw new CompileError('#else without #if/#ifdef/#ifndef', $file, $lineNum);
                    }
                    $frame = &$condStack[count($condStack) - 1];
                    if ($frame['seen_else']) {
                        throw new CompileError('Multiple #else directives', $file, $lineNum);
                    }
                    $frame['seen_else'] = true;
                    $frame['active'] = $frame['parent_emitting'] && !$frame['done'];
                    $emitting = $frame['active'];
                    if ($frame['active']) {
                        $frame['done'] = true;
                    }
                    $output[] = '';
                    break;

                case 'endif':
                    if (empty($condStack)) {
                        throw new CompileError('#endif without #if/#ifdef/#ifndef', $file, $lineNum);
                    }
                    array_pop($condStack);
                    $emitting = empty($condStack)
                        ? true
                        : $condStack[count($condStack) - 1]['active'];

                    // Include guard: if this is the last #endif at top level and
                    // we had a guard candidate, register the file as guarded
                    if (empty($condStack) && $guardCandidate !== null && $file !== '') {
                        // Check if we've reached the end of meaningful content
                        $hasMoreContent = false;
                        for ($peek = $lineNum; $peek < $totalLines; $peek++) {
                            $peekTrimmed = trim($lines[$peek]);
                            if ($peekTrimmed !== '') {
                                $hasMoreContent = true;
                                break;
                            }
                        }
                        if (!$hasMoreContent) {
                            $realPath = realpath($file) ?: $file;
                            $this->includeGuards[$realPath] = $guardCandidate;
                        }
                    }
                    $output[] = '';
                    break;

                case 'include':
                    $firstDirectiveSeen = true;
                    if (!$emitting) {
                        $output[] = '';
                        break;
                    }
                    // Expand macros in the #include argument (for computed includes)
                    $expandedRest = $this->expandMacros($rest);
                    $included = $this->processInclude($expandedRest, $file, $lineNum, $sourceDir);
                    $includedLines = $this->splitLines($included);
                    foreach ($includedLines as $il) {
                        $output[] = $il;
                    }
                    // Don't add an extra blank line if include already added content
                    if (empty($includedLines) || (count($includedLines) === 1 && $includedLines[0] === '')) {
                        $output[] = '';
                    }
                    break;

                case 'define':
                    if ($emitting) {
                        // Include guard detection: if the first define after #ifndef GUARD
                        // defines the same symbol, mark it
                        if ($guardCandidate !== null) {
                            $defName = $this->extractDefineName($rest);
                            if ($defName !== $guardCandidate) {
                                // Not a standard include guard pattern
                                $guardCandidate = null;
                            }
                        }
                        $this->processDefine($rest, $file, $lineNum);
                    }
                    $output[] = '';
                    break;

                case 'undef':
                    if ($emitting) {
                        $name = trim($rest);
                        unset($this->macros[$name]);
                    }
                    $output[] = '';
                    break;

                case 'pragma':
                    if ($emitting) {
                        $pragmaArg = trim($rest);
                        if ($pragmaArg === 'once') {
                            $realPath = $file !== '' ? (realpath($file) ?: $file) : '';
                            if ($realPath !== '') {
                                if (isset($this->pragmaOnceFiles[$realPath])) {
                                    $this->currentFile = $prevFile;
                                    $this->currentLine = $prevLine;
                                    return '';
                                }
                                $this->pragmaOnceFiles[$realPath] = true;
                            }
                        }
                        // Other pragmas are silently ignored or passed through
                    }
                    $output[] = '';
                    break;

                case 'error':
                    if ($emitting) {
                        throw new CompileError('#error ' . trim($rest), $file, $lineNum);
                    }
                    $output[] = '';
                    break;

                case 'warning':
                    if ($emitting) {
                        fwrite(STDERR, "{$file}:{$lineNum}: warning: #warning " . trim($rest) . "\n");
                    }
                    $output[] = '';
                    break;

                case 'line':
                    // #line N ["filename"] — adjust line numbering
                    // We just consume it; line preservation is done via blank lines
                    $output[] = '';
                    break;

                default:
                    // Unknown directive — silently drop
                    $output[] = '';
                    break;
            }
        }

        if (!empty($condStack)) {
            throw new CompileError(
                'Unterminated #if/#ifdef/#ifndef block (' . count($condStack) . ' level(s) deep)',
                $file,
                $lineNum
            );
        }

        $this->currentFile = $prevFile;
        $this->currentLine = $prevLine;

        return implode("\n", $output);
    }

    // ─── Comment Stripping ─────────────────────────────────────────────

    /**
     * Strip C and C++ comments from source, preserving newlines so line
     * numbers remain correct.
     */
    private function stripComments(string $source): string
    {
        $len = strlen($source);
        $result = '';
        $i = 0;

        while ($i < $len) {
            $ch = $source[$i];

            // String literal — pass through unchanged
            if ($ch === '"' || $ch === '\'') {
                $result .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $source[$i];
                    $result .= $c;
                    $i++;
                    if ($c === '\\' && $i < $len) {
                        $result .= $source[$i];
                        $i++;
                        continue;
                    }
                    if ($c === $ch) {
                        break;
                    }
                }
                continue;
            }

            // Possible comment start
            if ($ch === '/' && ($i + 1) < $len) {
                $next = $source[$i + 1];

                // Line comment //
                if ($next === '/') {
                    $i += 2;
                    while ($i < $len && $source[$i] !== "\n") {
                        $i++;
                    }
                    // Don't consume the newline — it stays
                    $result .= ' '; // replace comment with single space
                    continue;
                }

                // Block comment /* */
                if ($next === '*') {
                    $i += 2;
                    while ($i < $len) {
                        if ($source[$i] === '*' && ($i + 1) < $len && $source[$i + 1] === '/') {
                            $i += 2;
                            break;
                        }
                        if ($source[$i] === "\n") {
                            $result .= "\n"; // preserve newlines for line counting
                        }
                        $i++;
                    }
                    $result .= ' '; // replace comment with single space
                    continue;
                }
            }

            $result .= $ch;
            $i++;
        }

        return $result;
    }

    // ─── Line Continuation ─────────────────────────────────────────────

    /**
     * Join lines ending with backslash-newline, preserving line count
     * by inserting blank lines for consumed continuation lines.
     */
    private function joinContinuationLines(string $source): string
    {
        // Fast path: no continuations
        if (strpos($source, "\\\n") === false) {
            return $source;
        }

        $lines = explode("\n", $source);
        $result = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];
            $mergeCount = 0;

            while ($i + $mergeCount + 1 < $count) {
                // Check if current accumulated line ends with backslash
                $trimmed = rtrim($line);
                if (!str_ends_with($trimmed, '\\')) {
                    break;
                }
                // Remove the trailing backslash and join with next line
                $mergeCount++;
                $line = substr($trimmed, 0, -1) . $lines[$i + $mergeCount];
            }

            $result[] = $line;
            // Add blank lines for each consumed continuation to preserve line count
            for ($j = 0; $j < $mergeCount; $j++) {
                $result[] = '';
            }
            $i += 1 + $mergeCount;
        }

        return implode("\n", $result);
    }

    // ─── Include Processing ────────────────────────────────────────────

    private function processInclude(
        string $rest,
        string $parentFile,
        int    $lineNum,
        string $sourceDir,
    ): string {
        $rest = trim($rest);

        $isSystem = false;
        $includePath = '';

        if (str_starts_with($rest, '<')) {
            $closePos = strrpos($rest, '>');
            if ($closePos === false) {
                throw new CompileError('Malformed #include <...> directive', $parentFile, $lineNum);
            }
            $includePath = substr($rest, 1, $closePos - 1);
            $isSystem = true;
        } elseif (str_starts_with($rest, '"')) {
            $closeQuote = strpos($rest, '"', 1);
            if ($closeQuote === false) {
                throw new CompileError('Malformed #include "..." directive', $parentFile, $lineNum);
            }
            $includePath = substr($rest, 1, $closeQuote - 1);
            $isSystem = false;
        } else {
            // Could be a macro-expanded include; try to parse the expanded result
            // If it expanded to <...> or "..."
            $expanded = trim($rest);
            if (str_starts_with($expanded, '<')) {
                $closePos = strrpos($expanded, '>');
                if ($closePos !== false) {
                    $includePath = substr($expanded, 1, $closePos - 1);
                    $isSystem = true;
                }
            } elseif (str_starts_with($expanded, '"')) {
                $closeQuote = strpos($expanded, '"', 1);
                if ($closeQuote !== false) {
                    $includePath = substr($expanded, 1, $closeQuote - 1);
                    $isSystem = false;
                }
            }

            if ($includePath === '') {
                throw new CompileError(
                    "Malformed #include directive: expected '\"..\"' or '<..>'",
                    $parentFile,
                    $lineNum,
                );
            }
        }

        // Build candidate list
        $candidates = [];

        if (!$isSystem) {
            // For "..." includes, search relative to including file first, then sourceDir
            $parentDir = $parentFile !== '' ? dirname($parentFile) : $sourceDir;
            if ($parentDir !== '') {
                $candidates[] = $parentDir . '/' . $includePath;
            }
            if ($sourceDir !== '' && $sourceDir !== ($parentDir ?? '')) {
                $candidates[] = $sourceDir . '/' . $includePath;
            }
        }

        // System paths (always searched for <> includes, and as fallback for "" includes)
        foreach ($this->systemIncludePaths as $sysPath) {
            $candidates[] = $sysPath . '/' . $includePath;
        }

        // Resolve
        $resolvedPath = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $resolvedPath = $candidate;
                break;
            }
        }

        if ($resolvedPath === null) {
            if ($isSystem) {
                // System includes that can't be found: emit warning and return empty
                fwrite(STDERR, "Warning: {$parentFile}:{$lineNum}: cannot find <{$includePath}>\n");
                return '';
            }
            throw new CompileError(
                "Cannot open include file: '{$includePath}'",
                $parentFile,
                $lineNum,
            );
        }

        $realPath = realpath($resolvedPath) ?: $resolvedPath;

        // Check pragma once
        if (isset($this->pragmaOnceFiles[$realPath])) {
            return '';
        }

        // Check include guard
        if (isset($this->includeGuards[$realPath])) {
            $guardMacro = $this->includeGuards[$realPath];
            if ($this->isDefined($guardMacro)) {
                return '';
            }
        }

        // Check circular include
        if (in_array($realPath, $this->includeStack, true)) {
            // Silently skip circular includes (common with system headers)
            return '';
        }

        $this->includeStack[] = $realPath;

        $content = @file_get_contents($resolvedPath);
        if ($content === false) {
            array_pop($this->includeStack);
            throw new CompileError(
                "Cannot read include file: '{$resolvedPath}'",
                $parentFile,
                $lineNum,
            );
        }

        $result = $this->processSource($content, $resolvedPath, dirname($resolvedPath));
        array_pop($this->includeStack);

        return $result;
    }

    // ─── #define Processing ────────────────────────────────────────────

    private function processDefine(string $rest, string $file, int $lineNum): void
    {
        $rest = ltrim($rest);

        if ($rest === '') {
            throw new CompileError('#define with no macro name', $file, $lineNum);
        }

        // Parse the macro name
        $nameLen = 0;
        $len = strlen($rest);
        while ($nameLen < $len && $this->isIdentChar($rest[$nameLen], $nameLen === 0)) {
            $nameLen++;
        }
        $name = substr($rest, 0, $nameLen);
        $after = substr($rest, $nameLen);

        // Function-like macro: name IMMEDIATELY followed by '(' (no space)
        if (isset($after[0]) && $after[0] === '(') {
            $closeIdx = $this->findMatchingParen($after, 0);
            if ($closeIdx === false) {
                throw new CompileError(
                    "Malformed function-like macro: missing ')' in #define {$name}",
                    $file,
                    $lineNum,
                );
            }
            $paramStr = substr($after, 1, $closeIdx - 1);
            $variadic = false;
            $params = [];

            if (trim($paramStr) !== '') {
                $rawParams = array_map('trim', explode(',', $paramStr));
                foreach ($rawParams as $p) {
                    if ($p === '...') {
                        $variadic = true;
                    } elseif (str_ends_with($p, '...')) {
                        // GNU extension: named variadic parameter like args...
                        $variadic = true;
                        $params[] = substr($p, 0, -3);
                    } else {
                        $params[] = $p;
                    }
                }
            }

            $body = ltrim(substr($after, $closeIdx + 1));

            $this->macros[$name] = [
                'params' => $params,
                'variadic' => $variadic,
                'body' => $body,
            ];
            return;
        }

        // Object-like macro
        $value = ltrim($after);
        $this->macros[$name] = [
            'params' => null,
            'variadic' => false,
            'body' => $value,
        ];
    }

    /**
     * Extract just the name from a #define rest string, used for include guard detection.
     */
    private function extractDefineName(string $rest): string
    {
        $rest = ltrim($rest);
        $nameLen = 0;
        $len = strlen($rest);
        while ($nameLen < $len && $this->isIdentChar($rest[$nameLen], $nameLen === 0)) {
            $nameLen++;
        }
        return substr($rest, 0, $nameLen);
    }

    // ─── Macro Expansion ───────────────────────────────────────────────

    /**
     * Expand all macros in a line of source text.
     * Uses the "paint blue" algorithm to prevent recursive expansion.
     */
    private function expandMacros(string $text, array $hiddenSet = []): string
    {
        if (empty($this->macros)) {
            return $text;
        }

        $result = '';
        $pos = 0;
        $len = strlen($text);

        while ($pos < $len) {
            $ch = $text[$pos];

            // String literals — pass through unchanged
            if ($ch === '"' || $ch === '\'') {
                [$literal, $consumed] = $this->consumeStringLiteral($text, $pos);
                $result .= $literal;
                $pos += $consumed;
                continue;
            }

            // Check for L"..." (wide string) or L'...' (wide char)
            if ($ch === 'L' && ($pos + 1) < $len && ($text[$pos + 1] === '"' || $text[$pos + 1] === '\'')) {
                $result .= 'L';
                $pos++;
                [$literal, $consumed] = $this->consumeStringLiteral($text, $pos);
                $result .= $literal;
                $pos += $consumed;
                continue;
            }

            // Identifier
            if ($this->isIdentStart($ch)) {
                $idStart = $pos;
                $pos++;
                while ($pos < $len && $this->isIdentContinue($text[$pos])) {
                    $pos++;
                }
                $ident = substr($text, $idStart, $pos - $idStart);

                // Handle special predefined macros that need dynamic values
                if ($ident === '__FILE__') {
                    $result .= '"' . addcslashes($this->currentFile, '"\\') . '"';
                    continue;
                }
                if ($ident === '__LINE__') {
                    $result .= (string)$this->currentLine;
                    continue;
                }
                if ($ident === '__DATE__') {
                    $result .= '"' . $this->dateStr . '"';
                    continue;
                }
                if ($ident === '__TIME__') {
                    $result .= '"' . $this->timeStr . '"';
                    continue;
                }

                // GCC keyword pass-through / translations
                $translated = $this->translateGccKeyword($ident);
                if ($translated !== null) {
                    $result .= $translated;
                    continue;
                }

                // Paint-blue: don't re-expand macros in the hidden set
                if (isset($hiddenSet[$ident])) {
                    $result .= $ident;
                    continue;
                }

                // Check if it's a defined macro
                if (isset($this->macros[$ident])) {
                    $macro = $this->macros[$ident];

                    if ($macro['params'] !== null) {
                        // Function-like macro: must have parenthesized args
                        $tmp = $pos;
                        while ($tmp < $len && ($text[$tmp] === ' ' || $text[$tmp] === "\t" || $text[$tmp] === "\n")) {
                            $tmp++;
                        }
                        if ($tmp < $len && $text[$tmp] === '(') {
                            [$args, $newPos] = $this->parseCallArgs($text, $tmp);
                            $pos = $newPos;
                            $expanded = $this->expandFunctionMacro($ident, $macro, $args, $hiddenSet);
                            // Re-expand the result (with ident added to hidden set)
                            $newHidden = $hiddenSet;
                            $newHidden[$ident] = true;
                            $result .= $this->expandMacros($expanded, $newHidden);
                            continue;
                        }
                        // No parentheses — don't expand function-like macro
                        $result .= $ident;
                        continue;
                    }

                    // Object-like macro
                    $body = $macro['body'];
                    $newHidden = $hiddenSet;
                    $newHidden[$ident] = true;
                    $result .= $this->expandMacros($body, $newHidden);
                    continue;
                }

                $result .= $ident;
                continue;
            }

            $result .= $ch;
            $pos++;
        }

        return $result;
    }

    /**
     * Translate GCC-specific keywords to standard equivalents, or return null
     * if not a GCC keyword.
     */
    private function translateGccKeyword(string $ident): ?string
    {
        return match ($ident) {
            '__inline__', '__inline' => 'inline',
            '__restrict', '__restrict__' => 'restrict',
            '__volatile__' => 'volatile',
            '__const__', '__const' => 'const',
            '__signed__' => 'signed',
            // These are passed through as-is
            '__attribute__', '__extension__', '__asm__', '__asm', 'asm',
            '__typeof__', 'typeof', '__typeof',
            '__builtin_va_list', '__builtin_va_start', '__builtin_va_end',
            '__builtin_va_arg', '__builtin_va_copy',
            '__builtin_offsetof',
            '__builtin_types_compatible_p',
            '__builtin_constant_p',
            '__builtin_expect',
            '__builtin_prefetch',
            '__builtin_unreachable',
            '__builtin_trap',
            '__builtin_clz', '__builtin_ctz',
            '__builtin_popcount',
            '__builtin_bswap16', '__builtin_bswap32', '__builtin_bswap64',
            '__builtin_huge_val', '__builtin_huge_valf',
            '__builtin_inf', '__builtin_inff',
            '__builtin_nan', '__builtin_nanf',
            '__builtin_object_size',
            '__builtin_choose_expr'
            => null, // return null means "not translated, handle normally"
            default => null,
        };
    }

    /**
     * Expand a function-like macro with the given arguments.
     * Handles stringification (#), token pasting (##), and __VA_ARGS__.
     */
    private function expandFunctionMacro(string $name, array $macro, array $rawArgs, array $hiddenSet): string
    {
        $params = $macro['params'];
        $variadic = $macro['variadic'];
        $body = $macro['body'];

        // Trim args
        $args = array_map('trim', $rawArgs);

        // Build parameter -> argument map
        $paramMap = [];
        foreach ($params as $i => $param) {
            $paramMap[$param] = $args[$i] ?? '';
        }

        // Handle variadic: __VA_ARGS__ gets the extra arguments
        if ($variadic) {
            $vaArgs = array_slice($rawArgs, count($params));
            $vaArgsStr = implode(',', array_map('trim', $vaArgs));
            $paramMap['__VA_ARGS__'] = $vaArgsStr;
        }

        // Process the body: handle # (stringification) and ## (token pasting)
        $result = $this->substituteParams($body, $paramMap, $hiddenSet);

        return $result;
    }

    /**
     * Substitute parameters in macro body, handling # and ##.
     */
    private function substituteParams(string $body, array $paramMap, array $hiddenSet): string
    {
        $len = strlen($body);
        $result = '';
        $i = 0;

        while ($i < $len) {
            $ch = $body[$i];

            // String literal in macro body — pass through
            if ($ch === '"' || $ch === '\'') {
                [$literal, $consumed] = $this->consumeStringLiteral($body, $i);
                $result .= $literal;
                $i += $consumed;
                continue;
            }

            // Token pasting operator ##
            if ($ch === '#' && ($i + 1) < $len && $body[$i + 1] === '#') {
                // Remove trailing whitespace from result
                $result = rtrim($result);
                $i += 2;
                // Skip whitespace after ##
                while ($i < $len && ($body[$i] === ' ' || $body[$i] === "\t")) {
                    $i++;
                }
                // Get the next token
                if ($i < $len && $this->isIdentStart($body[$i])) {
                    $tokStart = $i;
                    $i++;
                    while ($i < $len && $this->isIdentContinue($body[$i])) {
                        $i++;
                    }
                    $nextTok = substr($body, $tokStart, $i - $tokStart);
                    if (isset($paramMap[$nextTok])) {
                        $result .= $paramMap[$nextTok];
                    } else {
                        $result .= $nextTok;
                    }
                } elseif ($i < $len) {
                    // Non-identifier token after ##
                    $result .= $body[$i];
                    $i++;
                }
                continue;
            }

            // Stringification operator #
            if ($ch === '#' && ($i + 1) < $len && $body[$i + 1] !== '#') {
                $i++;
                // Skip whitespace
                while ($i < $len && ($body[$i] === ' ' || $body[$i] === "\t")) {
                    $i++;
                }
                // Read parameter name
                if ($i < $len && $this->isIdentStart($body[$i])) {
                    $tokStart = $i;
                    $i++;
                    while ($i < $len && $this->isIdentContinue($body[$i])) {
                        $i++;
                    }
                    $paramName = substr($body, $tokStart, $i - $tokStart);
                    if (isset($paramMap[$paramName])) {
                        $result .= $this->stringify($paramMap[$paramName]);
                    } else {
                        // Not a parameter; output as-is
                        $result .= '#' . $paramName;
                    }
                } else {
                    $result .= '#';
                }
                continue;
            }

            // Check for ## after an identifier (the left-hand side)
            // Look ahead past this identifier to check for ##
            if ($this->isIdentStart($ch)) {
                $tokStart = $i;
                $i++;
                while ($i < $len && $this->isIdentContinue($body[$i])) {
                    $i++;
                }
                $ident = substr($body, $tokStart, $i - $tokStart);

                // Check if ## follows
                $peekPos = $i;
                while ($peekPos < $len && ($body[$peekPos] === ' ' || $body[$peekPos] === "\t")) {
                    $peekPos++;
                }
                if ($peekPos < $len && ($peekPos + 1) < $len &&
                    $body[$peekPos] === '#' && $body[$peekPos + 1] === '#') {
                    // Left side of ##: substitute parameter without expansion
                    if (isset($paramMap[$ident])) {
                        $result .= $paramMap[$ident];
                    } else {
                        $result .= $ident;
                    }
                    // The ## will be consumed on the next iteration
                    continue;
                }

                // Regular identifier: substitute parameter with expansion
                if (isset($paramMap[$ident])) {
                    $argValue = $paramMap[$ident];
                    // Expand macros in the argument before substitution
                    $result .= $this->expandMacros($argValue, $hiddenSet);
                } else {
                    $result .= $ident;
                }
                continue;
            }

            $result .= $ch;
            $i++;
        }

        return $result;
    }

    /**
     * Stringify a macro argument (for the # operator).
     */
    private function stringify(string $arg): string
    {
        $arg = trim($arg);
        // Escape backslashes and double quotes
        $escaped = str_replace('\\', '\\\\', $arg);
        $escaped = str_replace('"', '\\"', $escaped);
        return '"' . $escaped . '"';
    }

    // ─── #if Expression Evaluation ─────────────────────────────────────

    /**
     * Evaluate a preprocessor #if / #elif condition expression.
     * Returns the integer value (0 = false, non-zero = true).
     */
    private function evaluateConditionExpression(string $expr, string $file, int $lineNum): int
    {
        // First expand macros (but handle 'defined' specially)
        $expr = $this->expandConditionMacros($expr);

        // Replace any remaining identifiers with 0 (per C standard)
        $expr = $this->replaceUndefinedIdentifiers($expr);

        // Parse and evaluate the expression
        $tokens = $this->tokenizeExpression($expr);
        $pos = 0;
        $result = $this->parseExprTernary($tokens, $pos, $file, $lineNum);
        return $result;
    }

    /**
     * Expand macros in a condition expression, handling `defined(X)` and `defined X` specially.
     */
    private function expandConditionMacros(string $expr): string
    {
        $result = '';
        $pos = 0;
        $len = strlen($expr);

        while ($pos < $len) {
            $ch = $expr[$pos];

            // Skip whitespace
            if ($ch === ' ' || $ch === "\t") {
                $result .= $ch;
                $pos++;
                continue;
            }

            // String literal
            if ($ch === '"' || $ch === '\'') {
                [$literal, $consumed] = $this->consumeStringLiteral($expr, $pos);
                $result .= $literal;
                $pos += $consumed;
                continue;
            }

            // Number
            if ($ch >= '0' && $ch <= '9') {
                $start = $pos;
                while ($pos < $len && ($this->isIdentContinue($expr[$pos]) || $expr[$pos] === '.' || $expr[$pos] === '+' || $expr[$pos] === '-')) {
                    // Handle hex 0x..., suffixes like UL, LL, etc.
                    $pos++;
                }
                $result .= substr($expr, $start, $pos - $start);
                continue;
            }

            // Identifier
            if ($this->isIdentStart($ch)) {
                $start = $pos;
                $pos++;
                while ($pos < $len && $this->isIdentContinue($expr[$pos])) {
                    $pos++;
                }
                $ident = substr($expr, $start, $pos - $start);

                // Handle 'defined' operator
                if ($ident === 'defined') {
                    // Skip whitespace
                    while ($pos < $len && ($expr[$pos] === ' ' || $expr[$pos] === "\t")) {
                        $pos++;
                    }
                    if ($pos < $len && $expr[$pos] === '(') {
                        // defined(MACRO)
                        $pos++; // skip (
                        while ($pos < $len && ($expr[$pos] === ' ' || $expr[$pos] === "\t")) {
                            $pos++;
                        }
                        $mStart = $pos;
                        while ($pos < $len && $this->isIdentContinue($expr[$pos])) {
                            $pos++;
                        }
                        $macroName = substr($expr, $mStart, $pos - $mStart);
                        while ($pos < $len && ($expr[$pos] === ' ' || $expr[$pos] === "\t")) {
                            $pos++;
                        }
                        if ($pos < $len && $expr[$pos] === ')') {
                            $pos++;
                        }
                        $result .= $this->isDefined($macroName) ? '1' : '0';
                    } else {
                        // defined MACRO
                        $mStart = $pos;
                        while ($pos < $len && $this->isIdentContinue($expr[$pos])) {
                            $pos++;
                        }
                        $macroName = substr($expr, $mStart, $pos - $mStart);
                        $result .= $this->isDefined($macroName) ? '1' : '0';
                    }
                    continue;
                }

                // Handle __has_builtin(x), __has_attribute(x), __has_include(x)
                if ($ident === '__has_builtin' || $ident === '__has_attribute') {
                    // Always return 0 for now
                    while ($pos < $len && ($expr[$pos] === ' ' || $expr[$pos] === "\t")) {
                        $pos++;
                    }
                    if ($pos < $len && $expr[$pos] === '(') {
                        $depth = 1;
                        $pos++;
                        while ($pos < $len && $depth > 0) {
                            if ($expr[$pos] === '(') $depth++;
                            elseif ($expr[$pos] === ')') $depth--;
                            $pos++;
                        }
                    }
                    $result .= '0';
                    continue;
                }

                if ($ident === '__has_include') {
                    // Check if the file exists
                    while ($pos < $len && ($expr[$pos] === ' ' || $expr[$pos] === "\t")) {
                        $pos++;
                    }
                    if ($pos < $len && $expr[$pos] === '(') {
                        $pos++; // skip (
                        while ($pos < $len && ($expr[$pos] === ' ' || $expr[$pos] === "\t")) {
                            $pos++;
                        }
                        $headerName = '';
                        $isSystem = false;
                        if ($pos < $len && $expr[$pos] === '<') {
                            $isSystem = true;
                            $pos++;
                            while ($pos < $len && $expr[$pos] !== '>') {
                                $headerName .= $expr[$pos];
                                $pos++;
                            }
                            if ($pos < $len) $pos++; // skip >
                        } elseif ($pos < $len && $expr[$pos] === '"') {
                            $pos++;
                            while ($pos < $len && $expr[$pos] !== '"') {
                                $headerName .= $expr[$pos];
                                $pos++;
                            }
                            if ($pos < $len) $pos++; // skip "
                        }
                        while ($pos < $len && ($expr[$pos] === ' ' || $expr[$pos] === "\t")) {
                            $pos++;
                        }
                        if ($pos < $len && $expr[$pos] === ')') {
                            $pos++;
                        }

                        $found = false;
                        foreach ($this->systemIncludePaths as $sysPath) {
                            if (is_file($sysPath . '/' . $headerName)) {
                                $found = true;
                                break;
                            }
                        }
                        $result .= $found ? '1' : '0';
                    } else {
                        $result .= '0';
                    }
                    continue;
                }

                // Handle special dynamic macros
                if ($ident === '__FILE__' || $ident === '__LINE__' ||
                    $ident === '__DATE__' || $ident === '__TIME__') {
                    // These get expanded in expandMacros, but in #if context
                    // __LINE__ should work as a number
                    if ($ident === '__LINE__') {
                        $result .= (string)$this->currentLine;
                        continue;
                    }
                    // Others aren't numeric, leave as identifier (will become 0)
                    $result .= $ident;
                    continue;
                }

                // Regular identifier: try to expand it as a macro
                if (isset($this->macros[$ident])) {
                    $macro = $this->macros[$ident];
                    if ($macro['params'] !== null) {
                        // Function-like macro: check for args
                        $tmp = $pos;
                        while ($tmp < $len && ($expr[$tmp] === ' ' || $expr[$tmp] === "\t")) {
                            $tmp++;
                        }
                        if ($tmp < $len && $expr[$tmp] === '(') {
                            [$args, $newPos] = $this->parseCallArgs($expr, $tmp);
                            $pos = $newPos;
                            $expanded = $this->expandFunctionMacro($ident, $macro, $args, [$ident => true]);
                            // Re-expand
                            $result .= $this->expandConditionMacros($expanded);
                            continue;
                        }
                        // No args, leave as identifier
                        $result .= $ident;
                    } else {
                        $body = $macro['body'];
                        // Re-expand the body
                        $result .= $this->expandConditionMacros($body);
                    }
                    continue;
                }

                // Unknown identifier — will become 0
                $result .= $ident;
                continue;
            }

            // Operators and other characters
            $result .= $ch;
            $pos++;
        }

        return $result;
    }

    /**
     * Replace any remaining identifiers in an expression with 0.
     * Per C standard, undefined identifiers in #if expressions evaluate to 0.
     */
    private function replaceUndefinedIdentifiers(string $expr): string
    {
        // We need to skip character and string literals when replacing identifiers
        $result = '';
        $pos = 0;
        $len = strlen($expr);

        while ($pos < $len) {
            $ch = $expr[$pos];

            // Skip string and character literals
            if ($ch === '"' || $ch === '\'') {
                [$literal, $consumed] = $this->consumeStringLiteral($expr, $pos);
                $result .= $literal;
                $pos += $consumed;
                continue;
            }

            // Identifier
            if ($this->isIdentStart($ch)) {
                $start = $pos;
                $pos++;
                while ($pos < $len && $this->isIdentContinue($expr[$pos])) {
                    $pos++;
                }
                $ident = substr($expr, $start, $pos - $start);
                // Replace undefined identifiers with 0
                $result .= '0';
                continue;
            }

            // Numbers (skip over entirely, including suffixes)
            if ($ch >= '0' && $ch <= '9') {
                $start = $pos;
                // Hex prefix
                if ($ch === '0' && ($pos + 1) < $len && ($expr[$pos + 1] === 'x' || $expr[$pos + 1] === 'X')) {
                    $pos += 2;
                    while ($pos < $len && ctype_xdigit($expr[$pos])) {
                        $pos++;
                    }
                } else {
                    while ($pos < $len && ($expr[$pos] >= '0' && $expr[$pos] <= '9')) {
                        $pos++;
                    }
                }
                // Suffixes
                while ($pos < $len && ($expr[$pos] === 'u' || $expr[$pos] === 'U' ||
                       $expr[$pos] === 'l' || $expr[$pos] === 'L')) {
                    $pos++;
                }
                $result .= substr($expr, $start, $pos - $start);
                continue;
            }

            $result .= $ch;
            $pos++;
        }

        return $result;
    }

    /**
     * Tokenize a preprocessor expression for the expression evaluator.
     * Returns array of tokens: ['type' => ..., 'value' => ...]
     */
    private function tokenizeExpression(string $expr): array
    {
        $tokens = [];
        $pos = 0;
        $len = strlen($expr);

        while ($pos < $len) {
            $ch = $expr[$pos];

            // Skip whitespace
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $pos++;
                continue;
            }

            // Number (decimal, hex, octal)
            if ($ch >= '0' && $ch <= '9') {
                $start = $pos;
                if ($ch === '0' && ($pos + 1) < $len && ($expr[$pos + 1] === 'x' || $expr[$pos + 1] === 'X')) {
                    // Hex
                    $pos += 2;
                    while ($pos < $len && ctype_xdigit($expr[$pos])) {
                        $pos++;
                    }
                } elseif ($ch === '0' && ($pos + 1) < $len && ($expr[$pos + 1] === 'b' || $expr[$pos + 1] === 'B')) {
                    // Binary
                    $pos += 2;
                    while ($pos < $len && ($expr[$pos] === '0' || $expr[$pos] === '1')) {
                        $pos++;
                    }
                } elseif ($ch === '0') {
                    // Octal or just 0
                    $pos++;
                    while ($pos < $len && $expr[$pos] >= '0' && $expr[$pos] <= '7') {
                        $pos++;
                    }
                } else {
                    // Decimal
                    while ($pos < $len && $expr[$pos] >= '0' && $expr[$pos] <= '9') {
                        $pos++;
                    }
                }
                // Skip suffixes: u, U, l, L, ll, LL, etc.
                while ($pos < $len && ($expr[$pos] === 'u' || $expr[$pos] === 'U' ||
                       $expr[$pos] === 'l' || $expr[$pos] === 'L')) {
                    $pos++;
                }
                $numStr = substr($expr, $start, $pos - $start);
                $tokens[] = ['type' => 'num', 'value' => $this->parseNumber($numStr)];
                continue;
            }

            // Character literal 'x'
            if ($ch === '\'') {
                $pos++;
                $charVal = 0;
                if ($pos < $len && $expr[$pos] === '\\') {
                    $pos++;
                    if ($pos < $len) {
                        $charVal = match ($expr[$pos]) {
                            'n' => 10, 'r' => 13, 't' => 9, '0' => 0,
                            '\\' => 92, '\'' => 39, '"' => 34,
                            'a' => 7, 'b' => 8, 'f' => 12, 'v' => 11,
                            default => ord($expr[$pos]),
                        };
                        $pos++;
                    }
                } elseif ($pos < $len) {
                    $charVal = ord($expr[$pos]);
                    $pos++;
                }
                if ($pos < $len && $expr[$pos] === '\'') {
                    $pos++;
                }
                $tokens[] = ['type' => 'num', 'value' => $charVal];
                continue;
            }

            // Two-character operators
            if (($pos + 1) < $len) {
                $two = $ch . $expr[$pos + 1];
                if (in_array($two, ['&&', '||', '==', '!=', '<=', '>=', '<<', '>>', '##'])) {
                    $tokens[] = ['type' => 'op', 'value' => $two];
                    $pos += 2;
                    continue;
                }
            }

            // Single-character operators
            if (strpos('+-*/%<>&|^~!?:(),', $ch) !== false) {
                $tokens[] = ['type' => 'op', 'value' => $ch];
                $pos++;
                continue;
            }

            // Skip anything else
            $pos++;
        }

        return $tokens;
    }

    /**
     * Parse a number string (decimal, hex, octal, binary) to integer.
     */
    private function parseNumber(string $numStr): int
    {
        // Remove suffixes
        $numStr = rtrim($numStr, 'uUlL');

        if ($numStr === '' || $numStr === '0') {
            return 0;
        }

        if (str_starts_with($numStr, '0x') || str_starts_with($numStr, '0X')) {
            return intval($numStr, 16);
        }
        if (str_starts_with($numStr, '0b') || str_starts_with($numStr, '0B')) {
            return intval(substr($numStr, 2), 2);
        }
        if ($numStr[0] === '0') {
            return intval($numStr, 8);
        }
        return intval($numStr, 10);
    }

    // ── Expression Parsing (recursive descent) ─────────────────────────

    private function parseExprTernary(array &$tokens, int &$pos, string $file, int $line): int
    {
        $cond = $this->parseExprOr($tokens, $pos, $file, $line);

        if ($pos < count($tokens) && $tokens[$pos]['value'] === '?') {
            $pos++;
            $trueVal = $this->parseExprTernary($tokens, $pos, $file, $line);
            if ($pos < count($tokens) && $tokens[$pos]['value'] === ':') {
                $pos++;
            }
            $falseVal = $this->parseExprTernary($tokens, $pos, $file, $line);
            return $cond ? $trueVal : $falseVal;
        }

        return $cond;
    }

    private function parseExprOr(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprAnd($tokens, $pos, $file, $line);

        while ($pos < count($tokens) && $tokens[$pos]['value'] === '||') {
            $pos++;
            $right = $this->parseExprAnd($tokens, $pos, $file, $line);
            $left = ($left || $right) ? 1 : 0;
        }

        return $left;
    }

    private function parseExprAnd(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprBitOr($tokens, $pos, $file, $line);

        while ($pos < count($tokens) && $tokens[$pos]['value'] === '&&') {
            $pos++;
            $right = $this->parseExprBitOr($tokens, $pos, $file, $line);
            $left = ($left && $right) ? 1 : 0;
        }

        return $left;
    }

    private function parseExprBitOr(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprBitXor($tokens, $pos, $file, $line);

        while ($pos < count($tokens) && $tokens[$pos]['value'] === '|' &&
               !($pos + 1 < count($tokens) && $tokens[$pos + 1]['value'] === '|')) {
            // Single | not followed by another |
            $pos++;
            $right = $this->parseExprBitXor($tokens, $pos, $file, $line);
            $left = $left | $right;
        }

        return $left;
    }

    private function parseExprBitXor(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprBitAnd($tokens, $pos, $file, $line);

        while ($pos < count($tokens) && $tokens[$pos]['value'] === '^') {
            $pos++;
            $right = $this->parseExprBitAnd($tokens, $pos, $file, $line);
            $left = $left ^ $right;
        }

        return $left;
    }

    private function parseExprBitAnd(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprEquality($tokens, $pos, $file, $line);

        while ($pos < count($tokens) && $tokens[$pos]['value'] === '&' &&
               !($pos + 1 < count($tokens) && $tokens[$pos + 1]['value'] === '&')) {
            $pos++;
            $right = $this->parseExprEquality($tokens, $pos, $file, $line);
            $left = $left & $right;
        }

        return $left;
    }

    private function parseExprEquality(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprRelational($tokens, $pos, $file, $line);

        while ($pos < count($tokens) && ($tokens[$pos]['value'] === '==' || $tokens[$pos]['value'] === '!=')) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = $this->parseExprRelational($tokens, $pos, $file, $line);
            $left = match ($op) {
                '==' => ($left === $right) ? 1 : 0,
                '!=' => ($left !== $right) ? 1 : 0,
            };
        }

        return $left;
    }

    private function parseExprRelational(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprShift($tokens, $pos, $file, $line);

        while ($pos < count($tokens) &&
               ($tokens[$pos]['value'] === '<' || $tokens[$pos]['value'] === '>' ||
                $tokens[$pos]['value'] === '<=' || $tokens[$pos]['value'] === '>=')) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = $this->parseExprShift($tokens, $pos, $file, $line);
            $left = match ($op) {
                '<' => ($left < $right) ? 1 : 0,
                '>' => ($left > $right) ? 1 : 0,
                '<=' => ($left <= $right) ? 1 : 0,
                '>=' => ($left >= $right) ? 1 : 0,
            };
        }

        return $left;
    }

    private function parseExprShift(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprAdditive($tokens, $pos, $file, $line);

        while ($pos < count($tokens) && ($tokens[$pos]['value'] === '<<' || $tokens[$pos]['value'] === '>>')) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = $this->parseExprAdditive($tokens, $pos, $file, $line);
            $left = match ($op) {
                '<<' => $left << $right,
                '>>' => $left >> $right,
            };
        }

        return $left;
    }

    private function parseExprAdditive(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprMultiplicative($tokens, $pos, $file, $line);

        while ($pos < count($tokens) && ($tokens[$pos]['value'] === '+' || $tokens[$pos]['value'] === '-')) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = $this->parseExprMultiplicative($tokens, $pos, $file, $line);
            $left = match ($op) {
                '+' => $left + $right,
                '-' => $left - $right,
            };
        }

        return $left;
    }

    private function parseExprMultiplicative(array &$tokens, int &$pos, string $file, int $line): int
    {
        $left = $this->parseExprUnary($tokens, $pos, $file, $line);

        while ($pos < count($tokens) &&
               ($tokens[$pos]['value'] === '*' || $tokens[$pos]['value'] === '/' || $tokens[$pos]['value'] === '%')) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = $this->parseExprUnary($tokens, $pos, $file, $line);
            $left = match ($op) {
                '*' => $left * $right,
                '/' => $right !== 0 ? intdiv($left, $right) : 0,
                '%' => $right !== 0 ? $left % $right : 0,
            };
        }

        return $left;
    }

    private function parseExprUnary(array &$tokens, int &$pos, string $file, int $line): int
    {
        if ($pos >= count($tokens)) {
            return 0;
        }

        $tok = $tokens[$pos];

        if ($tok['value'] === '!') {
            $pos++;
            $val = $this->parseExprUnary($tokens, $pos, $file, $line);
            return $val === 0 ? 1 : 0;
        }

        if ($tok['value'] === '~') {
            $pos++;
            $val = $this->parseExprUnary($tokens, $pos, $file, $line);
            return ~$val;
        }

        if ($tok['value'] === '-') {
            $pos++;
            $val = $this->parseExprUnary($tokens, $pos, $file, $line);
            return -$val;
        }

        if ($tok['value'] === '+') {
            $pos++;
            return $this->parseExprUnary($tokens, $pos, $file, $line);
        }

        return $this->parseExprPrimary($tokens, $pos, $file, $line);
    }

    private function parseExprPrimary(array &$tokens, int &$pos, string $file, int $line): int
    {
        if ($pos >= count($tokens)) {
            return 0;
        }

        $tok = $tokens[$pos];

        // Number
        if ($tok['type'] === 'num') {
            $pos++;
            return $tok['value'];
        }

        // Parenthesized expression
        if ($tok['value'] === '(') {
            $pos++;
            $val = $this->parseExprTernary($tokens, $pos, $file, $line);
            if ($pos < count($tokens) && $tokens[$pos]['value'] === ')') {
                $pos++;
            }
            return $val;
        }

        // If we get here, skip the token and return 0
        $pos++;
        return 0;
    }

    // ─── Utility Methods ───────────────────────────────────────────────

    private function consumeStringLiteral(string $line, int $pos): array
    {
        $quote = $line[$pos];
        $text = $quote;
        $i = $pos + 1;
        $len = strlen($line);

        while ($i < $len) {
            $ch = $line[$i];
            $text .= $ch;
            $i++;
            if ($ch === '\\' && $i < $len) {
                $text .= $line[$i];
                $i++;
                continue;
            }
            if ($ch === $quote) {
                break;
            }
        }

        return [$text, $i - $pos];
    }

    /**
     * Parse function-like macro call arguments from text.
     * Handles nested parentheses, string literals, and character literals.
     */
    private function parseCallArgs(string $line, int $start): array
    {
        $len = strlen($line);
        $pos = $start + 1; // skip opening '('
        $depth = 1;
        $args = [];
        $cur = '';

        while ($pos < $len && $depth > 0) {
            $ch = $line[$pos];

            if ($ch === '(') {
                $depth++;
                $cur .= $ch;
                $pos++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $args[] = $cur;
                    $pos++; // skip closing ')'
                    break;
                }
                $cur .= $ch;
                $pos++;
            } elseif ($ch === ',' && $depth === 1) {
                $args[] = $cur;
                $cur = '';
                $pos++;
            } elseif ($ch === '"' || $ch === '\'') {
                [$literal, $consumed] = $this->consumeStringLiteral($line, $pos);
                $cur .= $literal;
                $pos += $consumed;
            } else {
                $cur .= $ch;
                $pos++;
            }
        }

        // Handle the case where there are no arguments: FOO()
        if (count($args) === 1 && trim($args[0]) === '') {
            // Check if the macro actually expects arguments
            // If not, return empty array
            // Actually, we should keep this as-is; the caller decides
        }

        return [$args, $pos];
    }

    private function parseDirective(string $trimmedLine): array
    {
        $body = ltrim(substr($trimmedLine, 1));

        // Handle empty directive (#)
        if ($body === '') {
            return ['', ''];
        }

        $spacePos = strcspn($body, " \t");
        $directive = substr($body, 0, $spacePos);
        $rest = ltrim(substr($body, $spacePos));

        return [$directive, $rest];
    }

    /**
     * Find matching closing parenthesis, handling nesting.
     */
    private function findMatchingParen(string $text, int $openPos): int|false
    {
        $len = strlen($text);
        $pos = $openPos + 1;
        $depth = 1;

        while ($pos < $len && $depth > 0) {
            $ch = $text[$pos];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $pos;
                }
            } elseif ($ch === '"' || $ch === '\'') {
                // Skip string/char literals
                $pos++;
                while ($pos < $len && $text[$pos] !== $ch) {
                    if ($text[$pos] === '\\') {
                        $pos++;
                    }
                    $pos++;
                }
            }
            $pos++;
        }

        return false;
    }

    /**
     * Check whether a line has unclosed parentheses (indicating a
     * multi-line macro invocation or expression that continues on
     * the next line).
     */
    private function hasUnclosedParens(string $text): bool
    {
        $depth = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === '"' || $ch === '\'') {
                // Skip string/char literals
                $i++;
                while ($i < $len && $text[$i] !== $ch) {
                    if ($text[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
        }
        return $depth > 0;
    }

    /**
     * Check whether a line ends with an identifier that is a known
     * function-like macro (e.g., __attribute_deprecated_msg__).
     * Used to detect multi-line macro invocations where the name and
     * the '(' are on separate lines.
     */
    private function endsWithFunctionMacro(string $text): bool
    {
        // Find the last identifier on the line
        $len = strlen($text);
        $end = $len;
        // Skip trailing whitespace
        while ($end > 0 && ($text[$end - 1] === ' ' || $text[$end - 1] === "\t")) {
            $end--;
        }
        if ($end === 0) {
            return false;
        }
        // Check if the character before trailing whitespace is an ident char
        $idEnd = $end;
        while ($end > 0 && $this->isIdentContinue($text[$end - 1])) {
            $end--;
        }
        if ($end === $idEnd) {
            return false; // no trailing identifier
        }
        $ident = substr($text, $end, $idEnd - $end);
        // Check if this is a function-like macro
        return isset($this->macros[$ident]) && $this->macros[$ident]['params'] !== null;
    }

    /**
     * Split source into lines using uniform LF.
     * @return string[]
     */
    private function splitLines(string $source): array
    {
        $source = str_replace("\r\n", "\n", $source);
        $source = str_replace("\r", "\n", $source);
        return explode("\n", $source);
    }

    private function isDefined(string $name): bool
    {
        return isset($this->macros[$name]);
    }

    private function isIdentStart(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z')
            || ($ch >= 'A' && $ch <= 'Z')
            || $ch === '_';
    }

    private function isIdentContinue(string $ch): bool
    {
        return $this->isIdentStart($ch) || ($ch >= '0' && $ch <= '9');
    }

    private function isIdentChar(string $ch, bool $isFirst): bool
    {
        if ($isFirst) {
            return $this->isIdentStart($ch);
        }
        return $this->isIdentContinue($ch);
    }
}
