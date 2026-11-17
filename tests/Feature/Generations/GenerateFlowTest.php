<?php

use App\Actions\Pricing\GetPricingSheet;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

const PRICING_URL = 'https://pixelforge.test/v1/pricing';

beforeEach(function () {
    Queue::fake();
    Http::fake([PRICING_URL => Http::response(pixelforgeFixture('pricing'))]);
});

function validInput(array $overrides = []): array
{
    return [
        'client_request_id' => (string) Str::uuid(),
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/1.png', 'https://example.com/2.png'],
        ...$overrides,
    ];
}

describe('generate page', function () {
    it('requires sign in', function () {
        $this->get(route('generations.create'))->assertRedirect(route('login'));
        $this->post(route('generations.store'), validInput())->assertRedirect(route('login'));
    });

    it('shows the options and a starting estimate', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('generations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Create')
                ->has('sizes', 3)
                ->has('qualities', 3)
                ->where('limits.prompt', 4000)
                ->where('limits.reference_images', 4)
                ->where('initialEstimate.min', '$0.058125')
                ->where('clientRequestId', fn (string $id) => Str::isUuid($id))
            );
    });

    it('still renders when pricing is unavailable', function () {
        Http::fake([PRICING_URL => Http::failedConnection()]);

        $this->actingAs(User::factory()->create())
            ->get(route('generations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('initialEstimate', null));
    });
});

describe('submitting', function () {
    it('saves a pending generation with its estimate and queues the submission', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('generations.store'), validInput())
            ->assertRedirect(route('generations.index'));

        $generation = Generation::sole();
        expect($generation->user_id)->toBe($user->id)
            ->and($generation->status)->toBe(GenerationStatus::Pending)
            ->and($generation->idempotency_key)->toBeUuid()
            ->and($generation->reference_images)->toBe(['https://example.com/1.png', 'https://example.com/2.png'])
            // The docs' worked example: $0.357781 … $0.467344 including markup.
            ->and($generation->estimated_min_micro_usd)->toBe(357_781)
            ->and($generation->estimated_max_micro_usd)->toBe(467_344);

        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
    });

    it('creates only one generation when the same form is submitted twice', function () {
        $user = User::factory()->create();
        $input = validInput();

        $this->actingAs($user)->post(route('generations.store'), $input)->assertRedirect(route('generations.index'));
        $this->actingAs($user)->post(route('generations.store'), $input)->assertRedirect(route('generations.index'));

        expect(Generation::count())->toBe(1);
        Queue::assertPushed(SubmitGeneration::class, 1);
    });

    it('scopes form ids per user', function () {
        $input = validInput();

        $this->actingAs(User::factory()->create())->post(route('generations.store'), $input);
        $this->actingAs(User::factory()->create())->post(route('generations.store'), $input);

        expect(Generation::count())->toBe(2);
    });

    it('gives every generation its own idempotency key', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('generations.store'), validInput());
        $this->actingAs($user)->post(route('generations.store'), validInput());

        expect(Generation::count())->toBe(2)
            ->and(Generation::pluck('idempotency_key')->unique())->toHaveCount(2);
    });

    it('estimates from Unicode characters, not bytes', function () {
        // 400 × "é" is 800 bytes but 400 characters: same estimate as 400 × "a".
        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), validInput(['prompt' => str_repeat('é', 400)]));

        expect(Generation::sole()->estimated_max_micro_usd)->toBe(467_344);
    });

    it('ignores empty reference image rows', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), validInput(['reference_images' => ['', 'https://example.com/1.png', null]]));

        expect(Generation::sole()->reference_images)->toBe(['https://example.com/1.png']);
    });

    it('validates input like Pixelforge does', function (array $input, string $errorKey) {
        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), validInput($input))
            ->assertSessionHasErrors($errorKey);

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'missing form id' => [['client_request_id' => ''], 'client_request_id'],
        'form id not a uuid' => [['client_request_id' => 'abc'], 'client_request_id'],
        'missing prompt' => [['prompt' => ''], 'prompt'],
        'prompt over 4000 characters' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
        'unknown size' => [['size' => '512x512'], 'size'],
        'unknown quality' => [['quality' => 'ultra'], 'quality'],
        'five reference images' => [['reference_images' => array_map(fn ($i) => "https://example.com/{$i}.png", range(1, 5))], 'reference_images'],
        'not http(s)' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
        'not a URL' => [['reference_images' => ['hello']], 'reference_images.0'],
        'duplicate image' => [['reference_images' => ['https://example.com/a.png', 'https://example.com/a.png']], 'reference_images.0'],
    ]);

    it('accepts a prompt of exactly 4000 Unicode characters', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), validInput(['prompt' => str_repeat('é', 4000)]))
            ->assertSessionHasNoErrors();
    });

    it('refuses to submit without a price when pricing is unavailable', function () {
        Http::fake([PRICING_URL => Http::failedConnection()]);

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), validInput())
            ->assertSessionHasErrors('pricing');

        expect(Generation::count())->toBe(0);
    });
});

describe('rate limits', function () {
    it('lets a user submit after typing a long prompt (live estimates have their own limit)', function () {
        // Reproduces a production bug: estimates and submits shared one throttle counter,
        // so ~10 live estimates while typing made the submit itself fail with 429.
        $user = User::factory()->create();

        foreach (range(1, 30) as $characters) {
            $this->actingAs($user)
                ->getJson(route('generations.estimate', ['characters' => $characters, 'reference_images' => 0, 'size' => '1024x1024', 'quality' => 'low']))
                ->assertOk();
        }

        $this->actingAs($user)
            ->post(route('generations.store'), validInput())
            ->assertRedirect(route('generations.index'));

        expect(Generation::count())->toBe(1);
    });

    it('still limits submits to 10 a minute, with a friendly message instead of an error page', function () {
        $user = User::factory()->create();

        foreach (range(1, 10) as $i) {
            $this->actingAs($user)->post(route('generations.store'), validInput())->assertRedirect(route('generations.index'));
        }

        $this->actingAs($user)
            ->from(route('generations.create'))
            ->post(route('generations.store'), validInput())
            ->assertRedirect(route('generations.create'))
            ->assertSessionHasErrors('submit');

        expect(Generation::count())->toBe(10);
    });

    it('limits live estimates separately, per user', function () {
        $user = User::factory()->create();
        $query = ['characters' => 1, 'reference_images' => 0, 'size' => '1024x1024', 'quality' => 'low'];

        foreach (range(1, 120) as $i) {
            $this->actingAs($user)->getJson(route('generations.estimate', $query))->assertOk();
        }

        $this->actingAs($user)->getJson(route('generations.estimate', $query))->assertStatus(429);
        // Another user is unaffected.
        $this->actingAs(User::factory()->create())->getJson(route('generations.estimate', $query))->assertOk();
    });
});

describe('live estimate', function () {
    it('returns the range for the chosen options', function () {
        $this->actingAs(User::factory()->create())
            ->getJson(route('generations.estimate', ['characters' => 400, 'reference_images' => 2, 'size' => '1536x1024', 'quality' => 'high']))
            ->assertOk()
            ->assertJson([
                'min_micro_usd' => 357_781,
                'max_micro_usd' => 467_344,
                'min' => '$0.357781',
                'max' => '$0.467344',
            ]);
    });

    it('validates its input', function () {
        $this->actingAs(User::factory()->create())
            ->getJson(route('generations.estimate', ['characters' => 5000, 'reference_images' => 9, 'size' => 'x', 'quality' => 'y']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['characters', 'reference_images', 'size', 'quality']);
    });

    it('answers 503 when pricing is unavailable', function () {
        Http::fake([PRICING_URL => Http::failedConnection()]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('generations.estimate', ['characters' => 1, 'reference_images' => 0, 'size' => '1024x1024', 'quality' => 'low']))
            ->assertStatus(503);
    });
});

describe('pricing cache', function () {
    it('fetches pricing once and caches it', function () {
        app(GetPricingSheet::class)->handle();
        app(GetPricingSheet::class)->handle();

        Http::assertSentCount(1);
    });

    it('falls back to the last good pricing when Pixelforge is down', function () {
        app(GetPricingSheet::class)->handle();
        Cache::forget(GetPricingSheet::CACHE_KEY);   // fresh copy expired
        Http::fake([PRICING_URL => Http::response('down', 500)]);

        expect(app(GetPricingSheet::class)->handle()->rates['output_tokens'])->toBe('40.00');
    });
});

describe('history page', function () {
    it('lists only my generations, newest first, with formatted money', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create(['prompt' => 'older', 'created_at' => now()->subMinute()]);
        Generation::factory()->for($user)->create(['prompt' => 'newer']);
        Generation::factory()->create(['prompt' => 'someone else']);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Index')
                ->has('generations.data', 2)
                ->where('generations.data.0.prompt', 'newer')
                ->where('generations.data.0.status', 'pending')
                ->where('generations.data.0.estimate.min', '$0.058125')
                ->where('generations.data.0.charged', null)
            );
    });
});
