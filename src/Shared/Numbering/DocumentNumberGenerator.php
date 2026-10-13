<?php

namespace App\Shared\Numbering;

use Doctrine\DBAL\Connection;

/**
 * Issues consecutive document numbers such as PZ/2026/10/0001, counted separately
 * for every series and month.
 *
 * Call it inside the transaction that saves the document. The counter row stays locked
 * until that transaction ends, so two documents never get the same number, and a rollback
 * gives the number back, so there are no gaps.
 */
final class DocumentNumberGenerator
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function next(string $series, \DateTimeImmutable $date): string
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Document numbers must be issued inside a transaction.');
        }

        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        // PostgreSQL upsert: creates the counter at 1 or increments it, atomically
        $number = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO shared_document_sequence (series, year, month, last_number)
                VALUES (:series, :year, :month, 1)
                ON CONFLICT (series, year, month)
                DO UPDATE SET last_number = shared_document_sequence.last_number + 1
                RETURNING last_number
                SQL,
            ['series' => $series, 'year' => $year, 'month' => $month],
        );

        return \sprintf('%s/%04d/%02d/%04d', $series, $year, $month, $number);
    }
}
