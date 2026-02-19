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
                );
                $merged->symbols[] = $adjusted;
                $this->symbols[$sym->name] = $adjusted;
            }

            // Merge relocations (adjust offsets)
            foreach ($sec->relocs as $reloc) {
                $merged->relocs[] = new Relocation(
                    $reloc->section,
                    $reloc->offset + $baseOffset,
                    $reloc->type,
                    $reloc->target,
                    $reloc->addend,
                );
            }
        }
    }

    /**
     * Resolve all relocations given final virtual addresses.
     *
     * @param array<string, int> $sectionVAddrs section name → virtual address
     */
    public function resolve(array $sectionVAddrs): void
    {
        // Build symbol → virtual address map
        $symAddrs = [];
        foreach ($this->symbols as $name => $sym) {
            if (isset($sectionVAddrs[$sym->section])) {
                $symAddrs[$name] = $sectionVAddrs[$sym->section] + $sym->offset;
            }
        }

        // Patch all relocations
        foreach ($this->sections as $secName => $sec) {
            $secVAddr = $sectionVAddrs[$secName] ?? 0;

            foreach ($sec->relocs as $reloc) {
                $targetAddr = $symAddrs[$reloc->target]
                    ?? throw new \RuntimeException("Undefined symbol: {$reloc->target}");

                $patchOffset = $reloc->offset;

                if ($reloc->type === 'REL32') {
                    // rel32 = target - (patch_vaddr + 4)
                    $patchVAddr = $secVAddr + $patchOffset;
                    $rel = ($targetAddr + $reloc->addend) - ($patchVAddr + 4);
                    $this->patchLE32($sec, $patchOffset, $rel);
                } elseif ($reloc->type === 'ABS64') {
                    $this->patchLE64($sec, $patchOffset, $targetAddr + $reloc->addend);
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
