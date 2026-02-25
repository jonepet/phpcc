<?php

declare(strict_types=1);

namespace Cppc\Assembler;

/**
 * Generates GOT and PLT sections for dynamic linking.
 *
 * GOT (.got.plt) layout:
 *   [0] = address of .dynamic section (filled by dynamic linker)
 *   [1] = link_map pointer (filled by dynamic linker)
 *   [2] = address of _dl_runtime_resolve (filled by dynamic linker)
 *   [3+N] = lazy resolution entries for each PLT function
 *
 * PLT (.plt) layout:
 *   PLT[0] = resolver trampoline (16 bytes)
 *   PLT[N] = per-function stub (16 bytes each)
 *
 * .rela.plt: R_X86_64_JUMP_SLOT entries (one per PLT function)
 * .rela.dyn: R_X86_64_GLOB_DAT entries (for global data references)
 */
class GotPltBuilder
{
    // PLT entry sizes
    private const PLT0_SIZE = 16;
    private const PLTN_SIZE = 16;
    private const GOT_ENTRY_SIZE = 8;

    /** @var string[] Ordered list of function symbols needing PLT entries */
    private array $pltSymbols = [];

    /** @var string[] Ordered list of data symbols needing GOT entries */
    private array $gotDataSymbols = [];

    /** @var array<string, int> symbol name → PLT index (0-based) */
    private array $pltIndex = [];

    /** @var array<string, int> symbol name → GOT data index (0-based) */
    private array $gotDataIndex = [];

    /**
     * Add a function symbol that needs a PLT entry.
     */
    public function addPltSymbol(string $name): void
    {
        if (!isset($this->pltIndex[$name])) {
            $this->pltIndex[$name] = count($this->pltSymbols);
            $this->pltSymbols[] = $name;
        }
    }

    /**
     * Add a data symbol that needs a GOT entry (for GLOB_DAT).
     */
    public function addGotDataSymbol(string $name): void
    {
        if (!isset($this->gotDataIndex[$name])) {
            $this->gotDataIndex[$name] = count($this->gotDataSymbols);
            $this->gotDataSymbols[] = $name;
        }
    }

    /**
     * @return string[] PLT symbol names
     */
    public function getPltSymbols(): array
    {
        return $this->pltSymbols;
    }

    /**
     * @return string[] GOT data symbol names
     */
    public function getGotDataSymbols(): array
    {
        return $this->gotDataSymbols;
    }

    /**
     * Get the total size of the .plt section.
     */
    public function getPltSize(): int
    {
        if ($this->pltSymbols === []) {
            return 0;
        }
        return self::PLT0_SIZE + count($this->pltSymbols) * self::PLTN_SIZE;
    }

    /**
     * Get the total size of the .got.plt section.
     * 3 reserved entries + one per PLT function.
     */
    public function getGotPltSize(): int
    {
        if ($this->pltSymbols === []) {
            return 0;
        }
        return (3 + count($this->pltSymbols)) * self::GOT_ENTRY_SIZE;
    }

    /**
     * Get the total size of the .got section (for data symbols).
     */
    public function getGotSize(): int
    {
        return count($this->gotDataSymbols) * self::GOT_ENTRY_SIZE;
    }

    /**
     * Build the PLT section bytes.
     * RIP-relative offsets in PLT stubs reference GOT entries.
     *
     * @param int $pltVAddr  Virtual address where .plt will be loaded
     * @param int $gotPltVAddr  Virtual address where .got.plt will be loaded
     */
    public function buildPlt(int $pltVAddr, int $gotPltVAddr): string
    {
        if ($this->pltSymbols === []) {
            return '';
        }

        $plt = '';

        // PLT[0] — resolver trampoline (16 bytes)
        // pushq GOT[1](%rip)
        // jmpq  *GOT[2](%rip)
        // nopl  0(%rax)
        $got1Addr = $gotPltVAddr + 8;  // GOT[1]
        $got2Addr = $gotPltVAddr + 16; // GOT[2]
        $plt0Addr = $pltVAddr;

        // pushq GOT[1](%rip): ff 35 <disp32>
        $disp = $got1Addr - ($plt0Addr + 6);
        $plt .= "\xff\x35" . pack('V', $disp & 0xFFFFFFFF);

        // jmpq *GOT[2](%rip): ff 25 <disp32>
        $disp = $got2Addr - ($plt0Addr + 12);
        $plt .= "\xff\x25" . pack('V', $disp & 0xFFFFFFFF);

        // nopl 0(%rax): 0f 1f 40 00
        $plt .= "\x0f\x1f\x40\x00";

        // PLT[N] entries — one per function
        foreach ($this->pltSymbols as $idx => $name) {
            $pltNAddr = $pltVAddr + self::PLT0_SIZE + $idx * self::PLTN_SIZE;
            $gotNAddr = $gotPltVAddr + (3 + $idx) * self::GOT_ENTRY_SIZE;

            // jmpq *GOT[3+N](%rip): ff 25 <disp32>
            $disp = $gotNAddr - ($pltNAddr + 6);
            $plt .= "\xff\x25" . pack('V', $disp & 0xFFFFFFFF);

            // pushq $reloc_index: 68 <imm32>
            $plt .= "\x68" . pack('V', $idx);

            // jmpq PLT[0]: e9 <disp32>
            $disp = $plt0Addr - ($pltNAddr + 16);
            $plt .= "\xe9" . pack('V', $disp & 0xFFFFFFFF);
        }

        return $plt;
    }

    /**
     * Build the .got.plt section bytes.
     * Initially, GOT entries for PLT symbols point back to the push instruction
     * in the corresponding PLT entry (for lazy binding).
     *
     * @param int $pltVAddr  Virtual address of .plt
     * @param int $dynamicVAddr  Virtual address of .dynamic section
     */
    public function buildGotPlt(int $pltVAddr, int $dynamicVAddr): string
    {
        if ($this->pltSymbols === []) {
            return '';
        }

        $got = '';

        // GOT[0] = address of .dynamic
        $got .= pack('P', $dynamicVAddr);
        // GOT[1] = 0 (link_map, filled by dynamic linker)
        $got .= pack('P', 0);
        // GOT[2] = 0 (_dl_runtime_resolve, filled by dynamic linker)
        $got .= pack('P', 0);

        // GOT[3+N] = PLT[N] push instruction address (lazy binding)
        foreach ($this->pltSymbols as $idx => $name) {
            $pushAddr = $pltVAddr + self::PLT0_SIZE + $idx * self::PLTN_SIZE + 6;
            $got .= pack('P', $pushAddr);
        }

        return $got;
    }

    /**
     * Build the .got section bytes (for GLOB_DAT data symbols).
     * All entries initialized to 0 (filled by dynamic linker).
     */
    public function buildGot(): string
    {
        return str_repeat("\0", count($this->gotDataSymbols) * self::GOT_ENTRY_SIZE);
    }

    /**
     * Build .rela.plt entries (R_X86_64_JUMP_SLOT).
     *
     * @param int $gotPltVAddr  Virtual address of .got.plt
     * @param array<string, int> $dynSymIndex  symbol name → index in .dynsym
     */
    public function buildRelaPlt(int $gotPltVAddr, array $dynSymIndex): string
    {
        $bytes = '';
        foreach ($this->pltSymbols as $idx => $name) {
            $gotEntryAddr = $gotPltVAddr + (3 + $idx) * self::GOT_ENTRY_SIZE;
            $symIdx = $dynSymIndex[$name] ?? 0;
            $rInfo = ($symIdx << 32) | 7; // R_X86_64_JUMP_SLOT = 7

            $bytes .= pack('P', $gotEntryAddr); // r_offset
            $bytes .= pack('P', $rInfo);         // r_info
            $bytes .= pack('P', 0);              // r_addend
        }
        return $bytes;
    }

    /**
     * Build .rela.dyn entries (R_X86_64_GLOB_DAT).
     *
     * @param int $gotVAddr  Virtual address of .got
     * @param array<string, int> $dynSymIndex  symbol name → index in .dynsym
     */
    public function buildRelaDyn(int $gotVAddr, array $dynSymIndex): string
    {
        $bytes = '';
        foreach ($this->gotDataSymbols as $idx => $name) {
            $gotEntryAddr = $gotVAddr + $idx * self::GOT_ENTRY_SIZE;
            $symIdx = $dynSymIndex[$name] ?? 0;
            $rInfo = ($symIdx << 32) | 6; // R_X86_64_GLOB_DAT = 6

            $bytes .= pack('P', $gotEntryAddr); // r_offset
            $bytes .= pack('P', $rInfo);         // r_info
            $bytes .= pack('P', 0);              // r_addend
        }
        return $bytes;
    }

    /**
     * Get the PLT entry virtual address for a given symbol.
     *
     * @param int $pltVAddr  Base virtual address of .plt
     */
    public function getPltEntryAddr(string $name, int $pltVAddr): int
    {
        $idx = $this->pltIndex[$name]
            ?? throw new \RuntimeException("No PLT entry for symbol: {$name}");
        return $pltVAddr + self::PLT0_SIZE + $idx * self::PLTN_SIZE;
    }

    /**
     * Get the GOT entry virtual address for a data symbol.
     *
     * @param int $gotVAddr  Base virtual address of .got
     */
    public function getGotEntryAddr(string $name, int $gotVAddr): int
    {
        $idx = $this->gotDataIndex[$name]
            ?? throw new \RuntimeException("No GOT entry for symbol: {$name}");
        return $gotVAddr + $idx * self::GOT_ENTRY_SIZE;
    }

    /**
     * Check if a symbol has a PLT entry.
     */
    public function hasPltEntry(string $name): bool
    {
        return isset($this->pltIndex[$name]);
    }
}
