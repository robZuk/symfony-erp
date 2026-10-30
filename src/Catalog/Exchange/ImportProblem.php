<?php

namespace App\Catalog\Exchange;

/**
 * What is wrong with one value of one row, or with the file as a whole (no line, no column).
 */
final readonly class ImportProblem
{
    public function __construct(
        public ?int $line,
        public ?string $column,
        public string $message,
    ) {
    }

    /**
     * @return array{line: ?int, column: ?string, message: string}
     */
    public function toArray(): array
    {
        return ['line' => $this->line, 'column' => $this->column, 'message' => $this->message];
    }
}
