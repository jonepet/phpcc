<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class Linker
{
    /** @var SectionData[] indexed by section name */
    private array $sections = [];
    /** @var array<string, Symbol> */
    private array $symbols = [];
    /** @var string[] */
    private array $globals = [];
    /** @var array<string, true> Symbols that are dynamically resolved (PLT/GOT) */
    private array $dynamicSymbols = [];
    /** @var array<string, true> Undefined symbols (not resolved locally or dynamically) */
    private array $undefinedSymbols = [];

    /**
     * Add an encoded module (set of sections) to the linker.
     *
     * @param SectionData[] $sections
     * @param string[] $globals
     */
    public function addModule(array $sections, array $globals): void
    {
        foreach ($globals as $g) {
            $this->globals[] = $g;
        }

        // Compute base offsets for all sections in this module BEFORE merging.
        // Used to adjust addends for section-relative relocations.
        $sectionBaseOffsets = [];
        foreach ($sections as $sec) {
            $sectionBaseOffsets[$sec->name] = isset($this->sections[$sec->name])
                ? strlen($this->sections[$sec->name]->bytes)
                : 0;
        }

        foreach ($sections as $sec) {
            if (!isset($this->sections[$sec->name])) {
                $this->sections[$sec->name] = new SectionData($sec->name);
            }

            $merged = $this->sections[$sec->name];
            $baseOffset = strlen($merged->bytes);

            // Merge bytes
            $merged->bytes .= $sec->bytes;

            // Merge symbols (adjust offsets)
            foreach ($sec->symbols as $sym) {
                $adjusted = new Symbol(
                    $sym->name,
                    $sym->section,
                    $sym->offset + $baseOffset,
                    in_array($sym->name, $this->globals, true),
                    $sym->type,
                    $sym->size,
                );
                $merged->symbols[] = $adjusted;
                $this->symbols[$sym->name] = $adjusted;
            }

            // Merge relocations (adjust offsets)
            foreach ($sec->relocs as $reloc) {
                $adjustedAddend = $reloc->addend;
                // For section-relative relocations (target is a section name like .rodata),
                // adjust the addend by the target section's base offset in the merged output.
                if (isset($sectionBaseOffsets[$reloc->target])) {
                    $adjustedAddend += $sectionBaseOffsets[$reloc->target];
                }

                $merged->relocs[] = new Relocation(
                    $reloc->section,
                    $reloc->offset + $baseOffset,
                    $reloc->type,
                    $reloc->target,
                    $adjustedAddend,
                );
            }
        }
    }

    /**
     * Mark a symbol as dynamically resolved (via PLT/GOT).
     */
    public function markDynamic(string $name): void
    {
        $this->dynamicSymbols[$name] = true;
    }

    /**
     * Check if a symbol is defined locally.
     */
    public function isDefined(string $name): bool
    {
        return isset($this->symbols[$name]);
    }

    /**
     * Check if a symbol is marked as dynamic.
     */
    public function isDynamic(string $name): bool
    {
        return isset($this->dynamicSymbols[$name]);
    }

    /**
     * Add a copy relocation symbol — allocates space in .bss for an external
     * data symbol (e.g., stderr, stdout). The linker resolves REL32 relocations
     * to this BSS address; the dynamic linker copies the actual value at load time.
     */
    public function addCopySymbol(string $name, int $bssOffset): void
    {
        if (!isset($this->sections['.bss'])) {
            $this->sections['.bss'] = new SectionData('.bss');
        }
        $sym = new Symbol($name, '.bss', $bssOffset, true, 'object');
        $this->sections['.bss']->symbols[] = $sym;
        $this->symbols[$name] = $sym;
    }

    /**
     * Extend .bss to at least the given size (for copy relocation allocations).
     */
    public function extendBss(int $minSize): void
    {
        if (!isset($this->sections['.bss'])) {
            $this->sections['.bss'] = new SectionData('.bss');
        }
        $currentSize = strlen($this->sections['.bss']->bytes);
        if ($minSize > $currentSize) {
            $this->sections['.bss']->bytes .= str_repeat("\0", $minSize - $currentSize);
        }
    }

    /**
     * Find all undefined symbols referenced by relocations but not defined locally.
     * Excludes section names (section-relative relocations are resolved via section addresses).
     *
     * @return string[]
     */
    public function findUndefinedSymbols(): array
    {
        $undefined = [];
        foreach ($this->sections as $sec) {
            foreach ($sec->relocs as $reloc) {
                $target = $reloc->target;
                // Skip section-relative targets (resolved from section vaddrs)
                if (isset($this->sections[$target])) {
                    continue;
                }
                if (!isset($this->symbols[$target]) && !isset($undefined[$target])) {
                    $undefined[$target] = true;
                }
            }
        }
        return array_keys($undefined);
    }

    /**
     * Resolve all relocations given final virtual addresses.
     * Dynamic symbols are resolved to their PLT entries or GOT entries.
     *
     * @param array<string, int> $sectionVAddrs section name → virtual address
     * @param array<string, int> $pltAddrs symbol name → PLT entry address (for dynamic calls)
     * @param array<string, int> $gotAddrs symbol name → GOT entry address (for data references)
     * @param bool $isSharedLibrary When true, ABS64 relocs in .data for dynamic symbols are
     *                              left unpatched (the dynamic linker will fill via GLOB_DAT).
     */
    public function resolve(array $sectionVAddrs, array $pltAddrs = [], array $gotAddrs = [], bool $isSharedLibrary = false): void
    {
        // Build symbol → virtual address map
        $symAddrs = [];
        foreach ($this->symbols as $name => $sym) {
            if (isset($sectionVAddrs[$sym->section])) {
                $symAddrs[$name] = $sectionVAddrs[$sym->section] + $sym->offset;
            }
        }

        // Add section base addresses as pseudo-symbols (for section-relative relocations)
        foreach ($sectionVAddrs as $secName => $vaddr) {
            if (!isset($symAddrs[$secName])) {
                $symAddrs[$secName] = $vaddr;
            }
        }

        // Patch all relocations
        foreach ($this->sections as $secName => $sec) {
            $secVAddr = $sectionVAddrs[$secName] ?? 0;

            foreach ($sec->relocs as $reloc) {
                $target = $reloc->target;
                $patchOffset = $reloc->offset;
                $patchVAddr = $secVAddr + $patchOffset;

                if ($reloc->type === 'GOTPCREL') {
                    // GOTPCREL: GOT entry address (RIP-relative)
                    // Used for PIC code accessing data or function pointers
                    if (isset($gotAddrs[$target])) {
                        $targetAddr = $gotAddrs[$target];
                        // rel32 = got_entry_addr - (patch_vaddr + 4)
                        $rel = $targetAddr - ($patchVAddr + 4);
                        $this->patchLE32($sec, $patchOffset, $rel);
                    } elseif (isset($this->dynamicSymbols[$target])) {
                        // Symbol will be resolved by dynamic linker; skip static resolution
                        continue;
                    } else {
                        throw new \RuntimeException("Undefined symbol in GOTPCREL: {$target}");
                    }
                    continue;
                }

                // Check for PLT resolution first (dynamic symbols)
                if (isset($pltAddrs[$target])) {
                    $targetAddr = $pltAddrs[$target];
                } elseif (isset($symAddrs[$target])) {
                    $targetAddr = $symAddrs[$target];
                } elseif (isset($this->dynamicSymbols[$target])) {
                    // Dynamic symbol without PLT address — skip (handled by dynamic linker)
                    continue;
                } else {
                    throw new \RuntimeException("Undefined symbol: {$target}");
                }

                if ($reloc->type === 'REL32' || $reloc->type === 'PLT32') {
                    // rel32 = target - (patch_vaddr + 4)
                    $rel = ($targetAddr + $reloc->addend) - ($patchVAddr + 4);
                    $this->patchLE32($sec, $patchOffset, $rel);
                } elseif ($reloc->type === 'ABS64') {
                    // In a shared library (ET_DYN, PIC base=0), direct data-section references
                    // to dynamic symbols must be filled by the dynamic linker via GLOB_DAT.
                    // Patching with a PLT address here would embed a wrong compile-time address.
                    if ($isSharedLibrary && $this->isDynamic($target) && $secName === '.data') {
                        // Leave slot at 0; dynamic linker fills via .rela.dyn GLOB_DAT.
                    } else {
                        $this->patchLE64($sec, $patchOffset, $targetAddr + $reloc->addend);
                    }
                } elseif ($reloc->type === '32S') {
                    // Sign-extended 32-bit absolute
                    $this->patchLE32($sec, $patchOffset, ($targetAddr + $reloc->addend) & 0xFFFFFFFF);
                }
            }
        }
    }

    /**
     * @return array<string, SectionData>
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * @return array<string, Symbol>
     */
    public function getSymbols(): array
    {
        return $this->symbols;
    }

    private function patchLE32(SectionData $sec, int $offset, int $val): void
    {
        $packed = pack('V', $val & 0xFFFFFFFF);
        $sec->bytes[$offset]     = $packed[0];
        $sec->bytes[$offset + 1] = $packed[1];
        $sec->bytes[$offset + 2] = $packed[2];
        $sec->bytes[$offset + 3] = $packed[3];
    }

    private function patchLE64(SectionData $sec, int $offset, int $val): void
    {
        $packed = pack('P', $val);
        for ($i = 0; $i < 8; $i++) {
            $sec->bytes[$offset + $i] = $packed[$i];
        }
    }
}
