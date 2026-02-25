<?php

declare(strict_types=1);

namespace Cppc\Assembler;

/**
 * Reads exported symbols from ELF shared libraries (.so files).
 * Parses .dynsym (SHT_DYNSYM=11) + .dynstr to get exported symbol names.
 */
class DynSymReader
{
    private const SHT_DYNSYM = 11;
    private const SHT_STRTAB = 3;

    // Symbol binding
    private const STB_GLOBAL = 1;
    private const STB_WEAK   = 2;

    // Special section indices
    private const SHN_UNDEF = 0;

    /**
     * Read exported symbols from a shared library.
     *
     * @return string[] list of exported symbol names
     */
    public function read(string $elfBytes): array
    {
        // Validate ELF magic
        if (substr($elfBytes, 0, 4) !== "\x7FELF") {
            throw new \RuntimeException('Not a valid ELF file');
        }

        // Parse ELF header
        $eShoff     = $this->u64($elfBytes, 40);
        $eShentsize = $this->u16($elfBytes, 58);
        $eShnum     = $this->u16($elfBytes, 60);

        // Read section headers
        $shdrs = [];
        for ($i = 0; $i < $eShnum; $i++) {
            $off = $eShoff + $i * $eShentsize;
            $shdrs[] = [
                'type'   => $this->u32($elfBytes, $off + 4),
                'offset' => $this->u64($elfBytes, $off + 24),
                'size'   => $this->u64($elfBytes, $off + 32),
                'link'   => $this->u32($elfBytes, $off + 40),
            ];
        }

        // Find .dynsym section
        $dynsymShdr = null;
        foreach ($shdrs as $shdr) {
            if ($shdr['type'] === self::SHT_DYNSYM) {
                $dynsymShdr = $shdr;
                break;
            }
        }

        if ($dynsymShdr === null) {
            return []; // No dynamic symbols
        }

        // Read associated string table
        $dynstrIdx = $dynsymShdr['link'];
        if ($dynstrIdx >= count($shdrs)) {
            return [];
        }
        $dynstr = substr($elfBytes, $shdrs[$dynstrIdx]['offset'], $shdrs[$dynstrIdx]['size']);

        // Parse symbols
        $exports = [];
        $numSyms = intdiv($dynsymShdr['size'], 24);
        for ($i = 0; $i < $numSyms; $i++) {
            $symOff = $dynsymShdr['offset'] + $i * 24;

            $stName  = $this->u32($elfBytes, $symOff);
            $stInfo  = ord($elfBytes[$symOff + 4]);
            $stShndx = $this->u16($elfBytes, $symOff + 6);

            $binding = ($stInfo >> 4) & 0xF;

            // Skip undefined symbols and local symbols
            if ($stShndx === self::SHN_UNDEF) {
                continue;
            }
            if ($binding !== self::STB_GLOBAL && $binding !== self::STB_WEAK) {
                continue;
            }

            $name = $this->readString($dynstr, $stName);
            if ($name === '') {
                continue;
            }

            // Strip version suffix (e.g., "printf@@GLIBC_2.2.5" → "printf")
            $atPos = strpos($name, '@');
            if ($atPos !== false) {
                $name = substr($name, 0, $atPos);
            }

            $exports[] = $name;
        }

        return $exports;
    }

    /**
     * Read exported symbols from a shared library file.
     * Handles GNU ld scripts that reference the actual .so file.
     *
     * @return string[]
     */
    public function readFile(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read shared library: {$path}");
        }

        // Check if this is a GNU ld script instead of an ELF file
        if (str_starts_with($bytes, '/*') || str_starts_with($bytes, 'OUTPUT_FORMAT')
            || str_starts_with($bytes, 'GROUP')) {
            $realPath = $this->parseLinkerScript($bytes, dirname($path));
            if ($realPath !== null) {
                return $this->readFile($realPath);
            }
            return []; // Cannot parse script
        }

        return $this->read($bytes);
    }

    /**
     * Parse a GNU ld linker script to find the actual shared library path.
     * Handles: GROUP ( /path/to/lib.so.6 ... )
     */
    private function parseLinkerScript(string $script, string $baseDir): ?string
    {
        // Look for GROUP ( ... ) pattern and extract .so paths
        if (preg_match('/GROUP\s*\(\s*([^\)]+)\)/', $script, $m)) {
            $content = $m[1];
            // Extract file paths (skip AS_NEEDED and keywords)
            if (preg_match_all('/\/[^\s\)]+\.so[^\s\)]*/', $content, $paths)) {
                foreach ($paths[0] as $soPath) {
                    if (file_exists($soPath) && !str_ends_with($soPath, '.a')) {
                        // Verify it's actually an ELF file
                        $header = file_get_contents($soPath, false, null, 0, 4);
                        if ($header === "\x7FELF") {
                            return $soPath;
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * Find a shared library by name in standard search paths.
     *
     * @param string $libName  e.g. "m", "pthread", "png16"
     * @param string[] $extraPaths  additional search directories from -L flags
     * @return string|null  full path to .so file, or null if not found
     */
    public function findLibrary(string $libName, array $extraPaths = []): ?string
    {
        $searchPaths = array_merge($extraPaths, [
            '/usr/lib/x86_64-linux-gnu',
            '/lib/x86_64-linux-gnu',
            '/usr/lib64',
            '/lib64',
            '/usr/lib',
            '/lib',
        ]);

        foreach ($searchPaths as $dir) {
            // Try versioned .so.N first (these are always real ELF files)
            if (is_dir($dir)) {
                $files = @scandir($dir);
                if ($files !== false) {
                    foreach ($files as $file) {
                        if (str_starts_with($file, "lib{$libName}.so.")) {
                            $path = "{$dir}/{$file}";
                            $real = realpath($path);
                            return $real !== false ? $real : $path;
                        }
                    }
                }
            }

            // Fall back to unversioned .so (may be a linker script)
            $soPath = "{$dir}/lib{$libName}.so";
            if (file_exists($soPath)) {
                // Check if it's a linker script
                $header = file_get_contents($soPath, false, null, 0, 4);
                if ($header === "\x7FELF") {
                    $real = realpath($soPath);
                    return $real !== false ? $real : $soPath;
                }
                // It's a linker script — try to resolve it
                $script = file_get_contents($soPath);
                $resolved = $this->parseLinkerScript($script, $dir);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function readString(string $strtab, int $offset): string
    {
        if ($offset >= strlen($strtab)) {
            return '';
        }
        $end = strpos($strtab, "\0", $offset);
        if ($end === false) {
            return substr($strtab, $offset);
        }
        return substr($strtab, $offset, $end - $offset);
    }

    private function u16(string $data, int $off): int
    {
        return unpack('v', $data, $off)[1];
    }

    private function u32(string $data, int $off): int
    {
        return unpack('V', $data, $off)[1];
    }

    private function u64(string $data, int $off): int
    {
        return unpack('P', $data, $off)[1];
    }
}
