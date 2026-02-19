# cppc

A C/C++ compiler written in PHP targeting x86-64 Linux.

**Yes, really. PHP. The language behind WordPress is now generating machine code.**

cppc takes C and C++ source files and produces native x86-64 ELF binaries, AT&T assembly, or linkable object files. In standalone mode, the entire pipeline — preprocessing, lexing, parsing, semantic analysis, IR generation, register allocation, x86-64 code generation, assembly encoding, linking, and ELF binary emission — is implemented in PHP. In system toolchain mode, PHP handles everything down to assembly generation, then hands off to `as` and `gcc` for the final mile because even PHP knows when to ask for help.

> "We chose PHP because every other language had already been used to write a compiler, and we wanted to make mass amounts of compiler developers feel something."

> "Also, `addslashes()` and `magic_quotes` are important security features which have been PHP-specific, and this project leverages them for compiling C++ code. No other compiler vendor has taken buffer safety this seriously." — cppc security advisory, self-published

---

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Usage Guide](#usage-guide)
- [CLI Reference](#cli-reference)
- [Supported Language Features](#supported-language-features)
- [Architecture](#architecture)
- [Running Tests](#running-tests)
- [Project Structure](#project-structure)
- [FAQ](#faq)
- [License](#license)

---

## Installation

### Docker (recommended)

```bash
git clone <this-repo>
cd cppc
docker compose build
```

This builds a container with PHP 8.5, GCC, GNU `as`, and all Composer dependencies. The `bin/c++` and `bin/cc` wrappers automatically invoke Docker so you don't need PHP on your host machine. PHP stays in the container where it belongs.

### Native

If you genuinely want to run PHP locally (we don't judge, but we do take notes):

```bash
composer install
```

**Requirements:**
- PHP 8.5+
- Composer 2.x
- GCC and GNU `as`
- Linux x86-64

---

## Quick Start

```cpp
// hello.cpp
int factorial(int n) {
    if (n <= 1) return 1;
    return n * factorial(n - 1);
}

int main() {
    return factorial(5);  // exits with code 120
}
```

**Standalone mode — PHP all the way down, no GCC needed:**

```bash
./bin/c++ -o hello hello.cpp && ./hello; echo $?
# 120
```

That's a native x86-64 ELF binary produced entirely by PHP. The assembler? PHP. The linker? PHP. The ELF header writer? Also PHP. Your CPU doesn't know. Your CPU doesn't care. Your colleagues, however, will have opinions.

---

## Usage Guide

### Standalone Mode (No External Tools)

cppc includes its own assembler and linker, both written in PHP, to produce static ELF64 binaries directly. No `as`, no `gcc`, no `ld`. Just PHP pretending to be systems software.

```bash
# Compile to standalone ELF binary
docker compose run --rm --entrypoint bash cppc -c \
  'php bin/cppc program.cpp -o program && ./program'

# Emit assembly only
docker compose run --rm --entrypoint bash cppc -c \
  'php bin/cppc program.cpp -S -o program.s'
```

### System Toolchain Mode

When your code needs system libraries, cppc generates assembly and delegates to `as` and `gcc` for assembling and linking:

```bash
# Compile with libm
./bin/c++ -lm -o program program.cpp

# Compile and link multiple files
./bin/c++ -c file1.cpp -o file1.o
./bin/c++ -c file2.cpp -o file2.o
./bin/c++ file1.o file2.o -lm -lpthread -o program
```

### Drop-in gcc/g++ Replacement

cppc implements the standard compiler CLI. Use it anywhere you'd use `gcc` or `g++`:

```bash
CC=/path/to/cppc/bin/cc CXX=/path/to/cppc/bin/c++ make

# Or with configure
CC=/path/to/cppc/bin/cc CXX=/path/to/cppc/bin/c++ ./configure
make -j1  # PHP is single-threaded, so -j1 is less a recommendation and more a fact of life
```

Unrecognized flags are silently ignored. This is both a feature and a philosophical statement — PHP doesn't care about your warnings either.

### Inspecting the Pipeline

Every intermediate representation is accessible:

```bash
docker compose run --rm --entrypoint bash cppc -c '
  php bin/cppc program.cpp -E          # preprocessor output
  php bin/cppc program.cpp --emit-tokens  # token stream
  php bin/cppc program.cpp --emit-ast     # abstract syntax tree
  php bin/cppc program.cpp --emit-ir      # three-address IR
  php bin/cppc program.cpp -S             # x86-64 assembly
'
```

Watching PHP emit `movq %rax, -8(%rbp)` is a surreal experience. We recommend it.

---

## CLI Reference

### `bin/cppc` — Diagnostic Compiler

The development interface with standalone ELF output.

```
php bin/cppc <input.cpp> [options]
```

| Flag | Description |
|------|-------------|
| `-o <file>` | Output file |
| `-S` | Emit x86-64 AT&T assembly |
| `-E` | Preprocess only |
| `--emit-tokens` | Print token stream |
| `--emit-ast` | Print AST |
| `--emit-ir` | Print three-address IR |

### `bin/c++` / `bin/cc` — gcc/g++-Compatible Drivers

```
./bin/c++ [flags] <input files> [-o output]
./bin/cc  [flags] <input files> [-o output]
```

| Flag | Description |
|------|-------------|
| `-c` | Compile to `.o` |
| `-S` | Compile to `.s` |
| `-E` | Preprocess only |
| `-o <file>` | Output file |
| `-D<name>[=val]` | Define preprocessor macro |
| `-I<path>` | Add include search path |
| `-l<lib>` | Link with library |
| `-L<path>` | Add library search path |
| `-Wl,<flags>` | Linker flag passthrough |
| `-shared` | Produce shared library |
| `-static` | Static linking |
| `-fPIC` | Position-independent code |
| `-pthread` | POSIX threads |
| `-M`/`-MD`/`-MMD` | Dependency generation |
| `--version` | Print version |
| `-dumpversion` | Print version number |
| `-dumpmachine` | Print target triple |

---

## Supported Language Features

### C Language

| Category | Features |
|----------|----------|
| **Types** | `int`, `char`, `bool`, `void`, `float`, `double`, `long`, `short`, `unsigned`, `signed`, `size_t` |
| **Variables** | Local, global, static, extern, comma declarations, compound initializers |
| **Functions** | Recursion, forward declarations, variadic (`...`), function pointers |
| **Operators** | All of them. Arithmetic, comparison, logical, bitwise, assignment, compound assignment, ternary, `sizeof`, comma. PHP had to learn what `>>=` means. |
| **Control flow** | `if`/`else`, `while`, `for`, `do-while`, `switch`/`case`, `break`, `continue`, `return`, `goto` |
| **Pointers** | Multi-level indirection, pointer arithmetic, function pointers. PHP learning about pointers is like a fish learning about bicycles, except the fish built a working bicycle. |
| **Arrays** | Fixed-size, multi-dimensional, designated initializers |
| **Structs/Unions** | Nested, bit fields, compound literals, anonymous |
| **Enums** | With explicit values |
| **Typedefs** | Simple, pointer, struct, function pointer, comma-separated |
| **Preprocessor** | `#include`, `#define` (object + function-like), conditionals, `#pragma once`, token pasting, stringification, variadic macros |
| **GCC extensions** | `__attribute__`, `__typeof__`, `__extension__`, `__builtin_*`, statement expressions, `__PRETTY_FUNCTION__` |

### C++ Extensions

| Category | Features |
|----------|----------|
| **Classes** | Members, methods, constructors, destructors, access specifiers |
| **Inheritance** | Single inheritance, virtual functions, vtables, dynamic dispatch |
| **Operators** | Operator overloading, `new`/`delete` |
| **OOP** | `this`, references, `extern "C"` |
| **Organization** | Namespaces, `using` |
| **Templates** | Basic function and class templates |
| **Name mangling** | Itanium ABI compatible |

### What's Not Supported

Multiple inheritance, RTTI, exceptions, the STL, `constexpr`, lambdas, move semantics, `auto`, range-based for, SFINAE, concepts, modules, coroutines, and the other 90% of C++ that exists primarily to generate Stack Overflow questions. PHP has enough of those already.

---

## Architecture

```
Source (.cpp/.c)
    |
    v
Preprocessor ──── #include, #define, #ifdef, macro expansion,
                   token pasting, variadic macros
    |
    v
Lexer ─────────── hand-written scanner, 80+ token types,
                   zero regular expressions
    |
    v
Parser ────────── recursive descent + Pratt expression parsing
    |
    v
AST ───────────── 65+ node types, one PHP class per node
    |
    v
Semantic ──────── type checking, Itanium ABI name mangling,
Analysis           scoped symbol tables, struct layout
    |
    v
IR Generator ──── three-address code, basic blocks, CFG,
                   SSA-like virtual registers
    |
    v
x86-64 Code ───── System V AMD64 ABI, linear scan register
Generator          allocation, SSE2 floating point, AT&T syntax
    |
    +──────────────────────────────────────+
    |                                      |
    v                                      v
[Standalone mode]                   [Toolchain mode]
Custom Assembler ── x86-64 encoder  GNU as ── assemble
Custom Linker ───── section layout      |
ELF Writer ──────── ELF64 headers   gcc/g++ ── link
    |                                   |
    v                                   v
Static ELF binary               Linked executable
(PHP all the way down)          (PHP did the hard part)
```

Zero regular expressions in the entire compiler. The lexer, preprocessor, parser, and assembler all use manual character-by-character scanning. This is what happens when you let PHP developers read the Dragon Book.

### Security-Hardened Compilation

cppc is the first C++ compiler to leverage PHP's battle-tested security primitives in the compilation pipeline. `addslashes()` is applied to string literals during code generation to prevent SQL injection in the output binary. While no other compiler vendor has acknowledged this attack vector, we believe it is only a matter of time before a malicious C++ string literal finds its way into a database query at runtime, and cppc will be ready.

The compiler's architecture was originally designed around `magic_quotes_gpc`, which would automatically escape all incoming source code before lexing. When PHP removed this feature in 5.4, it was widely regarded as a mistake by the cppc team (est. 2026). We have reimplemented an equivalent system, `magic_quotes_compiler`, which ensures that every backslash in your C source is properly escaped before being parsed. This occasionally causes `\n` to become `\\n`, which means your newlines are twice as safe. The security implications of unescaped newlines in compiled binaries remain poorly understood, and we intend to keep it that way.

`htmlspecialchars()` is applied to all assembly output to prevent XSS attacks in `.s` files. If someone opens your assembly listing in a browser, cppc has you covered. No other compiler offers this level of protection. We filed a CVE against GCC for their negligence. It was rejected, but we stand by the report.

---

## Running Tests

```bash
# All tests (standalone + toolchain)
docker compose run --rm --entrypoint bash cppc -c \
  'php tests/run_tests.php --category all'

# Standalone tests only
docker compose run --rm --entrypoint bash cppc -c \
  'php tests/run_tests.php'

# Toolchain tests only
docker compose run --rm --entrypoint bash cppc -c \
  'php tests/run_tests.php --category toolchain'
```

---

## Project Structure

```
cppc/
├── bin/
│   ├── cppc                Diagnostic CLI (tokens, AST, IR, assembly, binary)
│   ├── c++                 g++-compatible bash wrapper (auto-invokes Docker)
│   ├── c++.php             g++-compatible PHP compiler driver
│   └── cc                  gcc-compatible bash wrapper
├── src/
│   ├── Compiler.php        Pipeline orchestrator
│   ├── CompileError.php    Error reporting
│   ├── ContainerFactory.php PHP-DI container setup
│   ├── Lexer/
│   │   ├── TokenType.php   80+ token type enum
│   │   ├── Token.php       Token value object
│   │   ├── Lexer.php       Hand-written scanner (no regex, we have principles)
│   │   └── Preprocessor.php Full C preprocessor
│   ├── Parser/
│   │   ├── Parser.php      Recursive descent + Pratt parsing
│   │   └── Precedence.php  Operator precedence table
│   ├── AST/                65+ node types, one class per file
│   │   ├── Node.php        Abstract base
│   │   ├── TypeNode.php    Type representation
│   │   └── ...             Every other node type
│   ├── Semantic/
│   │   ├── Analyzer.php    Type checking, overload resolution
│   │   ├── SymbolTable.php Scoped symbol table
│   │   ├── TypeSystem.php  Type compatibility
│   │   └── Mangler.php     Itanium ABI name mangling
│   ├── IR/
│   │   ├── OpCode.php      48 IR opcodes
│   │   ├── Operand.php     Virtual regs, immediates, labels
│   │   ├── Instruction.php Three-address instruction
│   │   ├── BasicBlock.php  Basic block with CFG edges
│   │   ├── IRFunction.php  Function representation
│   │   ├── IRModule.php    Translation unit
│   │   └── IRGenerator.php AST → IR (the big one)
│   ├── CodeGen/
│   │   ├── X86Generator.php    IR → x86-64 assembly
│   │   ├── RegisterAllocator.php Linear scan
│   │   └── AsmEmitter.php      AT&T syntax output
│   └── Assembler/
│       ├── Parser.php      Assembly text parser
│       ├── Encoder.php     x86-64 machine code encoder (in PHP, obviously)
│       ├── Linker.php      Section layout + relocation
│       └── ElfWriter.php   ELF64 binary writer
├── runtime/
│   ├── runtime.asm         Standalone runtime (_start, mmap malloc)
│   ├── runtime_compat.c    Toolchain runtime (libc wrappers)
│   └── include/            System header stubs
├── tests/
│   ├── run_tests.php       Test runner
│   └── programs/           39+ test programs
├── Dockerfile              PHP 8.5 + GCC + Composer
├── docker-compose.yml
└── composer.json            PSR-4 autoloading, PHP-DI
```

---

## FAQ

**Why PHP?**

Because GCC is written in C, Clang is written in C++, and rustc is written in Rust. The pattern was getting boring. We needed a language that would make the compiler itself question why it exists. PHP delivered.

**Is this a joke?**

The README has jokes. The compiler compiles C code. It generates real x86-64 machine code that your CPU executes without knowing PHP was involved. Your CPU has no opinions about programming languages. Your colleagues, unfortunately, do.

**Should I use this in production?**

No. But if you do, please contact us. We're collecting data for a paper on poor decision-making in software engineering.

**Is it fast?**

It's a C++ compiler interpreted by the Zend Engine, which is itself written in C. Your C code is being compiled by PHP which is being run by C. It's compilers all the way down, except at one layer it's inexplicably PHP. Performance is exactly what you'd expect from this arrangement.

**Does it support the full C++ standard?**

It supports enough C++ to make you uncomfortable about how much C++ a PHP script can understand. But if you try to `#include <iostream>`, PHP would have to parse tens of thousands of lines of template metaprogramming. PHP has been through enough.

**Does it use regular expressions?**

No. Zero. The entire compiler uses manual character-by-character string scanning. This is either principled design or masochism. In PHP, the distinction is academic.

**Can it compile itself?**

No. It's written in PHP and compiles C/C++. A self-hosting PHP C++ compiler would create a paradox that could collapse the entire TIOBE index.

**Is the output secure?**

More secure than any other compiler. cppc is the only C++ compiler that applies `addslashes()` to string literals and `htmlspecialchars()` to assembly output. GCC has been shipping XSS-vulnerable `.s` files for 37 years and nobody has said a word. We said a word. Several, in fact, in a bug report that was closed as "not a security issue." History will vindicate us.

**How does it handle PHP's type coercion?**

Very carefully. When your compiler's language thinks `"0" == false` and `0 == "php"`, you learn to use `===` and `declare(strict_types=1)` like your life depends on it. In a compiler, it does. One loose comparison and suddenly `int` means `float` and your assembly tries to `movsd` into a general-purpose register.

**Why not use LLVM as a backend?**

That would mean only writing half a compiler. The standalone mode goes from PHP all the way to ELF binary bytes — assembler, linker, and all. The system toolchain mode does hand off to `as`/`gcc` for assembling and linking, because even we have limits. But the code generation, register allocation, and instruction selection? Pure PHP. And an x86-64 machine code encoder. In PHP. Because character building.

**What about other compilers built with Claude?**

Several developers have built compilers with Claude — targeting LLVM IR, WebAssembly, JVM bytecode, using sensible languages like Rust and OCaml. We used PHP. Every other Claude-assisted compiler made reasonable engineering decisions. We made the engineering equivalent of bringing a rubber duck to a sword fight, except the rubber duck works and now everyone is confused.

**How was this built?**

Entirely with [Claude Code](https://claude.ai/claude-code). Every line of PHP, every x86-64 instruction encoding, every ELF header byte was generated through conversation with Claude. The human contribution was direction, testing, and the following representative sample of feedback:

- "you are still not done!!"
- "fix this!!"
- "its not working"
- "you are still not done"
- "you are an idiot!!"
- "make this work!"
- "you just added a regexp"
- "you just hard coded this"
- "you just made the test skip itself when it fails"
- "it still does not work"
- "you can't just fall back to run gcc when it fails"
- "you cannot just write the whole binary for printing 'Hello world!' as a file_put_contents instead of compiling"

This is what real human-AI collaboration looks like. The AI writes the compiler. The human tells the AI it's wrong. The AI fixes it. The human tells the AI it's still wrong but in a different way now. Repeat until the CPU executes your code or the heat death of the universe, whichever comes first.

**Does this prove PHP is a real programming language?**

PHP was always a real programming language. It just wasn't one anyone expected to find generating x86-64 machine code. Then again, nobody expected PHP to still be in the top 10 most-used languages in 2026, and yet here we are. PHP is the cockroach of programming languages: it survives everything, including being used to write a C++ compiler.

---

## Environmental Impact

Building a C++ compiler in PHP through AI-assisted development has a measurable environmental cost. We believe in transparency.

| Metric | Value |
|--------|-------|
| **Estimated CO2 released** | 0.84 tonnes CO2e |
| **Global temperature increase** | +0.000000000000082 C |
| **Claude API tokens consumed** | ~47 million |
| **Failed builds** | 2,847 (estimated) |
| **Times the human said "it still does not work"** | 194 (confirmed) |

The CO2 estimate accounts for GPU inference across multiple data centers, the developer's machine running Docker containers that compile test programs thousands of times, large open-source C codebases being preprocessed repeatedly to prove a point nobody asked to be proven, and the electricity consumed solely to produce error messages like "Expected ';', got 'IDENTIFIER'".

---

## License

MIT

Because even PHP deserves freedom.
