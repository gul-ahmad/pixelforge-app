<?php

use App\Services\Pixelforge\WebhookSignature;

const SIG_SECRET = 'whsec_unit_secret';
const SIG_NOW = 1_762_265_009;
const SIG_BODY = '{"id":"evt_1","type":"generation.succeeded"}';

function signatureHeader(int $timestamp = SIG_NOW, string $body = SIG_BODY, string $secret = SIG_SECRET): string
{
    return "t={$timestamp},v1=".WebhookSignature::sign($secret, $timestamp, $body);
}

function verifier(): WebhookSignature
{
    return new WebhookSignature(SIG_SECRET, 300);
}

it('accepts a correctly signed request', function () {
    expect(verifier()->isValid(SIG_BODY, signatureHeader(), SIG_NOW))->toBeTrue();
});

it('matches the documented algorithm: hex HMAC-SHA256 of "{t}.{body}"', function () {
    expect(WebhookSignature::sign(SIG_SECRET, SIG_NOW, SIG_BODY))
        ->toBe(hash_hmac('sha256', SIG_NOW.'.'.SIG_BODY, SIG_SECRET))
        ->toMatch('/^[0-9a-f]{64}$/');
});

it('rejects a signature made with another secret', function () {
    expect(verifier()->isValid(SIG_BODY, signatureHeader(secret: 'whsec_attacker'), SIG_NOW))->toBeFalse();
});

it('rejects a tampered body', function () {
    $tampered = str_replace('succeeded', 'failed', SIG_BODY);

    expect(verifier()->isValid($tampered, signatureHeader(), SIG_NOW))->toBeFalse();
});

it('rejects a re-encoded body: only the exact raw bytes verify', function () {
    // Same JSON meaning, different bytes (spacing). This is why we never re-encode.
    $reencoded = '{"id": "evt_1", "type": "generation.succeeded"}';

    expect(verifier()->isValid($reencoded, signatureHeader(), SIG_NOW))->toBeFalse();
});

it('rejects a timestamp that was changed after signing', function () {
    $signature = WebhookSignature::sign(SIG_SECRET, SIG_NOW, SIG_BODY);

    expect(verifier()->isValid(SIG_BODY, 't='.(SIG_NOW + 10).",v1={$signature}", SIG_NOW))->toBeFalse();
});

it('enforces the 300-second window in both directions (replay protection)', function (int $age, bool $valid) {
    $signedAt = SIG_NOW - $age;

    expect(verifier()->isValid(SIG_BODY, signatureHeader($signedAt), SIG_NOW))->toBe($valid);
})->with([
    'just now' => [0, true],
    'exactly 300s old' => [300, true],
    '301s old' => [301, false],
    'an hour old' => [3600, false],
    '301s in the future' => [-301, false],
]);

it('rejects missing or malformed headers', function (?string $header) {
    expect(verifier()->isValid(SIG_BODY, $header, SIG_NOW))->toBeFalse();
})->with([
    'missing' => [null],
    'empty' => [''],
    'no v1' => ['t='.SIG_NOW],
    'no t' => ['v1=abc'],
    'non-numeric t' => ['t=abc,v1=abc'],
    'garbage' => ['nonsense'],
]);

it('tolerates spaces after the comma', function () {
    $signature = WebhookSignature::sign(SIG_SECRET, SIG_NOW, SIG_BODY);

    expect(verifier()->isValid(SIG_BODY, 't='.SIG_NOW.", v1={$signature}", SIG_NOW))->toBeTrue();
});
