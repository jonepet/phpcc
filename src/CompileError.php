<?php

declare(strict_types=1);

namespace Cppc;

class CompileError extends \RuntimeException
{
    public readonly string $sourceFile;
    public readonly int $sourceLine;
    public readonly int $sourceColumn;

    public function __construct(
        string $message,
        string $file = '',
        int $line = 0,
        int $column = 0,
    ) {
        $this->sourceFile = $file;
        $this->sourceLine = $line;
        $this->sourceColumn = $column;

        $location = '';
        if ($file) {
            $location = "{$file}:";
            if ($line > 0) {
                $location .= "{$line}:";
                if ($column > 0) {
                    $location .= "{$column}:";
                }
            }
            $location .= ' ';
        }
        parent::__construct("{$location}{$message}");
    }
}
