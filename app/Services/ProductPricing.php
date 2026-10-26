<?php

namespace App\Services;

/** The existing MKPOS base-unit automatic pricing formula, shared with diagnostics. */
class ProductPricing
{
    public static function automatic(int|float|string $cost, object $rule): int
    {
        $price = max(ceil($cost * (1 + $rule->markup_percent / 100)), $cost + $rule->minimum_profit);

        return (int) (ceil($price / max($rule->rounding, 1)) * max($rule->rounding, 1));
    }
}
