<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| The drill itself needs a running server and real workers, so it's run by hand
| (see README "Webhook drill"). These tests cover its safety guards only.
*/

it('refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');
    Http::fake();

    $this->artisan('pixelforge:webhook-drill')
        ->expectsOutputToContain('Refusing to run in production')
        ->assertFailed();

    Http::assertNothingSent();
    expect(User::count())->toBe(0)->and(Generation::count())->toBe(0);
});

it('refuses when the queue already has jobs, so it only processes its own', function () {
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    Http::fake();

    $this->artisan('pixelforge:webhook-drill')
        ->expectsOutputToContain('The queue is not empty')
        ->assertFailed();

    Http::assertNothingSent();
    expect(User::count())->toBe(0);
});
