<?php

namespace App\Partner\Vies;

/**
 * Checks VAT numbers against the EU register of businesses that trade across borders.
 */
interface VatRegistry
{
    /**
     * @param string $countryCode two letters, e.g. "PL"
     * @param string $vatNumber   without the country prefix, e.g. "5260250274"
     *
     * @throws ViesUnavailable when there is no answer, as opposed to an answer that the number is not registered
     */
    public function check(string $countryCode, string $vatNumber): VatCheck;
}
