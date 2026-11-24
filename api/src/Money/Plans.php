<?php
declare(strict_types=1);

namespace App\Money;

/** What each household plan allows. Only the free plan is limited so far. */
final class Plans
{
    /** How many goals a kid can have at once (requested, active, or reached but not yet used). */
    public static function maxActiveGoals(string $plan): int
    {
        return $plan === 'free' ? 1 : 25;
    }
}
