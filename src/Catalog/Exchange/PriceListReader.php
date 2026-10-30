<?php

namespace App\Catalog\Exchange;

/**
 * Reads a price list row by row, without loading the whole file: CSV with fgetcsv() on the stream,
 * XML through XMLReader, one <produkt> element at a time.
 *
 * CSV is read the way spreadsheets write it in Poland: separated by semicolons, commas or tabs
 * (whichever the header uses most), with or without a UTF-8 byte order mark, in UTF-8 or
 * Windows-1250 (what Excel calls "CSV" here).
 *
 * XML is read without network access and without expanding entities, so a crafted file cannot make
 * the server read its own files or call addresses inside its network (XXE).
 */
final class PriceListReader
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param resource $stream
     *
     * @return iterable<int, array<string, string>> line of the row in the file => column => raw text;
     *                                              only the columns of PriceListColumns, others are left out
     *
     * @throws InvalidPriceList when the file as a whole cannot be read
     */
    public function rows($stream, PriceListFormat $format): iterable
    {
        return match ($format) {
            PriceListFormat::Csv => $this->csv($stream),
            PriceListFormat::Xml => $this->xml($stream),
        };
    }

    /**
     * @param resource $stream
     *
     * @return \Generator<int, array<string, string>>
     */
    private function csv($stream): \Generator
    {
        $header = fgets($stream);
        if (false === $header || '' === trim($header)) {
            throw new InvalidPriceList('Plik jest pusty.');
        }

        $header = self::utf8(str_starts_with($header, self::BOM) ? substr($header, 3) : $header);
        $separator = self::separator($header);
        $columns = self::columns(str_getcsv(rtrim($header, "\r\n"), $separator, '"', ''));

        $line = 1;
        while (false !== ($fields = fgetcsv($stream, null, $separator, '"', ''))) {
            ++$line;
            // An empty line between rows is skipped, not an error
            if ([null] === $fields || [''] === array_map('trim', array_map('strval', $fields))) {
                continue;
            }

            $row = [];
            foreach ($columns as $position => $column) {
                $row[$column] = self::utf8(trim((string) ($fields[$position] ?? '')));
            }

            yield $line => $row;
        }
    }

    /**
     * @param resource $stream
     *
     * @return \Generator<int, array<string, string>>
     */
    private function xml($stream): \Generator
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $reader = \XMLReader::fromStream($stream, flags: \LIBXML_NONET);
            $seen = false;

            // Down to the first <produkt>, then from one to the next sibling of that name: next() skips
            // over the element's content, which read() would walk through node by node
            while ($reader->read() && !self::isProduct($reader)) {
            }

            while (self::isProduct($reader)) {
                $node = $reader->expand();
                if (!$node instanceof \DOMElement) {
                    break;
                }

                $seen = true;
                $row = [];
                foreach ($node->childNodes as $child) {
                    $column = $child instanceof \DOMElement ? PriceListColumns::fromHeader((string) $child->localName) : null;
                    if (null !== $column) {
                        $row[$column] = trim($child->textContent);
                    }
                }

                yield $node->getLineNo() => $row;

                if (!$reader->next('produkt')) {
                    break;
                }
            }

            $error = libxml_get_last_error();
            if (false !== $error) {
                throw new InvalidPriceList(\sprintf('Błędny XML w wierszu %d: %s', $error->line, trim($error->message)));
            }
            if (!$seen) {
                throw new InvalidPriceList('W pliku XML nie ma żadnego elementu <produkt>.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function isProduct(\XMLReader $reader): bool
    {
        return \XMLReader::ELEMENT === $reader->nodeType && 'produkt' === $reader->localName;
    }

    /**
     * @param list<string|null> $headers
     *
     * @return array<int, string> position in the row => column; unknown headers are left out
     */
    private static function columns(array $headers): array
    {
        $columns = [];
        foreach ($headers as $position => $header) {
            $column = PriceListColumns::fromHeader((string) $header);
            if (null !== $column) {
                $columns[$position] = $column;
            }
        }

        $missing = array_diff(PriceListColumns::REQUIRED, $columns);
        if ([] !== $missing) {
            throw new InvalidPriceList(\sprintf('W nagłówku brakuje kolumn: %s. Pierwszy wiersz pliku musi zawierać nazwy kolumn, np. %s.', implode(', ', $missing), implode(';', PriceListColumns::ALL)));
        }

        return $columns;
    }

    private static function separator(string $header): string
    {
        // With no separator at all (a single column) the sort keeps ";" first
        $counts = [';' => substr_count($header, ';'), ',' => substr_count($header, ','), "\t" => substr_count($header, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Text in UTF-8: Windows-1250 is converted, UTF-8 is left as it is.
     */
    private static function utf8(string $text): string
    {
        // iconv, because mbstring does not know Windows-1250
        return mb_check_encoding($text, 'UTF-8') ? $text : (string) iconv('WINDOWS-1250', 'UTF-8', $text);
    }
}
