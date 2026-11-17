<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/** Pixelforge pricing could not be loaded and no earlier copy exists, so we cannot quote a price. */
class PricingUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Pricing is temporarily unavailable. Please try again in a minute.', 0, $previous);
    }
}
