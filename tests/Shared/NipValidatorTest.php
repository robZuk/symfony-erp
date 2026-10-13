<?php

namespace App\Tests\Shared;

use App\Shared\Validator\NipValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NipValidatorTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function testAcceptsNumbersWithCorrectChecksum(string $nip): void
    {
        self::assertTrue(NipValidator::isValid($nip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNumbers(): iterable
    {
        yield 'plain digits' => ['1234563218'];
        yield 'another number' => ['5260250274'];
        yield 'with dashes' => ['123-456-32-18'];
        yield 'with country prefix and spaces' => ['PL 123 456 32 18'];
    }

    #[DataProvider('invalidNumbers')]
    public function testRejectsEverythingElse(string $nip): void
    {
        self::assertFalse(NipValidator::isValid($nip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'wrong checksum' => ['1234563219'];
        yield 'too short' => ['123456321'];
        yield 'too long' => ['12345632180'];
        yield 'letters' => ['12345632AB'];
        yield 'foreign prefix' => ['DE1234563218'];
        // 6*1 + 5*2 + 7*3 + 2*4 + 3*5 + 4*6 + 5*7 + 6*8 + 7*9 = 230, and 230 % 11 = 10
        yield 'checksum of ten cannot be a digit' => ['1234567890'];
    }

    public function testNormalizeLeavesOnlyDigits(): void
    {
        self::assertSame('1234563218', NipValidator::normalize(' pl 123-456-32-18 '));
    }
}
