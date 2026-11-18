<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const USAGE_URL = 'https://pixelforge.test/v1/usage*';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'));
});

function admin(): User
{
    $user = User::factory()->create();
    $user->forceFill(['is_admin' => true])->save();

    return $user;
}

/** A finished generation with real-looking money: cost in nano-$, charge in micro-$. */
function billed(User $user, string $completedAt, int $costNano, int $chargedMicro, GenerationStatus $status = GenerationStatus::Succeeded): Generation
{
    $generation = Generation::factory()->for($user)->withStatus($status)->create();
    $generation->forceFill([
        'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
        'provider_cost_nano_usd' => $costNano,
        'charged_micro_usd' => $chargedMicro,
    ])->save();

    return $generation;
}

/** Pixelforge's /v1/usage answer for the given days ([date => [generations, cost_usd]]). */
function pixelforgeUsage(array $days): mixed
{
    return Http::response([
        'object' => 'usage',
        'data' => collect($days)->map(fn (array $day, string $date) => [
            'date' => $date, 'generations' => $day[0], 'input_text_tokens' => 0, 'input_image_tokens' => 0,
            'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $day[1],
        ])->values()->all(),
    ]);
}

function reportFor(User $user, array $query = []): array
{
    return test()->actingAs($user)->get(route('admin.report', $query))->assertOk()->viewData('page')['props']['report'];
}

describe('access', function () {
    it('is only for admins', function () {
        Http::fake();

        $this->get(route('admin.report'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
        Http::assertNothingSent();
    });

    it('cannot be granted by registering with is_admin in the form', function () {
        $this->post(route('register.store'), [
            'name' => 'Mallory', 'email' => 'mallory@example.com',
            'password' => 'password', 'password_confirmation' => 'password', 'is_admin' => true,
        ]);

        expect(User::where('email', 'mallory@example.com')->sole()->is_admin)->toBeFalse();
    });

    it('is granted and revoked with the artisan command', function () {
        $user = User::factory()->create(['email' => 'ops@example.com']);

        $this->artisan('users:make-admin', ['email' => 'ops@example.com'])->assertSuccessful();
        expect($user->refresh()->is_admin)->toBeTrue();

        $this->artisan('users:make-admin', ['email' => 'ops@example.com', '--revoke' => true])->assertSuccessful();
        expect($user->refresh()->is_admin)->toBeFalse();

        $this->artisan('users:make-admin', ['email' => 'nobody@example.com'])->assertFailed();
    });
});

describe('money', function () {
    it('totals charges, provider costs and profit exactly, per day and per user', function () {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        billed($alice, '2026-11-17 09:00:00', 305_510_000, 381_888);   // the docs' example generation
        billed($alice, '2026-11-17 23:59:59', 12_590_000, 15_738);     // last second of the UTC day
        billed($bob, '2026-11-18 00:00:00', 11_522_500, 14_403);       // first second of the next day
        Http::fake([USAGE_URL => pixelforgeUsage([
            '2026-11-17' => [2, '0.318100000'],
            '2026-11-18' => [1, '0.011522500'],
        ])]);

        $report = reportFor(admin());

        expect($report['totals'])->toMatchArray([
            'generations' => 3,
            'charged' => '$0.412029000',         // 381,888 + 15,738 + 14,403 micro
            'provider_cost' => '$0.329622500',   // 305,510,000 + 12,590,000 + 11,522,500 nano
            'profit' => '$0.082406500',          // charged − cost, exact in nano-$
            'matches' => true,
        ])->and(collect($report['days'])->pluck('date')->all())->toBe(['2026-11-18', '2026-11-17']);

        $nov17 = $report['days'][1];
        expect($nov17)->toMatchArray(['generations' => 2, 'provider_cost' => '$0.318100000', 'matches' => true])
            ->and($nov17['users'])->toHaveCount(1)
            ->and($nov17['users'][0]['name'])->toBe('Alice');

        expect(collect($report['users'])->pluck('name')->all())->toBe(['Alice', 'Bob'])
            ->and($report['users'][0])->toMatchArray(['generations' => 2, 'charged' => '$0.397626000', 'profit' => '$0.079526000']);
    });

    it('counts only succeeded generations: failures and rejections are never billed', function () {
        $user = User::factory()->create();
        billed($user, '2026-11-17 10:00:00', 12_590_000, 15_738);
        billed($user, '2026-11-17 11:00:00', 0, 0, GenerationStatus::Failed);
        billed($user, '2026-11-17 12:00:00', 0, 0, GenerationStatus::Rejected);
        Generation::factory()->for($user)->withStatus(GenerationStatus::Processing)->create();
        Http::fake([USAGE_URL => pixelforgeUsage(['2026-11-17' => [1, '0.012590000']])]);

        $report = reportFor(admin());

        expect($report['totals']['generations'])->toBe(1)
            ->and($report['days'][0]['matches'])->toBeTrue();
    });

    it('keeps the totals of deleted users', function () {
        $gone = User::factory()->create();
        billed($gone, '2026-11-17 10:00:00', 12_590_000, 15_738);
        $gone->delete();
        Http::fake([USAGE_URL => pixelforgeUsage(['2026-11-17' => [1, '0.012590000']])]);

        $report = reportFor(admin());

        expect($report['users'][0])->toMatchArray(['name' => 'Deleted user', 'email' => null, 'charged' => '$0.015738000']);
    });
});

describe('reconciliation with GET /v1/usage', function () {
    it('asks Pixelforge for the same UTC days', function () {
        Http::fake([USAGE_URL => pixelforgeUsage([])]);

        reportFor(admin(), ['start_date' => '2026-11-01', 'end_date' => '2026-11-17']);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://pixelforge.test/v1/usage')
            && $r['start_date'] === '2026-11-01' && $r['end_date'] === '2026-11-17'
            && $r->hasHeader('Authorization', 'Bearer pf_test_key'));
    });

    it('flags a day where Pixelforge billed more than we recorded', function () {
        billed(User::factory()->create(), '2026-11-17 10:00:00', 12_590_000, 15_738);
        // e.g. a generation finished at Pixelforge but our webhook/poll hasn't applied it yet
        Http::fake([USAGE_URL => pixelforgeUsage(['2026-11-17' => [2, '0.024112500']])]);

        $report = reportFor(admin());

        expect($report['totals']['matches'])->toBeFalse()
            ->and($report['days'][0])->toMatchArray([
                'matches' => false,
                'difference' => '-$0.011522500',
                'pixelforge' => ['generations' => 2, 'cost' => '$0.024112500'],
            ]);
    });

    it('shows a day that only Pixelforge knows about', function () {
        Http::fake([USAGE_URL => pixelforgeUsage(['2026-11-16' => [1, '0.012590000'], '2026-11-17' => [0, '0.000000000']])]);

        $report = reportFor(admin());

        expect(collect($report['days'])->pluck('date')->all())->toBe(['2026-11-16'])
            ->and($report['days'][0]['matches'])->toBeFalse();
    });

    it('still shows our numbers when Pixelforge is unreachable', function () {
        billed(User::factory()->create(), '2026-11-17 10:00:00', 12_590_000, 15_738);
        Http::fake([USAGE_URL => Http::failedConnection()]);

        $report = reportFor(admin());

        expect($report['pixelforge_available'])->toBeFalse()
            ->and($report['totals']['provider_cost'])->toBe('$0.012590000')
            ->and($report['totals']['matches'])->toBeNull()
            ->and($report['days'][0]['matches'])->toBeNull();
    });
});

describe('date range', function () {
    it('defaults to the last 30 UTC days, like Pixelforge', function () {
        $user = User::factory()->create();
        billed($user, '2026-10-20 10:00:00', 1_000_000, 1_250);   // 30th day back: included
        billed($user, '2026-10-19 23:00:00', 9_000_000, 11_250);  // 31st day back: excluded
        Http::fake([USAGE_URL => pixelforgeUsage([])]);

        $this->actingAs(admin())->get(route('admin.report'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/SpendReport')
                ->where('report.range', ['start' => '2026-10-20', 'end' => '2026-11-18'])
                ->where('report.totals.generations', 1)
            );
    });

    it('rejects ranges Pixelforge would refuse', function (array $query, string $field) {
        Http::fake();

        $this->actingAs(admin())->get(route('admin.report', $query))->assertSessionHasErrors($field);
    })->with([
        'more than 92 days' => [['start_date' => '2026-01-01', 'end_date' => '2026-11-18'], 'end_date'],
        'end before start' => [['start_date' => '2026-11-10', 'end_date' => '2026-11-01'], 'end_date'],
        'not a date' => [['start_date' => 'yesterday'], 'start_date'],
    ]);
});
