<?php

namespace App\Shared\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Last number issued for a document series in a given month.
 * Rows are written only by DocumentNumberGenerator; the entity exists so migrations know the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shared_document_sequence')]
class DocumentSequence
{
    #[ORM\Id]
    #[ORM\Column(length: 10)]
    private string $series;

    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $year;

    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $month;

    #[ORM\Column]
    private int $lastNumber = 0;

    public function __construct(string $series, int $year, int $month)
    {
        $this->series = $series;
        $this->year = $year;
        $this->month = $month;
    }

    public function getLastNumber(): int
    {
        return $this->lastNumber;
    }
}
