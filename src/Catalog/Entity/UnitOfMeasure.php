<?php

namespace App\Catalog\Entity;

enum UnitOfMeasure: string
{
    case Piece = 'szt';
    case Kilogram = 'kg';
    case Meter = 'm';
    case Liter = 'l';
    case Package = 'opak';
    case Hour = 'godz';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'szt.',
            self::Kilogram => 'kg',
            self::Meter => 'm',
            self::Liter => 'l',
            self::Package => 'opak.',
            self::Hour => 'godz.',
        };
    }
}
