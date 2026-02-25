<?php

declare(strict_types=1);

namespace Cppc\Assembler;

/**
 * Reads ELF64 ET_REL (relocatable) .o files and returns SectionData[]
 * compatible with the Linker.
 */
class ElfReader
{
    // ELF section types
    private const SHT_NULL     = 0;
    private const SHT_PROGBITS = 1;
    private const SHT_SYMTAB   = 2;
    private const SHT_STRTAB   = 3;
    private const SHT_RELA     = 4;
    private const SHT_NOBITS   = 8;

    // Symbol binding
    private const STB_LOCAL  = 0;
    private const STB_GLOBAL = 1;
    private const STB_WEAK   = 2;

    // Symbol types
    private const STT_NOTYPE  = 0;
    private const STT_OBJECT  = 1;
    private const STT_FUNC    = 2;
    private const STT_SECTION = 3;

    // Special section indices
    private const SHN_UNDEF = 0;
    private const SHN_ABS   = 0xFFF1;

    /**
     * Read an ELF relocatable object file.
     *
     * @return array{sections: SectionData[], globals: string[]}
     */
    public function read(string $elfBytes): array
    {
        // Validate ELF magic
        if (substr($elfBytes, 0, 4) !== "\x7FELF") {
            throw new \RuntimeException('Not a valid ELF file');
        }

        // Validate ELF class (must be 64-bit)
        if (ord($elfBytes[4]) !== 2) {
            throw new \RuntimeException('Not a 64-bit ELF file');
        }

        // Parse ELF header
        $eType    = $this->u16($elfBytes, 16);
        $eShoff   = $this->u64($elfBytes, 40);
        $eShentsize = $this->u16($elfBytes, 58);
        $eShnum   = $this->u16($elfBytes, 60);
        $eShstrndx = $this->u16($elfBytes, 62);

        // Read section headers
        $shdrs = [];
        for ($i = 0; $i < $eShnum; $i++) {
            $off = $eShoff + $i * $eShentsize;
            $shdrs[] = $this->parseSectionHeader($elfBytes, $off);
        }

        // Read section name string table
        $shstrtab = '';
        if ($eShstrndx < $eShnum && $shdrs[$eShstrndx]['type'] === self::SHT_STRTAB) {
            $shstrtab = substr($elfBytes, $shdrs[$eShstrndx]['offset'], $shdrs[$eShstrndx]['size']);
        }

        // Name the sections
        foreach ($shdrs as &$shdr) {
            $shdr['name'] = $this->readString($shstrtab, $shdr['nameIdx']);
        }
        unset($shdr);

        // Find .symtab and .strtab
        $symtabShdr = null;
        $strtab = '';
        $symtabIdx = -1;
        foreach ($shdrs as $idx => $shdr) {
            if ($shdr['type'] === self::SHT_SYMTAB) {
                $symtabShdr = $shdr;
                $symtabIdx = $idx;
                // sh_link points to the associated string table
                $strtabIdx = $shdr['link'];
                if ($strtabIdx < $eShnum) {
                    $strtab = substr($elfBytes, $shdrs[$strtabIdx]['offset'], $shdrs[$strtabIdx]['size']);
                }
                break;
            }
        }

        // Parse symbols
        $symbols = [];
        if ($symtabShdr !== null) {
            $numSyms = intdiv($symtabShdr['size'], 24); // Elf64_Sym = 24 bytes
            for ($i = 0; $i < $numSyms; $i++) {
                $symOff = $symtabShdr['offset'] + $i * 24;
                $symbols[] = $this->parseSymbol($elfBytes, $symOff, $strtab);
            }
        }

        // Build section name → index map for symbol resolution
        $sectionNameByIdx = [];
        foreach ($shdrs as $idx => $shdr) {
            $sectionNameByIdx[$idx] = $shdr['name'];
        }

        // Parse PROGBITS/NOBITS sections into SectionData
        $sectionDataMap = [];
        $interestingSections = ['.text', '.rodata', '.data', '.bss'];

        foreach ($shdrs as $idx => $shdr) {
            $name = $shdr['name'];
            if (!in_array($name, $interestingSections, true)) {
                continue;
            }

            $sd = new SectionData($name);
            if ($shdr['type'] === self::SHT_NOBITS) {
                $sd->bytes = str_repeat("\0", $shdr['size']);
            } else {
                $sd->bytes = substr($elfBytes, $shdr['offset'], $shdr['size']);
            }
            $sd->align = max(1, $shdr['addralign']);
            $sectionDataMap[$name] = $sd;
        }

        // Ensure all standard sections exist
        foreach ($interestingSections as $name) {
            if (!isset($sectionDataMap[$name])) {
                $sectionDataMap[$name] = new SectionData($name);
            }
        }

        // Assign symbols to sections
        $globals = [];
        foreach ($symbols as $sym) {
            if ($sym['name'] === '' || $sym['shndx'] === self::SHN_UNDEF) {
                continue;
            }
            if ($sym['sttType'] === self::STT_SECTION) {
                continue; // Skip section symbols
            }

            $secName = $sectionNameByIdx[$sym['shndx']] ?? null;
            if ($secName === null || !isset($sectionDataMap[$secName])) {
                continue;
            }

            $binding = $sym['binding'];
            $isGlobal = ($binding === self::STB_GLOBAL || $binding === self::STB_WEAK);

            $typeStr = match ($sym['sttType']) {
                self::STT_FUNC => 'func',
                self::STT_OBJECT => 'object',
                default => 'notype',
            };

            $sectionDataMap[$secName]->symbols[] = new Symbol(
                $sym['name'],
                $secName,
                $sym['value'],
                $isGlobal,
                $typeStr,
                $sym['size'],
            );

            if ($isGlobal) {
                $globals[] = $sym['name'];
            }
        }

        // Parse RELA sections and assign relocations
        foreach ($shdrs as $shdr) {
            if ($shdr['type'] !== self::SHT_RELA) {
                continue;
            }

            // sh_info points to the section being relocated
            $targetSecIdx = $shdr['info'];
            $targetSecName = $sectionNameByIdx[$targetSecIdx] ?? null;
            if ($targetSecName === null || !isset($sectionDataMap[$targetSecName])) {
                continue;
            }

            $numRelas = intdiv($shdr['size'], 24); // Elf64_Rela = 24 bytes
            for ($i = 0; $i < $numRelas; $i++) {
                $relaOff = $shdr['offset'] + $i * 24;
                $rela = $this->parseRela($elfBytes, $relaOff, $symbols, $sectionNameByIdx);
                if ($rela !== null) {
                    $rela->section = $targetSecName;
                    $sectionDataMap[$targetSecName]->relocs[] = $rela;
                }
            }
        }

        return [
            'sections' => array_values($sectionDataMap),
            'globals' => $globals,
        ];
    }

    /**
     * Read an ELF relocatable object from a file.
     *
     * @return array{sections: SectionData[], globals: string[]}
     */
    public function readFile(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }
        return $this->read($bytes);
    }

    private function parseSectionHeader(string $data, int $off): array
    {
        return [
            'nameIdx'   => $this->u32($data, $off),
            'type'      => $this->u32($data, $off + 4),
            'flags'     => $this->u64($data, $off + 8),
            'addr'      => $this->u64($data, $off + 16),
            'offset'    => $this->u64($data, $off + 24),
            'size'      => $this->u64($data, $off + 32),
            'link'      => $this->u32($data, $off + 40),
            'info'      => $this->u32($data, $off + 44),
            'addralign' => $this->u64($data, $off + 48),
            'entsize'   => $this->u64($data, $off + 56),
        ];
    }

    private function parseSymbol(string $data, int $off, string $strtab): array
    {
        $stName  = $this->u32($data, $off);
        $stInfo  = ord($data[$off + 4]);
        $stOther = ord($data[$off + 5]);
        $stShndx = $this->u16($data, $off + 6);
        $stValue = $this->u64($data, $off + 8);
        $stSize  = $this->u64($data, $off + 16);

        $name = $this->readString($strtab, $stName);

        // Strip symbol versioning suffix (e.g., "printf@@GLIBC_2.2.5" → "printf")
        $atPos = strpos($name, '@');
        if ($atPos !== false) {
            $name = substr($name, 0, $atPos);
        }

        return [
            'name'    => $name,
            'binding' => ($stInfo >> 4) & 0xF,
            'sttType' => $stInfo & 0xF,
            'other'   => $stOther,
            'shndx'   => $stShndx,
            'value'   => $stValue,
            'size'    => $stSize,
        ];
    }

    /**
     * @param array<int, string> $sectionNameByIdx
     */
    private function parseRela(string $data, int $off, array $symbols, array $sectionNameByIdx): ?Relocation
    {
        $rOffset = $this->u64($data, $off);
        $rInfo   = $this->u64($data, $off + 8);
        $rAddend = $this->s64($data, $off + 16);

        $symIdx  = ($rInfo >> 32) & 0xFFFFFFFF;
        $elfType = $rInfo & 0xFFFFFFFF;

        // Convert ELF relocation type to internal type
        $type = match ($elfType) {
            Relocation::R_X86_64_PC32     => 'REL32',
            Relocation::R_X86_64_GOTPCRELX    => 'GOTPCREL', // relaxable GOTPCREL
            Relocation::R_X86_64_REX_GOTPCRELX => 'GOTPCREL', // relaxable GOTPCREL with REX
            Relocation::R_X86_64_PLT32    => 'REL32', // PLT32 treated as REL32 for non-PIC linking
            Relocation::R_X86_64_64       => 'ABS64',
            Relocation::R_X86_64_32S      => '32S',
            Relocation::R_X86_64_GOTPCREL => 'GOTPCREL',
            default => null,
        };

        if ($type === null) {
            return null; // Skip unsupported relocation types
        }

        // Resolve symbol name
        $targetName = '';
        if ($symIdx < count($symbols)) {
            $sym = $symbols[$symIdx];
            if ($sym['sttType'] === self::STT_SECTION) {
                // Section symbol — use the section name
                $targetName = $sectionNameByIdx[$sym['shndx']] ?? '';
            } else {
                $targetName = $sym['name'];
            }
        }

        if ($targetName === '') {
            return null;
        }

        // For PC32/PLT32, ELF addend includes -4, but our internal format uses addend=0
        // (the Encoder/Linker internally does target - (patch + 4))
        $addend = $rAddend;
        if ($type === 'REL32') {
            $addend = $rAddend + 4; // Convert from ELF convention back to our internal convention
        }

        return new Relocation('', $rOffset, $type, $targetName, $addend);
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

    private function s64(string $data, int $off): int
    {
        // Read as unsigned, then convert to signed
        $val = unpack('P', $data, $off)[1];
        // PHP integers are already signed 64-bit on 64-bit platforms
        return $val;
    }
}
