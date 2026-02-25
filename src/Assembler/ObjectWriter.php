<?php

declare(strict_types=1);

namespace Cppc\Assembler;

/**
 * Writes ELF64 ET_REL (relocatable) object files from SectionData.
 *
 * Layout:
 *   [ELF header, 64 bytes]
 *   [.text bytes]
 *   [.rodata bytes]
 *   [.data bytes]
 *   [.rela.text]
 *   [.rela.rodata]
 *   [.rela.data]
 *   [.symtab]
 *   [.strtab]
 *   [.shstrtab]
 *   [Section header table]
 */
class ObjectWriter
{
    private const ELF_HEADER_SZ = 64;
    private const SHDR_SZ       = 64;
    private const SYM_ENTRY_SZ  = 24;
    private const RELA_ENTRY_SZ = 24;

    // ELF section types
    private const SHT_NULL     = 0;
    private const SHT_PROGBITS = 1;
    private const SHT_SYMTAB   = 2;
    private const SHT_STRTAB   = 3;
    private const SHT_RELA     = 4;
    private const SHT_NOBITS   = 8;

    // ELF section flags
    private const SHF_WRITE     = 1;
    private const SHF_ALLOC     = 2;
    private const SHF_EXECINSTR = 4;

    // Symbol binding
    private const STB_LOCAL  = 0;
    private const STB_GLOBAL = 1;

    // Symbol types
    private const STT_NOTYPE  = 0;
    private const STT_OBJECT  = 1;
    private const STT_FUNC    = 2;
    private const STT_SECTION = 3;

    // Special section indices
    private const SHN_UNDEF = 0;
    private const SHN_ABS   = 0xFFF1;

    /**
     * Write an ELF relocatable object file.
     *
     * @param SectionData[] $sections  Encoded sections (keyed by name or indexed)
     * @param string[]      $globals   List of global symbol names
     * @return string Raw ELF binary bytes
     */
    public function write(array $sections, array $globals): string
    {
        // Normalize sections by name
        $sectionsByName = [];
        foreach ($sections as $sec) {
            $sectionsByName[$sec->name] = $sec;
        }

        // Ensure all standard sections exist
        foreach (['.text', '.rodata', '.data', '.bss'] as $name) {
            if (!isset($sectionsByName[$name])) {
                $sectionsByName[$name] = new SectionData($name);
            }
        }

        $textBytes   = $sectionsByName['.text']->bytes;
        $rodataBytes = $sectionsByName['.rodata']->bytes;
        $dataBytes   = $sectionsByName['.data']->bytes;
        $bssSize     = strlen($sectionsByName['.bss']->bytes);

        // Mark global symbols
        $globalSet = array_flip($globals);

        // Collect all defined symbols from all sections
        $allSymbols = [];
        foreach ($sectionsByName as $sec) {
            foreach ($sec->symbols as $sym) {
                $sym->global = isset($globalSet[$sym->name]);
                $allSymbols[] = $sym;
            }
        }

        // Collect all relocations from all sections, find undefined externals
        $definedNames = [];
        foreach ($allSymbols as $sym) {
            $definedNames[$sym->name] = true;
        }

        $allRelocs = [];
        $undefinedExternals = [];
        foreach ($sectionsByName as $sec) {
            foreach ($sec->relocs as $reloc) {
                $allRelocs[] = $reloc;
                if (!isset($definedNames[$reloc->target])) {
                    $undefinedExternals[$reloc->target] = true;
                }
            }
        }

        // Section index mapping (fixed order)
        // 0: SHT_NULL
        // 1: .text
        // 2: .rodata
        // 3: .data
        // 4: .bss
        $sectionIndexMap = [
            '.text'   => 1,
            '.rodata' => 2,
            '.data'   => 3,
            '.bss'    => 4,
        ];

        // Build rela sections (only for sections with relocations)
        $textRelocs   = $this->getSectionRelocs($sectionsByName['.text']);
        $rodataRelocs = $this->getSectionRelocs($sectionsByName['.rodata']);
        $dataRelocs   = $this->getSectionRelocs($sectionsByName['.data']);

        // Determine section header indices dynamically
        $shdrIndex = 5; // after null, .text, .rodata, .data, .bss
        $relaTextIdx = -1;
        $relaRodataIdx = -1;
        $relaDataIdx = -1;

        if ($textRelocs !== []) {
            $relaTextIdx = $shdrIndex++;
        }
        if ($rodataRelocs !== []) {
            $relaRodataIdx = $shdrIndex++;
        }
        if ($dataRelocs !== []) {
            $relaDataIdx = $shdrIndex++;
        }

        $symtabIdx  = $shdrIndex++;
        $strtabIdx  = $shdrIndex++;
        $shstrtabIdx = $shdrIndex++;
        $totalShdrs = $shdrIndex;

        // Build symbol table
        // Order: null entry, section symbols (local), local defined symbols, then globals, then undefined externals
        $symEntries = [];
        $strtab = "\0"; // start with null byte

        // Null symbol entry
        $symEntries[] = $this->symEntry(0, 0, 0, 0, self::SHN_UNDEF);

        // Section symbols (one per content section: .text, .rodata, .data, .bss)
        foreach ($sectionIndexMap as $secName => $secIdx) {
            $nameOffset = strlen($strtab);
            $strtab .= $secName . "\0";
            $stInfo = (self::STB_LOCAL << 4) | self::STT_SECTION;
            $symEntries[] = $this->symEntry($nameOffset, $stInfo, 0, 0, $secIdx);
        }

        // Local defined symbols
        $symNameToIndex = [];
        foreach ($allSymbols as $sym) {
            if ($sym->global) {
                continue;
            }
            $nameOffset = strlen($strtab);
            $strtab .= $sym->name . "\0";
            $secIdx = $sectionIndexMap[$sym->section] ?? self::SHN_ABS;
            $symNameToIndex[$sym->name] = count($symEntries);
            $symEntries[] = $this->symEntry($nameOffset, $sym->elfStInfo(), $sym->offset, $sym->size, $secIdx);
        }

        // Record first global index
        $firstGlobalIdx = count($symEntries);

        // Global defined symbols
        foreach ($allSymbols as $sym) {
            if (!$sym->global) {
                continue;
            }
            $nameOffset = strlen($strtab);
            $strtab .= $sym->name . "\0";
            $secIdx = $sectionIndexMap[$sym->section] ?? self::SHN_ABS;
            $symNameToIndex[$sym->name] = count($symEntries);
            $symEntries[] = $this->symEntry($nameOffset, $sym->elfStInfo(), $sym->offset, $sym->size, $secIdx);
        }

        // Undefined external symbols (always global)
        foreach ($undefinedExternals as $name => $_) {
            $nameOffset = strlen($strtab);
            $strtab .= $name . "\0";
            $stInfo = (self::STB_GLOBAL << 4) | self::STT_NOTYPE;
            $symNameToIndex[$name] = count($symEntries);
            $symEntries[] = $this->symEntry($nameOffset, $stInfo, 0, 0, self::SHN_UNDEF);
        }

        // Build rela entries
        $relaTextBytes   = $this->buildRelaBytes($textRelocs, $symNameToIndex, $sectionIndexMap);
        $relaRodataBytes = $this->buildRelaBytes($rodataRelocs, $symNameToIndex, $sectionIndexMap);
        $relaDataBytes   = $this->buildRelaBytes($dataRelocs, $symNameToIndex, $sectionIndexMap);

        // Build symtab bytes
        $symtabBytes = '';
        foreach ($symEntries as $entry) {
            $symtabBytes .= $entry;
        }

        // Build shstrtab
        $shstrtab = "\0";
        $shstrtabOffsets = [];

        $shstrtabNames = ['.text', '.rodata', '.data', '.bss'];
        if ($relaTextIdx >= 0) $shstrtabNames[] = '.rela.text';
        if ($relaRodataIdx >= 0) $shstrtabNames[] = '.rela.rodata';
        if ($relaDataIdx >= 0) $shstrtabNames[] = '.rela.data';
        $shstrtabNames[] = '.symtab';
        $shstrtabNames[] = '.strtab';
        $shstrtabNames[] = '.shstrtab';

        foreach ($shstrtabNames as $name) {
            $shstrtabOffsets[$name] = strlen($shstrtab);
            $shstrtab .= $name . "\0";
        }

        // Compute file layout
        $offset = self::ELF_HEADER_SZ;

        // .text
        $textOff = $offset;
        $offset += strlen($textBytes);

        // .rodata
        $rodataOff = $offset;
        $offset += strlen($rodataBytes);

        // .data
        $dataOff = $offset;
        $offset += strlen($dataBytes);

        // .bss has no file content
        $bssOff = $offset;

        // .rela.text
        $relaTextOff = $offset;
        $offset += strlen($relaTextBytes);

        // .rela.rodata
        $relaRodataOff = $offset;
        $offset += strlen($relaRodataBytes);

        // .rela.data
        $relaDataOff = $offset;
        $offset += strlen($relaDataBytes);

        // .symtab (align to 8)
        $offset = $this->alignUp($offset, 8);
        $symtabOff = $offset;
        $offset += strlen($symtabBytes);

        // .strtab
        $strtabOff = $offset;
        $offset += strlen($strtab);

        // .shstrtab
        $shstrtabOff = $offset;
        $offset += strlen($shstrtab);

        // Section headers (align to 8)
        $offset = $this->alignUp($offset, 8);
        $shdrOff = $offset;

        // Build the binary
        $bin = '';

        // ELF header
        $bin .= $this->elfHeader($shdrOff, $totalShdrs, $shstrtabIdx);

        // .text
        $bin .= $textBytes;

        // .rodata
        $bin .= $rodataBytes;

        // .data
        $bin .= $dataBytes;

        // .rela.*
        $bin .= $relaTextBytes;
        $bin .= $relaRodataBytes;
        $bin .= $relaDataBytes;

        // Alignment padding before .symtab
        $padLen = $symtabOff - strlen($bin);
        if ($padLen > 0) {
            $bin .= str_repeat("\0", $padLen);
        }

        // .symtab
        $bin .= $symtabBytes;

        // .strtab
        $bin .= $strtab;

        // .shstrtab
        $bin .= $shstrtab;

        // Alignment padding before section headers
        $padLen = $shdrOff - strlen($bin);
        if ($padLen > 0) {
            $bin .= str_repeat("\0", $padLen);
        }

        // Section headers
        // 0: SHT_NULL
        $bin .= $this->shdr(0, self::SHT_NULL, 0, 0, 0, 0, 0, 0, 0, 0);

        // 1: .text
        $bin .= $this->shdr(
            $shstrtabOffsets['.text'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_EXECINSTR,
            0, $textOff, strlen($textBytes),
            0, 0, 16, 0
        );

        // 2: .rodata
        $bin .= $this->shdr(
            $shstrtabOffsets['.rodata'], self::SHT_PROGBITS,
            self::SHF_ALLOC,
            0, $rodataOff, strlen($rodataBytes),
            0, 0, 1, 0
        );

        // 3: .data
        $bin .= $this->shdr(
            $shstrtabOffsets['.data'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            0, $dataOff, strlen($dataBytes),
            0, 0, 8, 0
        );

        // 4: .bss
        $bin .= $this->shdr(
            $shstrtabOffsets['.bss'], self::SHT_NOBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            0, $bssOff, $bssSize,
            0, 0, 8, 0
        );

        // .rela.text
        if ($relaTextIdx >= 0) {
            $bin .= $this->shdr(
                $shstrtabOffsets['.rela.text'], self::SHT_RELA,
                0,
                0, $relaTextOff, strlen($relaTextBytes),
                $symtabIdx, $sectionIndexMap['.text'],
                8, self::RELA_ENTRY_SZ
            );
        }

        // .rela.rodata
        if ($relaRodataIdx >= 0) {
            $bin .= $this->shdr(
                $shstrtabOffsets['.rela.rodata'], self::SHT_RELA,
                0,
                0, $relaRodataOff, strlen($relaRodataBytes),
                $symtabIdx, $sectionIndexMap['.rodata'],
                8, self::RELA_ENTRY_SZ
            );
        }

        // .rela.data
        if ($relaDataIdx >= 0) {
            $bin .= $this->shdr(
                $shstrtabOffsets['.rela.data'], self::SHT_RELA,
                0,
                0, $relaDataOff, strlen($relaDataBytes),
                $symtabIdx, $sectionIndexMap['.data'],
                8, self::RELA_ENTRY_SZ
            );
        }

        // .symtab
        $bin .= $this->shdr(
            $shstrtabOffsets['.symtab'], self::SHT_SYMTAB,
            0,
            0, $symtabOff, strlen($symtabBytes),
            $strtabIdx, $firstGlobalIdx,
            8, self::SYM_ENTRY_SZ
        );

        // .strtab
        $bin .= $this->shdr(
            $shstrtabOffsets['.strtab'], self::SHT_STRTAB,
            0,
            0, $strtabOff, strlen($strtab),
            0, 0, 1, 0
        );

        // .shstrtab
        $bin .= $this->shdr(
            $shstrtabOffsets['.shstrtab'], self::SHT_STRTAB,
            0,
            0, $shstrtabOff, strlen($shstrtab),
            0, 0, 1, 0
        );

        return $bin;
    }

    /**
     * Get relocations for a specific section.
     * @return Relocation[]
     */
    private function getSectionRelocs(SectionData $section): array
    {
        return $section->relocs;
    }

    /**
     * Build .rela bytes for a set of relocations.
     *
     * @param Relocation[] $relocs
     * @param array<string, int> $symNameToIndex
     * @param array<string, int> $sectionIndexMap
     */
    private function buildRelaBytes(array $relocs, array $symNameToIndex, array $sectionIndexMap): string
    {
        $bytes = '';
        foreach ($relocs as $reloc) {
            // Find symbol index for the relocation target
            $symIdx = $symNameToIndex[$reloc->target]
                ?? throw new \RuntimeException("Relocation target not in symbol table: {$reloc->target}");

            $elfType = $reloc->elfType();

            // For PC32 relocations, ELF convention requires addend = -4
            // because S + A - P, and the 4-byte field is at the patch site
            $addend = $reloc->addend;
            if ($elfType === Relocation::R_X86_64_PC32) {
                $addend = $reloc->addend - 4;
            }

            // r_info = (sym_idx << 32) | type
            $rInfo = ($symIdx << 32) | $elfType;

            // Elf64_Rela: r_offset(8) | r_info(8) | r_addend(8, signed)
            $bytes .= pack('P', $reloc->offset);  // r_offset
            $bytes .= pack('P', $rInfo);           // r_info
            $bytes .= pack('P', $this->signedToUnsigned64($addend)); // r_addend (signed)
        }
        return $bytes;
    }

    /**
     * Build a single Elf64_Sym entry (24 bytes).
     */
    private function symEntry(int $name, int $info, int $value, int $size, int $shndx): string
    {
        $entry = '';
        $entry .= pack('V', $name);   // st_name (4)
        $entry .= chr($info & 0xFF);  // st_info (1)
        $entry .= "\0";               // st_other (1)
        $entry .= pack('v', $shndx);  // st_shndx (2)
        $entry .= pack('P', $value);  // st_value (8)
        $entry .= pack('P', $size);   // st_size (8)
        return $entry;
    }

    /**
     * Build ELF64 header for ET_REL.
     */
    private function elfHeader(int $shoff, int $shnum, int $shstrndx): string
    {
        $h = '';
        // e_ident (16 bytes)
        $h .= "\x7FELF";                 // magic
        $h .= "\x02";                    // class: 64-bit
        $h .= "\x01";                    // data: little-endian
        $h .= "\x01";                    // version: current
        $h .= "\x00";                    // OS/ABI: System V
        $h .= str_repeat("\x00", 8);     // padding
        // e_type (2): ET_REL = 1
        $h .= pack('v', 1);
        // e_machine (2): EM_X86_64 = 0x3E
        $h .= pack('v', 0x3E);
        // e_version (4)
        $h .= pack('V', 1);
        // e_entry (8): 0 for relocatable
        $h .= pack('P', 0);
        // e_phoff (8): 0 for relocatable (no program headers)
        $h .= pack('P', 0);
        // e_shoff (8)
        $h .= pack('P', $shoff);
        // e_flags (4)
        $h .= pack('V', 0);
        // e_ehsize (2)
        $h .= pack('v', self::ELF_HEADER_SZ);
        // e_phentsize (2)
        $h .= pack('v', 0);
        // e_phnum (2)
        $h .= pack('v', 0);
        // e_shentsize (2)
        $h .= pack('v', self::SHDR_SZ);
        // e_shnum (2)
        $h .= pack('v', $shnum);
        // e_shstrndx (2)
        $h .= pack('v', $shstrndx);

        return $h;
    }

    /**
     * Build a single Elf64_Shdr entry (64 bytes).
     */
    private function shdr(int $name, int $type, int $flags, int $addr,
                          int $offset, int $size, int $link, int $info,
                          int $addralign, int $entsize): string
    {
        $s = '';
        $s .= pack('V', $name);       // sh_name
        $s .= pack('V', $type);       // sh_type
        $s .= pack('P', $flags);      // sh_flags
        $s .= pack('P', $addr);       // sh_addr
        $s .= pack('P', $offset);     // sh_offset
        $s .= pack('P', $size);       // sh_size
        $s .= pack('V', $link);       // sh_link
        $s .= pack('V', $info);       // sh_info
        $s .= pack('P', $addralign);  // sh_addralign
        $s .= pack('P', $entsize);    // sh_entsize
        return $s;
    }

    private function alignUp(int $value, int $align): int
    {
        return ($value + $align - 1) & ~($align - 1);
    }

    /**
     * Convert a signed 64-bit value to unsigned for pack('P').
     */
    private function signedToUnsigned64(int $val): int
    {
        return $val; // PHP's pack('P') handles this correctly for negative values
    }
}
