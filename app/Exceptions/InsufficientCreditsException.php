<?php

namespace App\Exceptions;

use App\Support\Pricing;
use RuntimeException;

/** The user's balance doesn't cover the maximum this generation could cost. */
class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly int $neededMicroUsd, public readonly int $balanceMicroUsd)
    {
        parent::__construct(sprintf(
            'This generation can cost up to %s, but your balance is %s. Buy credits to continue.',
            Pricing::formatMicroUsd($neededMicroUsd),
            Pricing::formatMicroUsd($balanceMicroUsd),
        ));
    }
}
