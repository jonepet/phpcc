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
    private const PT_LOAD    = 1;
    private const PT_DYNAMIC = 2;
    private const PT_INTERP  = 3;
    private const PT_PHDR    = 6;
    private const PT_NULL    = 0;
    private const PF_X       = 1;
    private const PF_W       = 2;
    private const PF_R       = 4;
    private const SHT_NULL     = 0;
    private const SHT_PROGBITS = 1;
    private const SHT_SYMTAB   = 2;
    private const SHT_STRTAB   = 3;
    private const SHT_RELA     = 4;
    private const SHT_HASH     = 5;
    private const SHT_DYNAMIC  = 6;
    private const SHT_NOBITS   = 8;
    private const SHT_DYNSYM   = 11;
    private const SHF_WRITE    = 1;
    private const SHF_ALLOC    = 2;
    private const SHF_EXEC     = 4;

    // DT_* constants
    private const DT_NULL      = 0;
    private const DT_NEEDED    = 1;
    private const DT_PLTGOT    = 3;
    private const DT_HASH      = 4;
    private const DT_STRTAB    = 5;
    private const DT_SYMTAB    = 6;
    private const DT_STRSZ     = 10;
    private const DT_SYMENT    = 11;
    private const DT_DEBUG     = 21;
    private const DT_PLTREL    = 20;
    private const DT_PLTRELSZ  = 2;
    private const DT_JMPREL    = 23;
    private const DT_RELA      = 7;
    private const DT_RELASZ    = 8;
    private const DT_RELAENT   = 9;

    private const INTERP = "/lib64/ld-linux-x86-64.so.2";

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

        $numPhdrs = 2;
        $headerSize = self::ELF_HEADER_SZ + $numPhdrs * self::PHDR_SZ;

        // Segment 1 (RX): .text + .rodata
        $seg1FileOff = self::PAGE_SIZE;
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

        $shstrtab = $this->buildShstrtab();
        $shstrtabNames = $this->shstrtabOffsets($shstrtab);

        $shOff = $seg2FileOff + $seg2FileSz;
        $shstrtabOff = $shOff;
        $shOff = $shstrtabOff + strlen($shstrtab);
        $shOff = $this->alignUp($shOff, 8);
        $numShdrs = 6;
        $shstrtabIdx = 5;

        $bin = '';
        $bin .= $this->elfHeader(2, $entryAddr, $numPhdrs, $shOff, $numShdrs, $shstrtabIdx);

        $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_X,
            $seg1FileOff, $seg1VAddr, $seg1FileSz, $seg1MemSz, self::PAGE_SIZE);

        if ($seg2FileSz > 0 || $seg2MemSz > 0) {
            $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_W,
                $seg2FileOff, $seg2VAddr, $seg2FileSz, $seg2MemSz, self::PAGE_SIZE);
        } else {
            $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_W,
                $seg2FileOff, $seg2VAddr, 0, 0, self::PAGE_SIZE);
        }

        $padLen = $seg1FileOff - strlen($bin);
        $bin .= str_repeat("\0", $padLen);
        $bin .= $seg1Content;

        $padLen = $seg2FileOff - strlen($bin);
        if ($padLen > 0) {
            $bin .= str_repeat("\0", $padLen);
        }
        $bin .= $seg2Content;

        $shstrtabFileOff = strlen($bin);
        $bin .= $shstrtab;

        $padLen = $shOff - strlen($bin);
        if ($padLen > 0) {
            $bin .= str_repeat("\0", $padLen);
        }

        // Section Headers
        $bin .= $this->shdr(0, self::SHT_NULL, 0, 0, 0, 0, 0, 0, 0, 0);

        $textVAddr = $sectionVAddrs['.text'] ?? $seg1VAddr;
        $bin .= $this->shdr($shstrtabNames['.text'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_EXEC,
            $textVAddr, $seg1FileOff, strlen($textBytes),
            0, 0, 16, 0);

        $rodataVAddr = $sectionVAddrs['.rodata'] ?? ($textVAddr + strlen($textBytes));
        $rodataFileOff = $seg1FileOff + strlen($textBytes);
        $bin .= $this->shdr($shstrtabNames['.rodata'], self::SHT_PROGBITS,
            self::SHF_ALLOC,
            $rodataVAddr, $rodataFileOff, strlen($rodataBytes),
            0, 0, 1, 0);

        $dataVAddr = $sectionVAddrs['.data'] ?? $seg2VAddr;
        $bin .= $this->shdr($shstrtabNames['.data'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $dataVAddr, $seg2FileOff, strlen($dataBytes),
            0, 0, 8, 0);

        $bssVAddr = $sectionVAddrs['.bss'] ?? ($dataVAddr + strlen($dataBytes));
        $bin .= $this->shdr($shstrtabNames['.bss'], self::SHT_NOBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $bssVAddr, $seg2FileOff + $seg2FileSz, $bssSize,
            0, 0, 8, 0);

        $bin .= $this->shdr($shstrtabNames['.shstrtab'], self::SHT_STRTAB,
            0, 0, $shstrtabFileOff, strlen($shstrtab),
            0, 0, 1, 0);

        return $bin;
    }

    /**
     * Build a dynamically-linked ELF64 executable.
     *
     * @param array<string, SectionData> $sections User code sections (.text, .rodata, .data, .bss)
     * @param int $entryAddr Virtual address of _start (after layout)
     * @param array<string, int> $sectionVAddrs
     * @param GotPltBuilder $gotPlt
     * @param string[] $neededLibs Library names (e.g., ['libc.so.6', 'libm.so.6'])
     * @return string raw ELF binary bytes
     */
    public function buildDynExecutable(
        array $sections,
        int $entryAddr,
        array $sectionVAddrs,
        GotPltBuilder $gotPlt,
        array $neededLibs,
    ): string {
        $textBytes   = $sections['.text']->bytes   ?? '';
        $rodataBytes = $sections['.rodata']->bytes ?? '';
        $dataBytes   = $sections['.data']->bytes   ?? '';
        $bssSize     = strlen($sections['.bss']->bytes ?? '');

        // Build .interp
        $interpBytes = self::INTERP . "\0";

        // Build .dynsym and .dynstr
        $pltSymbols = $gotPlt->getPltSymbols();
        $gotDataSymbols = $gotPlt->getGotDataSymbols();
        $dynstr = "\0"; // Start with null byte
        $dynSymEntries = [];
        $dynSymIndex = []; // symbol name → index in .dynsym

        // Null symbol entry
        $dynSymEntries[] = $this->symEntry(0, 0, 0, 0, 0);

        // Add symbols for PLT entries (undefined function references)
        foreach ($pltSymbols as $name) {
            $nameOff = strlen($dynstr);
            $dynstr .= $name . "\0";
            $stInfo = (1 << 4) | 0; // STB_GLOBAL | STT_NOTYPE
            $dynSymIndex[$name] = count($dynSymEntries);
            $dynSymEntries[] = $this->symEntry($nameOff, $stInfo, 0, 0, 0); // SHN_UNDEF
        }

        // Add symbols for GOT data entries (undefined data references)
        foreach ($gotDataSymbols as $name) {
            $nameOff = strlen($dynstr);
            $dynstr .= $name . "\0";
            $stInfo = (1 << 4) | 0; // STB_GLOBAL | STT_NOTYPE
            $dynSymIndex[$name] = count($dynSymEntries);
            $dynSymEntries[] = $this->symEntry($nameOff, $stInfo, 0, 0, 0); // SHN_UNDEF
        }

        // Add DT_NEEDED library names to dynstr
        $neededOffsets = [];
        foreach ($neededLibs as $lib) {
            $neededOffsets[] = strlen($dynstr);
            $dynstr .= $lib . "\0";
        }

        $dynsymBytes = '';
        foreach ($dynSymEntries as $entry) {
            $dynsymBytes .= $entry;
        }

        // Build .hash (SysV hash)
        $hashBytes = $this->buildSysVHash(count($dynSymEntries), $pltSymbols, $dynSymIndex);

        // Calculate program header count
        // PT_PHDR, PT_INTERP, PT_LOAD (RO), PT_LOAD (RX), PT_LOAD (RW), PT_DYNAMIC
        $numPhdrs = 6;
        $phdrsTotalSize = $numPhdrs * self::PHDR_SZ;

        // Layout planning - all sizes need to be computed before layout
        // Segment 1 (RO): ELF header + phdrs + .interp + .dynsym + .dynstr + .hash + .rela.plt + .rela.dyn
        // Segment 2 (RX): .plt + .text + .rodata
        // Segment 3 (RW): .data + .bss + .got.plt + .dynamic

        // Build rela sections (need sizes for layout)
        $relaPltBytes = $gotPlt->buildRelaPlt(0, $dynSymIndex); // vaddr placeholder, entries are position-independent
        $relaDynBytes = $gotPlt->buildRelaDyn(0, $dynSymIndex);

        // Segment 1 (RO) - starts at offset 0
        $seg1FileOff = 0;
        $seg1VAddr = self::BASE_ADDR;

        $interpOff = self::ELF_HEADER_SZ + $phdrsTotalSize;
        $interpSize = strlen($interpBytes);

        $dynsymOff = $this->alignUp($interpOff + $interpSize, 8);
        $dynsymSize = strlen($dynsymBytes);

        $dynstrOff = $dynsymOff + $dynsymSize;
        $dynstrSize = strlen($dynstr);

        $hashOff = $this->alignUp($dynstrOff + $dynstrSize, 4);
        $hashSize = strlen($hashBytes);

        $relaPltOff = $this->alignUp($hashOff + $hashSize, 8);
        $relaPltSize = strlen($relaPltBytes);

        $relaDynOff = $relaPltOff + $relaPltSize;
        $relaDynSize = strlen($relaDynBytes);

        $seg1End = $relaDynOff + $relaDynSize;
        $seg1FileSz = $seg1End;

        // Segment 2 (RX) - page-aligned
        $seg2FileOff = $this->alignUp($seg1End, self::PAGE_SIZE);
        $seg2VAddr = self::BASE_ADDR + $seg2FileOff;

        $pltOff = $seg2FileOff;
        $pltSize = $gotPlt->getPltSize();

        $textOff = $pltOff + $pltSize;
        $textSize = strlen($textBytes);

        $rodataOff = $textOff + $textSize;
        $rodataSize = strlen($rodataBytes);

        $seg2FileSz = $pltSize + $textSize + $rodataSize;
        $seg2End = $seg2FileOff + $seg2FileSz;

        // Virtual addresses for code sections
        $pltVAddr = $seg2VAddr;
        $textVAddr = $pltVAddr + $pltSize;
        $rodataVAddr = $textVAddr + $textSize;

        // Segment 3 (RW) - page-aligned
        $seg3FileOff = $this->alignUp($seg2End, self::PAGE_SIZE);
        $seg3VAddr = self::BASE_ADDR + $seg3FileOff;

        $dataOff = $seg3FileOff;
        $dataSize = strlen($dataBytes);

        // .got section (for GOTPCREL data symbols)
        $gotSize = $gotPlt->getGotSize();
        $gotOff = $this->alignUp($dataOff + $dataSize, 8);
        $gotVAddr = self::BASE_ADDR + $gotOff;
        $gotBytes = $gotPlt->buildGot();

        $gotPltOff = $this->alignUp($gotOff + $gotSize, 8);
        $gotPltSize = $gotPlt->getGotPltSize();
        $gotPltVAddr = self::BASE_ADDR + $gotPltOff;
        $dynamicOff = $this->alignUp($gotPltOff + $gotPltSize, 8);

        // Build .dynamic section (need to know its own vaddr for entries)
        $dynamicVAddr = self::BASE_ADDR + $dynamicOff;

        $dynamicEntries = [];
        foreach ($neededOffsets as $off) {
            $dynamicEntries[] = [self::DT_NEEDED, $off];
        }
        $dynamicEntries[] = [self::DT_STRTAB,   self::BASE_ADDR + $dynstrOff];
        $dynamicEntries[] = [self::DT_SYMTAB,    self::BASE_ADDR + $dynsymOff];
        $dynamicEntries[] = [self::DT_STRSZ,     $dynstrSize];
        $dynamicEntries[] = [self::DT_SYMENT,    24];
        $dynamicEntries[] = [self::DT_HASH,      self::BASE_ADDR + $hashOff];
        if ($gotPltSize > 0) {
            $dynamicEntries[] = [self::DT_PLTGOT,    $gotPltVAddr];
            $dynamicEntries[] = [self::DT_PLTRELSZ,  $relaPltSize];
            $dynamicEntries[] = [self::DT_PLTREL,    7]; // DT_RELA
            $dynamicEntries[] = [self::DT_JMPREL,    self::BASE_ADDR + $relaPltOff];
        }
        if ($relaDynSize > 0) {
            $dynamicEntries[] = [self::DT_RELA,      self::BASE_ADDR + $relaDynOff];
            $dynamicEntries[] = [self::DT_RELASZ,    $relaDynSize];
            $dynamicEntries[] = [self::DT_RELAENT,   24];
        }
        $dynamicEntries[] = [self::DT_DEBUG, 0];
        $dynamicEntries[] = [self::DT_NULL, 0];

        $dynamicBytes = '';
        foreach ($dynamicEntries as [$tag, $val]) {
            $dynamicBytes .= pack('P', $tag);  // d_tag (signed but we treat as unsigned)
            $dynamicBytes .= pack('P', $val);  // d_val/d_ptr
        }
        $dynamicSize = strlen($dynamicBytes);

        $bssOff = $dynamicOff + $dynamicSize;
        $bssVAddr = self::BASE_ADDR + $bssOff;
        $dataVAddr = $seg3VAddr;

        $seg3FileSz = $bssOff - $seg3FileOff;
        $seg3MemSz = $seg3FileSz + $bssSize;

        // Now rebuild rela sections with correct vaddrs
        $relaPltBytes = $gotPlt->buildRelaPlt($gotPltVAddr, $dynSymIndex);
        $relaDynBytes = $gotPlt->buildRelaDyn($gotVAddr, $dynSymIndex); // GOT entries for GOTPCREL symbols

        // Build PLT and GOT.PLT with correct addresses
        $pltBytes = $gotPlt->buildPlt($pltVAddr, $gotPltVAddr);
        $gotPltBytes = $gotPlt->buildGotPlt($pltVAddr, $dynamicVAddr);

        // Compute entry point relative to text section
        $actualEntry = $entryAddr;
        // If entryAddr was computed with old text vaddr, adjust
        if (isset($sectionVAddrs['.text'])) {
            $oldTextVAddr = $sectionVAddrs['.text'];
            $actualEntry = $textVAddr + ($entryAddr - $oldTextVAddr);
        }

        // Build shstrtab for dynamic executable
        $shstrtab = "\0";
        $shstrtabNames = [];
        $secNames = ['.interp', '.dynsym', '.dynstr', '.hash',
                     '.rela.plt', '.rela.dyn', '.plt', '.text', '.rodata',
                     '.data', '.bss', '.got', '.got.plt', '.dynamic', '.shstrtab'];
        foreach ($secNames as $name) {
            $shstrtabNames[$name] = strlen($shstrtab);
            $shstrtab .= $name . "\0";
        }

        // Section headers
        $shstrtabFileOff = $bssOff; // after all file content
        $shdrOff = $this->alignUp($shstrtabFileOff + strlen($shstrtab), 8);

        // Section indices (for cross-references)
        // 0: NULL, 1: .interp, 2: .dynsym, 3: .dynstr, 4: .hash,
        // 5: .rela.plt, 6: .rela.dyn, 7: .plt, 8: .text, 9: .rodata,
        // 10: .data, 11: .bss, 12: .got, 13: .got.plt, 14: .dynamic, 15: .shstrtab
        $dynsymIdx = 2;
        $dynstrIdx = 3;
        $shstrtabIdx = 15;
        $numShdrs = 16;

        // Build the binary
        $bin = '';

        // ELF Header
        $bin .= $this->elfHeader(2, $actualEntry, $numPhdrs, $shdrOff, $numShdrs, $shstrtabIdx);

        // Program Headers
        // PT_PHDR
        $bin .= $this->phdr(self::PT_PHDR, self::PF_R,
            self::ELF_HEADER_SZ, self::BASE_ADDR + self::ELF_HEADER_SZ,
            $phdrsTotalSize, $phdrsTotalSize, 8);

        // PT_INTERP
        $bin .= $this->phdr(self::PT_INTERP, self::PF_R,
            $interpOff, self::BASE_ADDR + $interpOff,
            $interpSize, $interpSize, 1);

        // PT_LOAD #1 (RO): headers + .interp + .dynsym + .dynstr + .hash + .rela.*
        $bin .= $this->phdr(self::PT_LOAD, self::PF_R,
            $seg1FileOff, $seg1VAddr, $seg1FileSz, $seg1FileSz, self::PAGE_SIZE);

        // PT_LOAD #2 (RX): .plt + .text + .rodata
        $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_X,
            $seg2FileOff, $seg2VAddr, $seg2FileSz, $seg2FileSz, self::PAGE_SIZE);

        // PT_LOAD #3 (RW): .data + .got.plt + .dynamic + .bss
        $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_W,
            $seg3FileOff, $seg3VAddr, $seg3FileSz, $seg3MemSz, self::PAGE_SIZE);

        // PT_DYNAMIC
        $bin .= $this->phdr(self::PT_DYNAMIC, self::PF_R | self::PF_W,
            $dynamicOff, $dynamicVAddr, $dynamicSize, $dynamicSize, 8);

        // Segment 1 content
        // .interp
        $this->padTo($bin, $interpOff);
        $bin .= $interpBytes;

        // .dynsym
        $this->padTo($bin, $dynsymOff);
        $bin .= $dynsymBytes;

        // .dynstr
        $bin .= $dynstr;

        // .hash
        $this->padTo($bin, $hashOff);
        $bin .= $hashBytes;

        // .rela.plt
        $this->padTo($bin, $relaPltOff);
        $bin .= $relaPltBytes;

        // .rela.dyn
        $bin .= $relaDynBytes;

        // Segment 2 content
        $this->padTo($bin, $seg2FileOff);

        // .plt
        $bin .= $pltBytes;

        // .text
        $bin .= $textBytes;

        // .rodata
        $bin .= $rodataBytes;

        // Segment 3 content
        $this->padTo($bin, $seg3FileOff);

        // .data
        $bin .= $dataBytes;

        // .got
        $this->padTo($bin, $gotOff);
        $bin .= $gotBytes;

        // .got.plt
        $bin .= $gotPltBytes;

        // .dynamic
        $this->padTo($bin, $dynamicOff);
        $bin .= $dynamicBytes;

        // .shstrtab
        $bin .= $shstrtab;

        // Padding before section headers
        $this->padTo($bin, $shdrOff);

        // Section Headers
        // 0: NULL
        $bin .= $this->shdr(0, self::SHT_NULL, 0, 0, 0, 0, 0, 0, 0, 0);

        // 1: .interp
        $bin .= $this->shdr($shstrtabNames['.interp'], self::SHT_PROGBITS,
            self::SHF_ALLOC, self::BASE_ADDR + $interpOff, $interpOff, $interpSize,
            0, 0, 1, 0);

        // 2: .dynsym
        $bin .= $this->shdr($shstrtabNames['.dynsym'], self::SHT_DYNSYM,
            self::SHF_ALLOC, self::BASE_ADDR + $dynsymOff, $dynsymOff, strlen($dynsymBytes),
            $dynstrIdx, 1, 8, 24); // sh_info=1 (first global)

        // 3: .dynstr
        $bin .= $this->shdr($shstrtabNames['.dynstr'], self::SHT_STRTAB,
            self::SHF_ALLOC, self::BASE_ADDR + $dynstrOff, $dynstrOff, $dynstrSize,
            0, 0, 1, 0);

        // 4: .hash
        $bin .= $this->shdr($shstrtabNames['.hash'], self::SHT_HASH,
            self::SHF_ALLOC, self::BASE_ADDR + $hashOff, $hashOff, $hashSize,
            $dynsymIdx, 0, 4, 4);

        // 5: .rela.plt
        $bin .= $this->shdr($shstrtabNames['.rela.plt'], self::SHT_RELA,
            self::SHF_ALLOC, self::BASE_ADDR + $relaPltOff, $relaPltOff, $relaPltSize,
            $dynsymIdx, 7, 8, 24); // sh_info = .plt section index (7)

        // 6: .rela.dyn
        $bin .= $this->shdr($shstrtabNames['.rela.dyn'], self::SHT_RELA,
            self::SHF_ALLOC, self::BASE_ADDR + $relaDynOff, $relaDynOff, $relaDynSize,
            $dynsymIdx, 0, 8, 24);

        // 7: .plt
        $bin .= $this->shdr($shstrtabNames['.plt'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_EXEC,
            $pltVAddr, $pltOff, $pltSize,
            0, 0, 16, 16);

        // 8: .text
        $bin .= $this->shdr($shstrtabNames['.text'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_EXEC,
            $textVAddr, $textOff, $textSize,
            0, 0, 16, 0);

        // 9: .rodata
        $bin .= $this->shdr($shstrtabNames['.rodata'], self::SHT_PROGBITS,
            self::SHF_ALLOC,
            $rodataVAddr, $rodataOff, $rodataSize,
            0, 0, 1, 0);

        // 10: .data
        $bin .= $this->shdr($shstrtabNames['.data'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $dataVAddr, $dataOff, $dataSize,
            0, 0, 8, 0);

        // 11: .bss
        $bin .= $this->shdr($shstrtabNames['.bss'], self::SHT_NOBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $bssVAddr, $bssOff, $bssSize,
            0, 0, 8, 0);

        // 12: .got
        $bin .= $this->shdr($shstrtabNames['.got'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $gotVAddr, $gotOff, $gotSize,
            0, 0, 8, 8);

        // 13: .got.plt
        $bin .= $this->shdr($shstrtabNames['.got.plt'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $gotPltVAddr, $gotPltOff, $gotPltSize,
            0, 0, 8, 8);

        // 14: .dynamic
        $bin .= $this->shdr($shstrtabNames['.dynamic'], self::SHT_DYNAMIC,
            self::SHF_ALLOC | self::SHF_WRITE,
            $dynamicVAddr, $dynamicOff, $dynamicSize,
            $dynstrIdx, 0, 8, 16);

        // 15: .shstrtab
        $bin .= $this->shdr($shstrtabNames['.shstrtab'], self::SHT_STRTAB,
            0, 0, $shstrtabFileOff, strlen($shstrtab),
            0, 0, 1, 0);
        return $bin;
    }

    /**
     * Build a shared library (ET_DYN).
     *
     * @param array<string, SectionData> $sections
     * @param array<string, int> $sectionVAddrs
     * @param GotPltBuilder $gotPlt
     * @param string[] $neededLibs
     * @param string $soname The SONAME for this library
     * @param string[] $exportedSymbols Symbol names to export in .dynsym
     */
    public function buildSharedLibrary(
        array $sections,
        array $sectionVAddrs,
        GotPltBuilder $gotPlt,
        array $neededLibs,
        string $soname,
        array $exportedSymbols,
    ): string {
        // For shared libraries, use base address 0 (PIC)
        // The structure is similar to buildDynExecutable but:
        // - e_type = ET_DYN (3)
        // - No PT_INTERP
        // - DT_SONAME in .dynamic
        // - Exported symbols in .dynsym
        // - No _start entry point (e_entry = 0)

        $textBytes   = $sections['.text']->bytes   ?? '';
        $rodataBytes = $sections['.rodata']->bytes ?? '';
        $dataBytes   = $sections['.data']->bytes   ?? '';
        $bssSize     = strlen($sections['.bss']->bytes ?? '');

        // Build .dynsym and .dynstr
        $pltSymbols = $gotPlt->getPltSymbols();
        $dynstr = "\0";
        $dynSymEntries = [];
        $dynSymIndex = [];

        // Null symbol
        $dynSymEntries[] = $this->symEntry(0, 0, 0, 0, 0);

        // Imported symbols (PLT)
        foreach ($pltSymbols as $name) {
            $nameOff = strlen($dynstr);
            $dynstr .= $name . "\0";
            $stInfo = (1 << 4) | 0; // STB_GLOBAL | STT_NOTYPE
            $dynSymIndex[$name] = count($dynSymEntries);
            $dynSymEntries[] = $this->symEntry($nameOff, $stInfo, 0, 0, 0);
        }

        // Exported symbols
        foreach ($exportedSymbols as $name) {
            if (isset($dynSymIndex[$name])) continue; // already added as import
            $nameOff = strlen($dynstr);
            $dynstr .= $name . "\0";
            $stInfo = (1 << 4) | 2; // STB_GLOBAL | STT_FUNC
            $dynSymIndex[$name] = count($dynSymEntries);
            // Value and section will be patched after layout
            $dynSymEntries[] = $this->symEntry($nameOff, $stInfo, 0, 0, 8); // section index 8 (.text)
        }

        // SONAME and DT_NEEDED in dynstr
        $sonameOff = strlen($dynstr);
        $dynstr .= $soname . "\0";
        $neededOffsets = [];
        foreach ($neededLibs as $lib) {
            $neededOffsets[] = strlen($dynstr);
            $dynstr .= $lib . "\0";
        }

        $dynsymBytes = '';
        foreach ($dynSymEntries as $entry) {
            $dynsymBytes .= $entry;
        }

        $hashBytes = $this->buildSysVHash(count($dynSymEntries),
            array_merge($pltSymbols, $exportedSymbols), $dynSymIndex);

        // Rela sections
        $relaPltBytes = $gotPlt->buildRelaPlt(0, $dynSymIndex);
        $relaDynBytes = $gotPlt->buildRelaDyn(0, $dynSymIndex);

        // Layout (base address 0 for PIC)
        $baseAddr = 0;

        // PT_PHDR, PT_LOAD (RO), PT_LOAD (RX), PT_LOAD (RW), PT_DYNAMIC
        $numPhdrs = 5;
        $phdrsTotalSize = $numPhdrs * self::PHDR_SZ;

        $dynsymOff = $this->alignUp(self::ELF_HEADER_SZ + $phdrsTotalSize, 8);
        $dynstrOff = $dynsymOff + strlen($dynsymBytes);
        $hashOff = $this->alignUp($dynstrOff + strlen($dynstr), 4);
        $relaPltOff = $this->alignUp($hashOff + strlen($hashBytes), 8);
        $relaDynOff = $relaPltOff + strlen($relaPltBytes);
        $seg1End = $relaDynOff + strlen($relaDynBytes);

        $seg2FileOff = $this->alignUp($seg1End, self::PAGE_SIZE);
        $pltOff = $seg2FileOff;
        $pltSize = $gotPlt->getPltSize();
        $textOff = $pltOff + $pltSize;
        $rodataOff = $textOff + strlen($textBytes);
        $seg2End = $rodataOff + strlen($rodataBytes);

        $seg3FileOff = $this->alignUp($seg2End, self::PAGE_SIZE);
        $dataOff = $seg3FileOff;
        $gotPltOff = $this->alignUp($dataOff + strlen($dataBytes), 8);
        $gotPltSize = $gotPlt->getGotPltSize();

        $dynamicOff = $this->alignUp($gotPltOff + $gotPltSize, 8);

        // Build .dynamic
        $dynamicEntries = [];
        $dynamicEntries[] = [12, $sonameOff]; // DT_SONAME = 14 ... wait, DT_SONAME is 14
        // Fix: DT_SONAME = 14
        $dynamicEntries = [];
        $dynamicEntries[] = [14, $sonameOff]; // DT_SONAME
        foreach ($neededOffsets as $off) {
            $dynamicEntries[] = [self::DT_NEEDED, $off];
        }
        $dynamicEntries[] = [self::DT_STRTAB, $baseAddr + $dynstrOff];
        $dynamicEntries[] = [self::DT_SYMTAB, $baseAddr + $dynsymOff];
        $dynamicEntries[] = [self::DT_STRSZ, strlen($dynstr)];
        $dynamicEntries[] = [self::DT_SYMENT, 24];
        $dynamicEntries[] = [self::DT_HASH, $baseAddr + $hashOff];
        if ($gotPltSize > 0) {
            $dynamicEntries[] = [self::DT_PLTGOT, $baseAddr + $gotPltOff];
            $dynamicEntries[] = [self::DT_PLTRELSZ, strlen($relaPltBytes)];
            $dynamicEntries[] = [self::DT_PLTREL, 7];
            $dynamicEntries[] = [self::DT_JMPREL, $baseAddr + $relaPltOff];
        }
        if (strlen($relaDynBytes) > 0) {
            $dynamicEntries[] = [self::DT_RELA, $baseAddr + $relaDynOff];
            $dynamicEntries[] = [self::DT_RELASZ, strlen($relaDynBytes)];
            $dynamicEntries[] = [self::DT_RELAENT, 24];
        }
        $dynamicEntries[] = [self::DT_NULL, 0];

        $dynamicBytes = '';
        foreach ($dynamicEntries as [$tag, $val]) {
            $dynamicBytes .= pack('P', $tag);
            $dynamicBytes .= pack('P', $val);
        }

        $bssOff = $dynamicOff + strlen($dynamicBytes);
        $seg3FileSz = $bssOff - $seg3FileOff;
        $seg3MemSz = $seg3FileSz + $bssSize;

        // Rebuild with correct addresses
        $pltVAddr = $baseAddr + $seg2FileOff;
        $gotPltVAddr = $baseAddr + $gotPltOff;
        $dynamicVAddr = $baseAddr + $dynamicOff;

        $relaPltBytes = $gotPlt->buildRelaPlt($gotPltVAddr, $dynSymIndex);
        $pltBytes = $gotPlt->buildPlt($pltVAddr, $gotPltVAddr);
        $gotPltBytes = $gotPlt->buildGotPlt($pltVAddr, $dynamicVAddr);

        // Shstrtab
        $shstrtab = "\0";
        $shstrtabNames = [];
        $secNames = ['.dynsym', '.dynstr', '.hash',
                     '.rela.plt', '.rela.dyn', '.plt', '.text', '.rodata',
                     '.data', '.bss', '.got', '.got.plt', '.dynamic', '.shstrtab'];
        foreach ($secNames as $name) {
            $shstrtabNames[$name] = strlen($shstrtab);
            $shstrtab .= $name . "\0";
        }

        $shstrtabFileOff = $bssOff;
        $shdrOff = $this->alignUp($shstrtabFileOff + strlen($shstrtab), 8);
        $numShdrs = 16; // null + 13 sections
        $shstrtabIdx = 15;

        // Build binary
        $bin = '';
        $bin .= $this->elfHeader(3, 0, $numPhdrs, $shdrOff, $numShdrs, $shstrtabIdx); // ET_DYN, no entry

        // Program headers
        $bin .= $this->phdr(self::PT_PHDR, self::PF_R,
            self::ELF_HEADER_SZ, $baseAddr + self::ELF_HEADER_SZ,
            $phdrsTotalSize, $phdrsTotalSize, 8);
        $bin .= $this->phdr(self::PT_LOAD, self::PF_R,
            0, $baseAddr, $seg1End, $seg1End, self::PAGE_SIZE);
        $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_X,
            $seg2FileOff, $baseAddr + $seg2FileOff,
            $seg2End - $seg2FileOff, $seg2End - $seg2FileOff, self::PAGE_SIZE);
        $bin .= $this->phdr(self::PT_LOAD, self::PF_R | self::PF_W,
            $seg3FileOff, $baseAddr + $seg3FileOff,
            $seg3FileSz, $seg3MemSz, self::PAGE_SIZE);
        $bin .= $this->phdr(self::PT_DYNAMIC, self::PF_R | self::PF_W,
            $dynamicOff, $dynamicVAddr, strlen($dynamicBytes), strlen($dynamicBytes), 8);

        // Segment 1 content
        $this->padTo($bin, $dynsymOff);
        $bin .= $dynsymBytes;
        $bin .= $dynstr;
        $this->padTo($bin, $hashOff);
        $bin .= $hashBytes;
        $this->padTo($bin, $relaPltOff);
        $bin .= $relaPltBytes;
        $bin .= $relaDynBytes;

        // Segment 2 content
        $this->padTo($bin, $seg2FileOff);
        $bin .= $pltBytes;
        $bin .= $textBytes;
        $bin .= $rodataBytes;

        // Segment 3 content
        $this->padTo($bin, $seg3FileOff);
        $bin .= $dataBytes;
        $this->padTo($bin, $gotPltOff);
        $bin .= $gotPltBytes;
        $this->padTo($bin, $dynamicOff);
        $bin .= $dynamicBytes;

        // Shstrtab
        $bin .= $shstrtab;
        $this->padTo($bin, $shdrOff);

        // Section headers
        $dynsymIdx = 1;
        $dynstrIdx = 2;

        $bin .= $this->shdr(0, self::SHT_NULL, 0, 0, 0, 0, 0, 0, 0, 0);
        $bin .= $this->shdr($shstrtabNames['.dynsym'], self::SHT_DYNSYM,
            self::SHF_ALLOC, $baseAddr + $dynsymOff, $dynsymOff, strlen($dynsymBytes),
            $dynstrIdx, 1, 8, 24);
        $bin .= $this->shdr($shstrtabNames['.dynstr'], self::SHT_STRTAB,
            self::SHF_ALLOC, $baseAddr + $dynstrOff, $dynstrOff, strlen($dynstr),
            0, 0, 1, 0);
        $bin .= $this->shdr($shstrtabNames['.hash'], self::SHT_HASH,
            self::SHF_ALLOC, $baseAddr + $hashOff, $hashOff, strlen($hashBytes),
            $dynsymIdx, 0, 4, 4);
        $bin .= $this->shdr($shstrtabNames['.rela.plt'], self::SHT_RELA,
            self::SHF_ALLOC, $baseAddr + $relaPltOff, $relaPltOff, strlen($relaPltBytes),
            $dynsymIdx, 6, 8, 24); // sh_info=6 (.plt index)
        $bin .= $this->shdr($shstrtabNames['.rela.dyn'], self::SHT_RELA,
            self::SHF_ALLOC, $baseAddr + $relaDynOff, $relaDynOff, strlen($relaDynBytes),
            $dynsymIdx, 0, 8, 24);
        $bin .= $this->shdr($shstrtabNames['.plt'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_EXEC,
            $pltVAddr, $pltOff, $pltSize,
            0, 0, 16, 16);
        $bin .= $this->shdr($shstrtabNames['.text'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_EXEC,
            $baseAddr + $textOff, $textOff, strlen($textBytes),
            0, 0, 16, 0);
        $bin .= $this->shdr($shstrtabNames['.rodata'], self::SHT_PROGBITS,
            self::SHF_ALLOC,
            $baseAddr + $rodataOff, $rodataOff, strlen($rodataBytes),
            0, 0, 1, 0);
        $bin .= $this->shdr($shstrtabNames['.data'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $baseAddr + $dataOff, $dataOff, strlen($dataBytes),
            0, 0, 8, 0);
        $bin .= $this->shdr($shstrtabNames['.bss'], self::SHT_NOBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $baseAddr + $bssOff, $bssOff, $bssSize,
            0, 0, 8, 0);
        $bin .= $this->shdr($shstrtabNames['.got.plt'], self::SHT_PROGBITS,
            self::SHF_ALLOC | self::SHF_WRITE,
            $gotPltVAddr, $gotPltOff, $gotPltSize,
            0, 0, 8, 8);
        $bin .= $this->shdr($shstrtabNames['.dynamic'], self::SHT_DYNAMIC,
            self::SHF_ALLOC | self::SHF_WRITE,
            $dynamicVAddr, $dynamicOff, strlen($dynamicBytes),
            $dynstrIdx, 0, 8, 16);
        $bin .= $this->shdr($shstrtabNames['.shstrtab'], self::SHT_STRTAB,
            0, 0, $shstrtabFileOff, strlen($shstrtab),
            0, 0, 1, 0);

        return $bin;
    }

    /**
     * Compute section virtual addresses for the linker (static executable).
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

        $entry = null;
        $symbols = $linker->getSymbols();
        if (isset($symbols['_start'])) {
            $sym = $symbols['_start'];
            $entry = $vaddrs[$sym->section] + $sym->offset;
        }

        return ['vaddrs' => $vaddrs, 'entry' => $entry];
    }

    /**
     * Compute section virtual addresses for dynamic executable layout.
     * PLT is placed before .text in the RX segment.
     *
     * @return array{vaddrs: array<string, int>, entry: int|null, pltVAddr: int, gotPltVAddr: int, dynamicVAddr: int}
     */
    public static function computeDynLayout(Linker $linker, GotPltBuilder $gotPlt): array
    {
        $sections = $linker->getSections();
        $textLen   = strlen($sections['.text']->bytes ?? '');
        $rodataLen = strlen($sections['.rodata']->bytes ?? '');
        $dataLen   = strlen($sections['.data']->bytes ?? '');

        $pltSize = $gotPlt->getPltSize();
        $gotPltSize = $gotPlt->getGotPltSize();
        $gotSize = $gotPlt->getGotSize();

        // Segment 2 (RX): .plt + .text + .rodata
        $seg2FileOff = self::PAGE_SIZE; // approximate; actual depends on seg1 size
        $seg2VAddr = self::BASE_ADDR + $seg2FileOff;
        $pltVAddr = $seg2VAddr;
        $textVAddr = $pltVAddr + $pltSize;
        $rodataVAddr = $textVAddr + $textLen;

        // Segment 3 (RW): .data + .got + .got.plt + .dynamic
        $seg2End = $seg2FileOff + $pltSize + $textLen + $rodataLen;
        $seg3FileOff = self::alignUpStatic($seg2End, self::PAGE_SIZE);
        $seg3VAddr = self::BASE_ADDR + $seg3FileOff;
        $dataVAddr = $seg3VAddr;

        // .got comes before .got.plt for standard layout
        $gotOff = self::alignUpStatic($dataLen, 8);
        $gotVAddr = $dataVAddr + $gotOff;

        $gotPltOff = $gotOff + $gotSize;
        $gotPltVAddr = $dataVAddr + $gotPltOff;
        $dynamicVAddr = $gotPltVAddr + $gotPltSize;
        $dynamicVAddr = self::alignUpStatic($dynamicVAddr, 8);

        $bssVAddr = $dynamicVAddr + 256; // estimate, refined during build

        $vaddrs = [
            '.text'   => $textVAddr,
            '.rodata' => $rodataVAddr,
            '.data'   => $dataVAddr,
            '.bss'    => $bssVAddr,
        ];

        $entry = null;
        $symbols = $linker->getSymbols();
        if (isset($symbols['_start'])) {
            $sym = $symbols['_start'];
            $entry = $vaddrs[$sym->section] + $sym->offset;
        } elseif (isset($symbols['main'])) {
            // For CRT-based linking, _start comes from crt1.o
            $sym = $symbols['main'];
            $entry = $vaddrs[$sym->section] + $sym->offset;
        }

        return [
            'vaddrs' => $vaddrs,
            'entry' => $entry,
            'pltVAddr' => $pltVAddr,
            'gotVAddr' => $gotVAddr,
            'gotPltVAddr' => $gotPltVAddr,
            'dynamicVAddr' => $dynamicVAddr,
        ];
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    private function elfHeader(int $eType, int $entry, int $phnum, int $shoff, int $shnum, int $shstrndx): string
    {
        $h = '';
        $h .= "\x7FELF";
        $h .= "\x02";             // 64-bit
        $h .= "\x01";             // little-endian
        $h .= "\x01";             // version
        $h .= "\x00";             // OS/ABI
        $h .= str_repeat("\x00", 8);
        $h .= pack('v', $eType);  // ET_EXEC=2, ET_DYN=3
        $h .= pack('v', 0x3E);    // EM_X86_64
        $h .= pack('V', 1);       // version
        $h .= pack('P', $entry);
        $h .= pack('P', self::ELF_HEADER_SZ); // e_phoff
        $h .= pack('P', $shoff);
        $h .= pack('V', 0);       // flags
        $h .= pack('v', self::ELF_HEADER_SZ);
        $h .= pack('v', self::PHDR_SZ);
        $h .= pack('v', $phnum);
        $h .= pack('v', self::SHDR_SZ);
        $h .= pack('v', $shnum);
        $h .= pack('v', $shstrndx);
        return $h;
    }

    private function phdr(int $type, int $flags, int $offset, int $vaddr,
                          int $filesz, int $memsz, int $align): string
    {
        $p = '';
        $p .= pack('V', $type);
        $p .= pack('V', $flags);
        $p .= pack('P', $offset);
        $p .= pack('P', $vaddr);
        $p .= pack('P', $vaddr);   // p_paddr = p_vaddr
        $p .= pack('P', $filesz);
        $p .= pack('P', $memsz);
        $p .= pack('P', $align);
        return $p;
    }

    private function shdr(int $name, int $type, int $flags, int $addr,
                          int $offset, int $size, int $link, int $info,
                          int $addralign, int $entsize): string
    {
        $s = '';
        $s .= pack('V', $name);
        $s .= pack('V', $type);
        $s .= pack('P', $flags);
        $s .= pack('P', $addr);
        $s .= pack('P', $offset);
        $s .= pack('P', $size);
        $s .= pack('V', $link);
        $s .= pack('V', $info);
        $s .= pack('P', $addralign);
        $s .= pack('P', $entsize);
        return $s;
    }

    /**
     * Build an Elf64_Sym entry (24 bytes).
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
     * Build SysV hash section.
     *
     * @param int $numSyms Total number of symbols (including null)
     * @param string[] $symbolNames List of symbol names (excluding null entry)
     * @param array<string, int> $symIndex Symbol name → .dynsym index
     */
    private function buildSysVHash(int $numSyms, array $symbolNames, array $symIndex): string
    {
        $nbuckets = max(1, count($symbolNames));
        $nchain = $numSyms;

        $buckets = array_fill(0, $nbuckets, 0);
        $chain = array_fill(0, $nchain, 0);

        foreach ($symbolNames as $name) {
            $idx = $symIndex[$name] ?? 0;
            if ($idx === 0) continue;
            $hash = $this->elfHash($name);
            $bucket = $hash % $nbuckets;

            if ($buckets[$bucket] === 0) {
                $buckets[$bucket] = $idx;
            } else {
                // Chain: walk to end
                $cur = $buckets[$bucket];
                while ($chain[$cur] !== 0) {
                    $cur = $chain[$cur];
                }
                $chain[$cur] = $idx;
            }
        }

        $bytes = pack('V', $nbuckets) . pack('V', $nchain);
        foreach ($buckets as $b) {
            $bytes .= pack('V', $b);
        }
        foreach ($chain as $c) {
            $bytes .= pack('V', $c);
        }
        return $bytes;
    }

    /**
     * SysV ELF hash function.
     */
    private function elfHash(string $name): int
    {
        $h = 0;
        for ($i = 0; $i < strlen($name); $i++) {
            $h = ($h << 4) + ord($name[$i]);
            $g = $h & 0xF0000000;
            if ($g !== 0) {
                $h ^= ($g >> 24);
            }
            $h &= ~$g;
            $h &= 0xFFFFFFFF;
        }
        return $h;
    }

    private function buildShstrtab(): string
    {
        $s = "\0";
        $s .= ".text\0";
        $s .= ".rodata\0";
        $s .= ".data\0";
        $s .= ".bss\0";
        $s .= ".shstrtab\0";
        return $s;
    }

    /** @return array<string, int> */
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

    private function padTo(string &$bin, int $targetOffset): void
    {
        $current = strlen($bin);
        if ($targetOffset > $current) {
            $bin .= str_repeat("\0", $targetOffset - $current);
        }
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
