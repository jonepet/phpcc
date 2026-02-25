<?php

declare(strict_types=1);

namespace Cppc\Assembler;

/**
 * Reads Unix AR archive files (.a static libraries).
 *
 * AR format:
 *   "!<arch>\n" magic (8 bytes)
 *   Then member entries, each with:
 *     60-byte header + file data (padded to 2-byte boundary)
 *
 * Member header (60 bytes):
 *   ar_name[16]   — name (/ terminated, or /offset for long names)
 *   ar_date[12]   — modification time
 *   ar_uid[6]     — user ID
 *   ar_gid[6]     — group ID
 *   ar_mode[8]    — file mode (octal)
 *   ar_size[12]   — file size in bytes
 *   ar_fmag[2]    — "`\n" (0x60 0x0A)
 */
class ArReader
{
    private const MAGIC = "!<arch>\n";
    private const HEADER_SIZE = 60;

    /**
     * Read an AR archive and return member name → raw bytes mapping.
     *
     * @return array<string, string> member name → file contents
     */
    public function read(string $archiveBytes): array
    {
        if (substr($archiveBytes, 0, 8) !== self::MAGIC) {
            throw new \RuntimeException('Not a valid AR archive');
        }

        $members = [];
        $longNames = '';
        $offset = 8; // Skip magic
        $len = strlen($archiveBytes);

        while ($offset + self::HEADER_SIZE <= $len) {
            // Parse header
            $name    = substr($archiveBytes, $offset, 16);
            $sizeStr = substr($archiveBytes, $offset + 48, 10);
            $fmag    = substr($archiveBytes, $offset + 58, 2);

            if ($fmag !== "`\n") {
                break; // Invalid header
            }

            $size = (int)trim($sizeStr);
            $dataOffset = $offset + self::HEADER_SIZE;
            $data = substr($archiveBytes, $dataOffset, $size);

            // Parse name
            $name = rtrim($name);

            if ($name === '/') {
                // Symbol table (index) — skip
                $offset = $dataOffset + $size;
                if ($size % 2 !== 0) $offset++; // pad to 2-byte boundary
                continue;
            }

            if ($name === '//') {
                // GNU long-name table
                $longNames = $data;
                $offset = $dataOffset + $size;
                if ($size % 2 !== 0) $offset++;
                continue;
            }

            // Resolve name
            if (str_starts_with($name, '/') && $longNames !== '') {
                // Long name: /offset in long-name table
                $nameOff = (int)substr($name, 1);
                $end = strpos($longNames, "/\n", $nameOff);
                if ($end === false) {
                    $end = strpos($longNames, "\n", $nameOff);
                }
                $name = substr($longNames, $nameOff, $end !== false ? $end - $nameOff : null);
                $name = rtrim($name, "/");
            } else {
                // Short name: remove trailing /
                $name = rtrim($name, '/');
            }

            $members[$name] = $data;

            // Advance to next member (padded to 2-byte boundary)
            $offset = $dataOffset + $size;
            if ($size % 2 !== 0) {
                $offset++;
            }
        }

        return $members;
    }

    /**
     * Read an AR archive from a file.
     *
     * @return array<string, string>
     */
    public function readFile(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read archive: {$path}");
        }
        return $this->read($bytes);
    }
}
