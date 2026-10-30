<?php

namespace App\Partner\Vies;

/**
 * VIES could not answer: the service or a member state's register is down, busy, or too slow.
 * Not an answer about the VAT number, so it is never cached; trying again later usually works.
 */
final class ViesUnavailable extends \RuntimeException
{
}
