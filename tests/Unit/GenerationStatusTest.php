<?php

use App\Enums\GenerationStatus as S;

it('maps the four Pixelforge statuses', function (string $provider, S $expected) {
    expect(S::fromProvider($provider))->toBe($expected);
})->with([
    ['queued', S::Queued],
    ['processing', S::Processing],
    ['succeeded', S::Succeeded],
    ['failed', S::Failed],
]);

it('refuses statuses Pixelforge never sends', function (string $status) {
    S::fromProvider($status);
})->throws(UnexpectedValueException::class)->with(['pending', 'rejected', 'cancelled', '']);

it('only moves forward', function (S $from, S $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    // Normal life
    [S::Pending, S::Queued, true],
    [S::Queued, S::Processing, true],
    [S::Processing, S::Succeeded, true],
    [S::Processing, S::Failed, true],
    // Skipping a step is fine: a webhook or replay can report a later status first
    [S::Pending, S::Processing, true],
    [S::Pending, S::Succeeded, true],
    [S::Queued, S::Succeeded, true],
    [S::Queued, S::Failed, true],
    [S::Pending, S::Rejected, true],
    // Out-of-order events never move backwards
    [S::Processing, S::Queued, false],
    [S::Succeeded, S::Processing, false],
    [S::Succeeded, S::Queued, false],
    [S::Failed, S::Processing, false],
    // Final states are final
    [S::Succeeded, S::Failed, false],
    [S::Failed, S::Succeeded, false],
    [S::Succeeded, S::Succeeded, false],
    // Once Pixelforge has accepted it, it cannot be locally rejected
    [S::Queued, S::Rejected, false],
    [S::Processing, S::Rejected, false],
    [S::Succeeded, S::Rejected, false],
    // Provider truth beats our local give-up (it exists and may be billed)
    [S::Rejected, S::Succeeded, true],
    [S::Rejected, S::Processing, true],
    [S::Rejected, S::Pending, false],
    // Nothing ever goes back to pending
    [S::Queued, S::Pending, false],
    [S::Pending, S::Pending, false],
]);

it('knows which statuses are terminal and in flight', function () {
    expect(array_values(array_map(fn (S $s) => $s->value, array_filter(S::cases(), fn (S $s) => $s->isTerminal()))))
        ->toEqualCanonicalizing(['succeeded', 'failed', 'rejected'])
        ->and(array_values(array_map(fn (S $s) => $s->value, array_filter(S::cases(), fn (S $s) => $s->isInFlight()))))
        ->toEqualCanonicalizing(['queued', 'processing']);
});
