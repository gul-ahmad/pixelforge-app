<?php

namespace App\Actions\Admin;

/**
 * One line of the spend report's raw data: everything one user's succeeded generations
 * cost on one UTC completion day. Integers only.
 */
final readonly class SpendRow
{
    public function __construct(
        public string $day,
        public ?int $userId,
        public int $generations,
        public int $chargedMicroUsd,
        public int $costNanoUsd,
    ) {}
}
