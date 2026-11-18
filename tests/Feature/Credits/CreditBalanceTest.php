<?php

use App\Actions\Credits\RecordCreditTransaction;
use App\Actions\Generations\ApplyProviderUpdate;
use App\Actions\Generations\CreateGeneration;
use App\Actions\Generations\RejectGeneration;
use App\Enums\CreditTransactionType as T;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\ProviderGeneration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The docs' worked example: 400-character prompt, 2 reference images, high 1536x1024.
 * Estimate $0.357781 – $0.467344, so $0.467344 is held. The docs' succeeded generation
 * then charges $0.381888.
 */
const MAX_HOLD = 467_344;
const ACTUAL_CHARGE = 381_888;

beforeEach(function () {
    Queue::fake();
    Http::fake(['https://pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);
});

function submitFor(User $user, array $overrides = []): TestResponse
{
    return test()->actingAs($user)->post(route('generations.store'), [
        'client_request_id' => (string) Str::uuid(),
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/1.png', 'https://example.com/2.png'],
        ...$overrides,
    ]);
}

/** Pixelforge reports this generation (the docs example) in the given state. */
function provider(Generation $generation, string $state): void
{
    app(ApplyProviderUpdate::class)->handle(ProviderGeneration::fromApi(
        // Each local generation gets its own Pixelforge id, like in real life.
        pixelforgeGeneration($state, ['id' => 'gen_'.$generation->id, 'metadata' => ['generation_id' => $generation->id]])
    ));
}

/** @return list<array{0: string, 1: int}> [type, amount] in order */
function ledger(User $user): array
{
    return $user->creditTransactions()->orderBy('id')->get()
        ->map(fn (CreditTransaction $t) => [$t->type->value, $t->amount_micro_usd])->all();
}

/** The running balance must always equal the sum of the ledger. */
function expectLedgerConsistent(User $user): void
{
    $user->refresh();
    expect($user->balance_micro_usd)->toBe((int) $user->creditTransactions()->sum('amount_micro_usd'))
        ->and($user->creditTransactions()->latest('id')->value('balance_after_micro_usd') ?? 0)->toBe($user->balance_micro_usd);
}

function fundedUser(int $microUsd = 1_000_000): User
{
    $user = User::factory()->create();
    app(RecordCreditTransaction::class)->handle($user->id, T::Purchase, $microUsd);

    return $user->refresh();
}

describe('buying credits', function () {
    it('adds a pack to the balance and records it in the ledger', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('credits.store'), ['pack_micro_usd' => 5_000_000, 'client_request_id' => (string) Str::uuid()])
            ->assertRedirect(route('credits.index'));

        expect($user->refresh()->balance_micro_usd)->toBe(5_000_000)
            ->and(ledger($user))->toBe([['purchase', 5_000_000]]);
        expectLedgerConsistent($user);
    });

    it('buys only once when the same form is submitted twice (double-click)', function () {
        $user = User::factory()->create();
        $form = ['pack_micro_usd' => 1_000_000, 'client_request_id' => (string) Str::uuid()];

        $this->actingAs($user)->post(route('credits.store'), $form);
        $this->actingAs($user)->post(route('credits.store'), $form);

        expect($user->refresh()->balance_micro_usd)->toBe(1_000_000)
            ->and(CreditTransaction::count())->toBe(1);
    });

    it('buys again for a new form (a deliberate second purchase)', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('credits.store'), ['pack_micro_usd' => 1_000_000, 'client_request_id' => (string) Str::uuid()]);
        $this->actingAs($user)->post(route('credits.store'), ['pack_micro_usd' => 1_000_000, 'client_request_id' => (string) Str::uuid()]);

        expect($user->refresh()->balance_micro_usd)->toBe(2_000_000);
    });

    it('only allows the fixed packs', function (mixed $pack) {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('credits.store'), ['pack_micro_usd' => $pack, 'client_request_id' => (string) Str::uuid()])
            ->assertSessionHasErrors('pack_micro_usd');

        expect($user->refresh()->balance_micro_usd)->toBe(0);
    })->with([
        'arbitrary amount' => [999_999_999_999],
        'negative' => [-1_000_000],
        'zero' => [0],
        'decimal' => ['1.5'],
        'text' => ['lots'],
    ]);

    it('requires a valid form id', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('credits.store'), ['pack_micro_usd' => 1_000_000, 'client_request_id' => 'nope'])
            ->assertSessionHasErrors('client_request_id');
    });

    it('requires sign in', function () {
        $this->post(route('credits.store'), ['pack_micro_usd' => 1_000_000, 'client_request_id' => (string) Str::uuid()])
            ->assertRedirect(route('login'));
        $this->get(route('credits.index'))->assertRedirect(route('login'));
    });

    it('is rate limited with a friendly message', function () {
        $user = User::factory()->create();

        foreach (range(1, 10) as $i) {
            $this->actingAs($user)->post(route('credits.store'), ['pack_micro_usd' => 1_000_000, 'client_request_id' => (string) Str::uuid()]);
        }

        $this->actingAs($user)->from(route('credits.index'))
            ->post(route('credits.store'), ['pack_micro_usd' => 1_000_000, 'client_request_id' => (string) Str::uuid()])
            ->assertSessionHasErrors('pack_micro_usd');

        expect($user->refresh()->balance_micro_usd)->toBe(10_000_000);
    });

    it('cannot set the balance through the profile form (not mass assignable)', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Same', 'email' => $user->email, 'balance_micro_usd' => 999_000_000, 'is_admin' => true,
        ]);

        $user->refresh();
        expect($user->balance_micro_usd)->toBe(0)
            ->and($user->is_admin)->toBeFalse();
    });

    it("shows only the user's own balance and ledger", function () {
        $me = fundedUser(1_000_000);
        fundedUser(20_000_000);

        $this->actingAs($me)->get(route('credits.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('credits/Index')
                ->where('balance', '$1.00')
                ->has('transactions.data', 1)
                ->where('transactions.data.0.amount', '+$1.00')
                ->where('credits.balance', '$1.00')
            );
    });
});

describe('blocking generations the user cannot afford', function () {
    it('refuses when the balance is below the maximum estimate, creating nothing', function () {
        $user = fundedUser(MAX_HOLD - 1);

        submitFor($user)->assertSessionHasErrors('credits');

        expect(Generation::count())->toBe(0)
            ->and(ledger($user))->toBe([['purchase', MAX_HOLD - 1]]);
        Queue::assertNothingPushed();
    });

    it('explains how much is needed', function () {
        $user = fundedUser(100_000);

        submitFor($user)->assertSessionHasErrors([
            'credits' => 'This generation can cost up to $0.467344, but your balance is $0.10. Buy credits to continue.',
        ]);
    });

    it('allows a balance of exactly the maximum', function () {
        $user = fundedUser(MAX_HOLD);

        submitFor($user)->assertRedirect(route('generations.index'));

        expect($user->refresh()->balance_micro_usd)->toBe(0);
    });

    it('holds the maximum when submitting', function () {
        $user = fundedUser(1_000_000);

        submitFor($user);

        $generation = Generation::sole();
        expect($generation->reserved_micro_usd)->toBe(MAX_HOLD)
            ->and($user->refresh()->balance_micro_usd)->toBe(1_000_000 - MAX_HOLD)
            ->and(ledger($user))->toBe([['purchase', 1_000_000], ['hold', -MAX_HOLD]]);
        Queue::assertPushed(SubmitGeneration::class, 1);
        expectLedgerConsistent($user);
    });

    it('holds only once for a double-submitted form', function () {
        $user = fundedUser(1_000_000);
        $id = (string) Str::uuid();

        submitFor($user, ['client_request_id' => $id]);
        submitFor($user, ['client_request_id' => $id]);

        expect(Generation::count())->toBe(1)
            ->and($user->refresh()->balance_micro_usd)->toBe(1_000_000 - MAX_HOLD);
    });

    it('stops a second generation that the remaining balance cannot cover', function () {
        $user = fundedUser(MAX_HOLD + 100_000);

        submitFor($user)->assertRedirect(route('generations.index'));
        submitFor($user)->assertSessionHasErrors('credits');

        expect(Generation::count())->toBe(1);
    });
});

describe('settling when a generation ends', function () {
    it('succeeded: releases the hold and charges exactly the actual cost', function () {
        $user = fundedUser(1_000_000);
        submitFor($user);
        $generation = Generation::sole();

        provider($generation, 'succeeded');

        expect($generation->refresh()->reserved_micro_usd)->toBe(0)
            ->and($user->refresh()->balance_micro_usd)->toBe(1_000_000 - ACTUAL_CHARGE)
            ->and(ledger($user))->toBe([
                ['purchase', 1_000_000],
                ['hold', -MAX_HOLD],
                ['release', MAX_HOLD],
                ['charge', -ACTUAL_CHARGE],
            ]);
        expectLedgerConsistent($user);
    });

    it('failed: gives the whole hold back, charges nothing', function () {
        $user = fundedUser(1_000_000);
        submitFor($user);

        provider(Generation::sole(), 'failed');

        expect($user->refresh()->balance_micro_usd)->toBe(1_000_000)
            ->and(ledger($user))->toBe([['purchase', 1_000_000], ['hold', -MAX_HOLD], ['release', MAX_HOLD]]);
        expectLedgerConsistent($user);
    });

    it('rejected before creation: gives the whole hold back', function () {
        $user = fundedUser(1_000_000);
        submitFor($user);
        $generation = Generation::sole();
        Http::fake(['https://pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Blocked.']], 400,
        )]);

        $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Rejected)
            ->and($user->refresh()->balance_micro_usd)->toBe(1_000_000);
        expectLedgerConsistent($user);
    });

    it('charges once even when the success arrives twice (duplicate webhook + poll)', function () {
        $user = fundedUser(1_000_000);
        submitFor($user);
        $generation = Generation::sole();

        provider($generation, 'succeeded');
        provider($generation, 'succeeded');
        provider($generation, 'failed');        // late, contradictory event: ignored

        expect($user->refresh()->balance_micro_usd)->toBe(1_000_000 - ACTUAL_CHARGE)
            ->and(CreditTransaction::where('type', 'charge')->count())->toBe(1);
        expectLedgerConsistent($user);
    });

    it('moves no money for in-between updates (queued, processing)', function () {
        $user = fundedUser(1_000_000);
        submitFor($user);
        $generation = Generation::sole();

        provider($generation, 'queued');
        app(ApplyProviderUpdate::class)->handle(ProviderGeneration::fromApi(
            pixelforgeGeneration('queued', ['id' => 'gen_'.$generation->id, 'status' => 'processing', 'metadata' => ['generation_id' => $generation->id]])
        ));

        expect(ledger($user))->toBe([['purchase', 1_000_000], ['hold', -MAX_HOLD]]);
    });

    it('rejected, then Pixelforge says it succeeded after all: charged once, hold not released twice', function () {
        $user = fundedUser(1_000_000);
        submitFor($user);
        $generation = Generation::sole();
        app(RejectGeneration::class)->handle($generation, 'submission_timeout', 'Gave up.');

        provider($generation, 'succeeded');

        expect($user->refresh()->balance_micro_usd)->toBe(1_000_000 - ACTUAL_CHARGE)
            ->and(ledger($user))->toBe([
                ['purchase', 1_000_000],
                ['hold', -MAX_HOLD],
                ['release', MAX_HOLD],
                ['charge', -ACTUAL_CHARGE],
            ]);
        expectLedgerConsistent($user);
    });

    it('skips the ledger for a generation whose user deleted their account', function () {
        $user = fundedUser(1_000_000);
        submitFor($user);
        $generation = Generation::sole();
        $user->delete();

        provider($generation, 'succeeded');

        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and(CreditTransaction::count())->toBe(0);
    });
});

describe('ledger integrity', function () {
    it('cannot be edited or deleted (append-only)', function () {
        $entry = fundedUser()->creditTransactions()->sole();

        expect(fn () => $entry->forceFill(['amount_micro_usd' => 999])->save())->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class)
            ->and($entry->refresh()->amount_micro_usd)->toBe(1_000_000);
    });

    it('lets the database refuse a second charge for the same generation', function () {
        $user = fundedUser();
        $generation = Generation::factory()->for($user)->create();
        $record = app(RecordCreditTransaction::class);

        $record->handle($user->id, T::Charge, -100, $generation->id);
        $record->handle($user->id, T::Charge, -100, $generation->id);
    })->throws(UniqueConstraintViolationException::class);

    it('keeps the balance equal to the sum of the ledger across a whole day of activity', function () {
        $user = fundedUser(2_000_000);

        foreach (['succeeded', 'failed', 'succeeded'] as $outcome) {
            submitFor($user);
            provider(Generation::latest('created_at')->latest('id')->first(), $outcome);
        }
        submitFor($user);   // still in flight: its hold stays

        expect($user->refresh()->balance_micro_usd)->toBe(2_000_000 - 2 * ACTUAL_CHARGE - MAX_HOLD);
        expectLedgerConsistent($user);
    });
});

it('creates a generation through the action without leaking a hold on failure', function () {
    $user = fundedUser(100);   // far too little

    expect(fn () => app(CreateGeneration::class)->handle($user, (string) Str::uuid(), 'x', ImageSize::Square, ImageQuality::High))
        ->toThrow(InsufficientCreditsException::class);

    expect(Generation::count())->toBe(0)
        ->and($user->refresh()->balance_micro_usd)->toBe(100);
});
