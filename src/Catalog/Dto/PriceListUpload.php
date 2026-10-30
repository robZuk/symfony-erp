<?php

namespace App\Catalog\Dto;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The price list file sent with the import form.
 */
final class PriceListUpload
{
    #[Assert\NotNull(message: 'Wybierz plik.')]
    #[Assert\File(
        maxSize: '5M',
        // The content is checked too, not only the name; spreadsheets save CSV as plain text
        extensions: [
            'csv' => ['text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'],
            'xml' => ['application/xml', 'text/xml', 'text/plain'],
        ],
        maxSizeMessage: 'Plik jest za duży ({{ size }} {{ suffix }}). Najwyżej {{ limit }} {{ suffix }}.',
        extensionsMessage: 'Wgraj plik CSV albo XML.',
    )]
    public ?UploadedFile $file = null;
}
