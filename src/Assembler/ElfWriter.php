<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class ElfWriter
{
    private const BASE_ADDR     = 0x400000;
    private const PAGE_SIZE     = 0x1000;
    private const ELF_HEADER_SZ = 64;
    private const PHDR_SZ       = 56;
    private const SHDR_SZ       = 64;

    // ELF constants
    private const PT_LOAD   = 1;
    private const PT_NULL   = 0;
    private const PF_X      = 1;
    private const PF_W      = 2;
    private const PF_R      = 4;
    private const SHT_NULL     = 0;
    private const SHT_PROGBITS = 1;
    private const SHT_NOBITS   = 8;
    private const SHT_STRTAB   = 3;
    private const SHF_WRITE    = 1;
    private const SHF_ALLOC    = 2;
    private const SHF_EXEC     = 4;

    /**
     * Build a static ELF64 executable.
     *
     * @param array<string, SectionData> $sections keyed by name (.text, .rodata, .data, .bss)
     * @param int $entryAddr virtual address of _start
     * @param array<string, int> $sectionVAddrs
     * @return string raw ELF binary bytes
     */
    public function build(array $sections, int $entryAddr, array $sectionVAddrs): string
    {
        $textBytes   = $sections['.text']->bytes   ?? '';
        $rodataBytes = $sections['.rodata']->bytes ?? '';
        $dataBytes   = $sections['.data']->bytes   ?? '';
        $bssSize     = strlen($sections['.bss']->bytes ?? '');

        // Layout:
        // 0x0000: ELF header (64 bytes)
        // 0x0040: Program headers (2 × 56 = 112 bytes)
        // padding to page boundary (0x1000)
        // 0x1000: .text
        //         .rodata (immediately after .text)
        // next page: .data
        //            .bss (virtual only)
        // After file content: section headers + .shstrtab

        $numPhdrs = 2;
        $headerSize = self::ELF_HEADER_SZ + $numPhdrs * self::PHDR_SZ;

        // Segment 1 (RX): .text + .rodata
        $seg1FileOff = self::PAGE_SIZE; // page-aligned
        $seg1VAddr   = self::BASE_ADDR + $seg1FileOff;
        $seg1Content = $textBytes . $rodataBytes;
        $seg1FileSz  = strlen($seg1Content);
        $seg1MemSz   = $seg1FileSz;

        // Segment 2 (RW): .data + .bss
        $seg2FileOff = $seg1FileOff + $this->alignUp($seg1FileSz, self::PAGE_SIZE);
        $seg2VAddr   = self::BASE_ADDR + $seg2FileOff;
        $seg2Content = $dataBytes;
        $seg2FileSz  = strlen($seg2Content);
        $seg2MemSz   = $seg2FileSz + $bssSize;

        // Build section header string table
        $shstrtab = $this->buildShstrtab();
        $shstrtabNames = $this->shstrtabOffsets($shstrtab);

        // Section headers go after all file content
        $shOff = $seg2FileOff + $seg2FileSz;
        // Add .shstrtab content
        $shstrtabOff = $shOff;
        $shOff = $shstrtabOff + strlen($shstrtab);
        // Align section headers
        $shOff = $this->alignUp($shOff, 8);
        $numShdrs = 6; // null, .text, .rodata, .data, .bss, .shstrtab
        $shstrtabIdx = 5;

        // Build the binary
        $bin = '';

        // ── ELF Header ──
        $bin .= $this->elfHeader($entryAddr, $numPhdrs, $shOff, $numShdrs, $shstrtabIdx);

        // ── Program Headers ──
        // PT_LOAD #1: RX (.text + .rodata)
        $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_X,
            $seg1FileOff, $seg1VAddr, $seg1FileSz, $seg1MemSz, self::PAGE_SIZE);

        // PT_LOAD #2: RW (.data + .bss)
        if ($seg2FileSz > 0 || $seg2MemSz > 0) {
            $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_W,
                $seg2FileOff, $seg2VAddr, $seg2FileSz, $seg2MemSz, self::PAGE_SIZE);
        } else {
            // Empty RW segment — still emit for the phdr count
            $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_W,
                $seg2FileOff, $seg2VAddr, 0, 0, self::PAGE_SIZE);
        }

        // ── Padding to page boundary ──
        $padLen = $seg1FileOff - strlen($bin);
        $bin .= str_repeat("\0", $padLen);

        // ── Segment 1: .text + .rodata ──
        $bin .= $seg1Content;

        // ── Padding to next page ──
        $padLen = $seg2FileOff - strlen($bin);
        if ($padLen > 0) {
            $bin .= str_repeat("\0", $padLen);
        }

        // ── Segment 2: .data ──
        $bin .= $seg2Content;

        // ── .shstrtab ──
        $shstrtabFileOff = strlen($bin);
        $bin .= $shstrtab;

        // ── Alignment for section headers ──
        $padLen = $shOff - strlen($bin);
        if ($padLen > 0) {
            $bin .= str_repeat("\0", $padLen);
        }

        // ── Section Headers ──
        // SHT_NULL
        $bin .= $this->shdr(0, self::SHT_NULL, 0, 0, 0, 0, 0, 0, 0, 0);

        // .text
        $textVAddr = $sectionVAddrs['.text'] ?? $seg1VAddr;
        $bin .= $this->shdr($shstrtabNames['.text'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_EXEC,
            $textVAddr, $seg1FileOff, strlen($textBytes),
            0, 0, 16, 0);

        // .rodata
        $rodataVAddr = $sectionVAddrs['.rodata'] ?? ($textVAddr + strlen($textBytes));
        $rodataFileOff = $seg1FileOff + strlen($textBytes);
        $bin .= $this->shdr($shstrtabNames['.rodata'], self::SHT_PROGBITS,
            self::SHF_ALLOC,
            $rodataVAddr, $rodataFileOff, strlen($rodataBytes),
            0, 0, 1, 0);

        // .data
        $dataVAddr = $sectionVAddrs['.data'] ?? $seg2VAddr;
        $bin .= $this->shdr($shstrtabNames['.data'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $dataVAddr, $seg2FileOff, strlen($dataBytes),
            0, 0, 8, 0);

        // .bss
        $bssVAddr = $sectionVAddrs['.bss'] ?? ($dataVAddr + strlen($dataBytes));
        $bin .= $this->shdr($shstrtabNames['.bss'], self::SHT_NOBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $bssVAddr, $seg2FileOff + $seg2FileSz, $bssSize,
            0, 0, 8, 0);

        // .shstrtab
        $bin .= $this->shdr($shstrtabNames['.shstrtab'], self::SHT_STRTAB,
            0, 0, $shstrtabFileOff, strlen($shstrtab),
            0, 0, 1, 0);

        return $bin;
    }

    /**
     * Compute section virtual addresses for the linker.
     *
     * @return array{vaddrs: array<string, int>, entry: int|null}
     */
    public static function computeLayout(Linker $linker): array
    {
        $sections = $linker->getSections();
        $textLen   = strlen($sections['.text']->bytes ?? '');
        $rodataLen = strlen($sections['.rodata']->bytes ?? '');
        $dataLen   = strlen($sections['.data']->bytes ?? '');

        $seg1FileOff = self::PAGE_SIZE;
        $textVAddr   = self::BASE_ADDR + $seg1FileOff;
        $rodataVAddr = $textVAddr + $textLen;

        $seg2FileOff = $seg1FileOff + self::alignUpStatic($textLen + $rodataLen, self::PAGE_SIZE);
        $dataVAddr   = self::BASE_ADDR + $seg2FileOff;
        $bssVAddr    = $dataVAddr + $dataLen;

        $vaddrs = [
            '.text'   => $textVAddr,
            '.rodata' => $rodataVAddr,
            '.data'   => $dataVAddr,
            '.bss'    => $bssVAddr,
        ];

        // Find _start entry point
        $entry = null;
        $symbols = $linker->getSymbols();
        if (isset($symbols['_start'])) {
            $sym = $symbols['_start'];
            $entry = $vaddrs[$sym->section] + $sym->offset;
        }

        return ['vaddrs' => $vaddrs, 'entry' => $entry];
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    private function elfHeader(int $entry, int $phnum, int $shoff, int $shnum, int $shstrndx): string
    {
        $h = '';
        // e_ident (16 bytes)
        $h .= "\x7FELF";          // magic
        $h .= "\x02";             // class: 64-bit
        $h .= "\x01";             // data: little-endian
        $h .= "\x01";             // version: current
        $h .= "\x00";             // OS/ABI: System V
        $h .= str_repeat("\x00", 8); // padding
        // e_type (2): ET_EXEC = 2
        $h .= pack('v', 2);
        // e_machine (2): EM_X86_64 = 0x3E
        $h .= pack('v', 0x3E);
        // e_version (4)
        $h .= pack('V', 1);
        // e_entry (8)
        $h .= pack('P', $entry);
        // e_phoff (8)
        $h .= pack('P', self::ELF_HEADER_SZ);
        // e_shoff (8)
        $h .= pack('P', $shoff);
        // e_flags (4)
        $h .= pack('V', 0);
        // e_ehsize (2)
        $h .= pack('v', self::ELF_HEADER_SZ);
        // e_phentsize (2)
        $h .= pack('v', self::PHDR_SZ);
        // e_phnum (2)
        $h .= pack('v', $phnum);
        // e_shentsize (2)
        $h .= pack('v', self::SHDR_SZ);
        // e_shnum (2)
        $h .= pack('v', $shnum);
        // e_shstrndx (2)
        $h .= pack('v', $shstrndx);

        return $h;
    }

    private function phdr(int $type, int $flags, int $offset, int $vaddr,
                          int $filesz, int $memsz, int $align): string
    {
        $p = '';
        $p .= pack('V', $type);     // p_type
        $p .= pack('V', $flags);    // p_flags
        $p .= pack('P', $offset);   // p_offset
        $p .= pack('P', $vaddr);    // p_vaddr
        $p .= pack('P', $vaddr);    // p_paddr (= vaddr for static)
        $p .= pack('P', $filesz);   // p_filesz
        $p .= pack('P', $memsz);    // p_memsz
        $p .= pack('P', $align);    // p_align
        return $p;
    }

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

    private function buildShstrtab(): string
    {
        // Null-terminated string table
        $s = "\0";
        $s .= ".text\0";
        $s .= ".rodata\0";
        $s .= ".data\0";
        $s .= ".bss\0";
        $s .= ".shstrtab\0";
        return $s;
    }

    /** @return array<string, int> section name → offset in shstrtab */
    private function shstrtabOffsets(string $shstrtab): array
    {
        return [
            '.text'     => 1,
            '.rodata'   => 7,
            '.data'     => 15,
            '.bss'      => 21,
            '.shstrtab' => 26,
        ];
    }

    private function alignUp(int $value, int $align): int
    {
        return ($value + $align - 1) & ~($align - 1);
    }

    private static function alignUpStatic(int $value, int $align): int
    {
        return ($value + $align - 1) & ~($align - 1);
    }
}
