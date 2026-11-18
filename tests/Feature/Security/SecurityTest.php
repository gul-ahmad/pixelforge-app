<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('sends browser security headers on every page', function (string $url) {
    $response = $this->get($url);

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
})->with(['/', '/login', '/register']);

it('requires sign-in for every app page', function (string $method, string $url) {
    Http::fake();

    $this->call($method, $url)->assertRedirect(route('login'));
    Http::assertNothingSent();
})->with([
    ['GET', '/generations'],
    ['GET', '/generations/create'],
    ['GET', '/generations/estimate?characters=1&reference_images=0&size=1024x1024&quality=low'],
    ['POST', '/generations'],
    ['GET', '/credits'],
    ['POST', '/credits'],
    ['GET', '/admin/report'],
]);

it('never shows one user another user\'s generations', function () {
    $mine = Generation::factory()->create(['prompt' => 'mine']);
    Generation::factory()->create(['prompt' => 'secret of someone else']);

    $this->actingAs($mine->user)->get(route('generations.index'))
        ->assertOk()
        ->assertDontSee('secret of someone else');
});

it('does not expose secrets or internal costs in shared page data', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create();

    $props = $this->actingAs($user)->get(route('generations.index'))->viewData('page')['props'];
    $json = json_encode($props);

    expect($json)->not->toContain('pf_test_key')
        ->not->toContain('whsec_test_secret')
        ->not->toContain('provider_cost_nano_usd')
        ->not->toContain('idempotency_key')
        ->not->toContain('"password"')
        ->not->toContain('two_factor_secret');
});

it('rejects webhooks signed with the wrong secret even through the public route', function () {
    $body = json_encode(['id' => 'evt_x', 'type' => 'ping', 'data' => []]);
    $t = time();

    $this->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'whsec_guess'),
    ], content: $body)->assertStatus(400);
});
