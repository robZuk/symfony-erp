<?php

namespace App\Tests\Support;

/**
 * Failed sign-ins are counted in a cache that outlives a test. Tests that sign in through the form
 * start by forgetting the count, so attempts made by earlier tests cannot lock them out.
 */
trait ForgetsLoginAttempts
{
    private function forgetLoginAttempts(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
    }
}
