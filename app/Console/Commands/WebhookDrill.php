<?php

namespace App\Console\Commands;

use App\Actions\Credits\RecordCreditTransaction;
use App\Actions\Generations\ApplyProviderUpdate;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\ProviderGeneration;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * LOCAL ONLY. An end-to-end drill of the webhook path, with nothing faked:
 *
 *   real signed HTTP requests → the RUNNING app (signature middleware, controller, event store)
 *   → real database queue → several real queue workers IN PARALLEL → ApplyProviderUpdate
 *   → then the final rows (generation, webhook events, credit ledger, balance) are checked.
 *
 * Pest tests can't do this: they run inside a rolled-back transaction that a separate web
 * server and separate worker processes can't see, and they never run two processes at once.
 *
 * Every scenario gets its own throwaway user ($5 bought, $0.50 held), so each balance can be
 * checked on its own. Everything is deleted afterwards unless --keep is given.
 *
 * Run the app with several PHP workers so the concurrent requests really overlap:
 *   PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8011 --no-reload
 *   php artisan pixelforge:webhook-drill --url=http://127.0.0.1:8011
 */
#[Signature('pixelforge:webhook-drill
    {--url=http://127.0.0.1:8010 : Base URL of the running app}
    {--workers=4 : Queue workers to run in parallel}
    {--keep : Keep the drill rows for inspection instead of deleting them}')]
#[Description('Local only: send real signed webhooks to the running app and check the final money and state')]
class WebhookDrill extends Command
{
    private const int PURCHASED = 5_000_000;   // $5.00 in micro-USD

    private const int HELD = 500_000;          // $0.50 hold (the max estimate)

    /**
     * Expected charge for the usage below, worked out by hand (rates are $ per 1M tokens):
     *   12×5 + 765×10 + 6450×40 + 3184×12.5 = 305,510 → $0.30551 cost
     *   × 1.25 = $0.3818875 → half-up to micro-dollars = $0.381888
     */
    private const int EXPECTED_CHARGE = 381_888;

    private const array USAGE = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411];

    private const array RATES = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];

    private string $run;

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: bool}> scenario, check, expected, actual, passed */
    private array $results = [];

    public function handle(RecordCreditTransaction $credits, ApplyProviderUpdate $applyUpdate): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production: this drill creates fake users, generations and charges.');

            return self::FAILURE;
        }

        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '' || DB::table('jobs')->count() > 0) {
            $this->error($secret === ''
                ? 'PIXELFORGE_WEBHOOK_SECRET is not set.'
                : 'The queue is not empty. Let a worker finish it first, so the drill only processes its own jobs.');

            return self::FAILURE;
        }

        $this->run = Str::lower(Str::random(6));
        $url = rtrim((string) $this->option('url'), '/').'/webhooks/pixelforge';
        $lastFailedJobId = (int) DB::table('failed_jobs')->max('id');

        $this->info("Drill {$this->run} → {$url}");

        // ---- 1. Set up one user + generation per scenario ----------------------------------
        $gen = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $key) {
            $gen[$key] = $this->makeGeneration($credits, $key, linked: true);
        }
        $gen['G'] = $this->makeGeneration($credits, 'G', linked: false);   // create call "still in flight"

        // ---- 2. Send real HTTP requests -------------------------------------------------------
        // A: the same succeeded event 5 times at once.
        $eventA = $this->event($gen['A'], 'succeeded');
        $started = microtime(true);
        $responsesA = Http::pool(fn (Pool $pool) => array_map(
            fn () => $this->signedRequest($pool->withOptions([]), $secret, $eventA),
            range(1, 5),
        ));
        $wallMs = (microtime(true) - $started) * 1000;
        // Pool responses come back in completion order (keys kept), so compare values only.
        $codesA = array_values(array_map(fn ($r) => $r instanceof Response ? $r->status() : 0, $responsesA));
        $sumMs = array_sum(array_map(fn ($r) => $r instanceof Response ? (float) ($r->handlerStats()['total_time'] ?? 0) * 1000 : 0, $responsesA));

        // B: succeeded, then a late processing.
        $codesB = [$this->send($url, $secret, $this->event($gen['B'], 'succeeded')), $this->send($url, $secret, $this->event($gen['B'], 'processing'))];
        // C: succeeded, then failed.
        $codesC = [$this->send($url, $secret, $this->event($gen['C'], 'succeeded')), $this->send($url, $secret, $this->event($gen['C'], 'failed'))];
        // D: correctly signed, but 301 seconds ago (window is 300).
        $codeD = $this->send($url, $secret, $this->event($gen['D'], 'succeeded'), timestamp: time() - 301);
        // E: signed, then the body is changed in transit (output tokens ×10).
        $codeE = $this->send($url, $secret, $this->event($gen['E'], 'succeeded'), tamper: true);
        // F: an event type we don't know, carrying a succeeded object that must NOT be applied.
        $codeF = $this->send($url, $secret, $this->event($gen['F'], 'succeeded', type: 'account.updated'));
        // G: the webhook arrives before our create call returned (row has no provider id yet).
        $codeG = $this->send($url, $secret, $this->event($gen['G'], 'succeeded', withMetadata: true));

        // ---- 3. Process the queue with several workers at once --------------------------------
        $queued = Queue::size();
        $workers = max(1, (int) $this->option('workers'));
        $this->line("Queued jobs: {$queued}. Starting {$workers} queue workers in parallel…");

        Process::pool(function ($pool) use ($workers) {
            foreach (range(1, $workers) as $i) {
                $pool->path(base_path())->timeout(120)->command([PHP_BINARY, 'artisan', 'queue:work', '--stop-when-empty']);
            }
        })->start()->wait();

        // G: now the create call "returns" its 202 (status queued), late. Same path SubmitGeneration uses.
        $applyUpdate->handle(ProviderGeneration::fromApi($this->generationObject($gen['G'], 'queued', withMetadata: true)));

        // ---- 4. Check the final state ----------------------------------------------------------
        $this->check('A 5× same event', 'HTTP codes', '204 ×5', implode(',', $codesA), count(array_filter($codesA, fn (int $c) => $c === 204)) === 5);
        $this->check('A 5× same event', 'requests overlapped', 'wall < sum', sprintf('%.0fms < %.0fms', $wallMs, $sumMs), $wallMs < $sumMs);
        $this->check('A 5× same event', 'events stored', '1', (string) $this->eventsFor($gen['A']), $this->eventsFor($gen['A']) === 1);
        $this->checkCharged('A 5× same event', $gen['A']);

        $this->check('B succeeded, then processing', 'HTTP codes', '204,204', implode(',', $codesB), $codesB === [204, 204]);
        $this->check('B succeeded, then processing', 'events stored + processed', '2', (string) $this->eventsFor($gen['B'], processed: true), $this->eventsFor($gen['B'], processed: true) === 2);
        $this->checkCharged('B succeeded, then processing', $gen['B']);

        $this->check('C succeeded, then failed', 'HTTP codes', '204,204', implode(',', $codesC), $codesC === [204, 204]);
        $this->check('C succeeded, then failed', 'error_code', 'null', (string) ($gen['C']->refresh()->error_code ?? 'null'), $gen['C']->error_code === null);
        $this->checkCharged('C succeeded, then failed', $gen['C']);

        $this->checkUntouched('D stale timestamp', $gen['D'], $codeD, 400, eventsStored: 0);
        $this->checkUntouched('E tampered body', $gen['E'], $codeE, 400, eventsStored: 0);
        $this->checkUntouched('F unknown type', $gen['F'], $codeF, 204, eventsStored: 1);

        $this->check('G before create returns', 'HTTP code', '204', (string) $codeG, $codeG === 204);
        $this->check('G before create returns', 'linked to provider id', "gen_drill_{$this->run}_g", (string) $gen['G']->refresh()->provider_generation_id, $gen['G']->provider_generation_id === "gen_drill_{$this->run}_g");
        $this->checkCharged('G before create returns', $gen['G']);

        $failedJobs = DB::table('failed_jobs')->where('id', '>', $lastFailedJobId)->count();
        $left = Queue::size();
        $this->check('All', 'queue drained / failed jobs', '0 / 0', "{$left} / {$failedJobs}", $left === 0 && $failedJobs === 0);

        $this->table(['Scenario', 'Check', 'Expected', 'Actual', ''], array_map(
            fn (array $r) => [$r[0], $r[1], $r[2], $r[3], $r[4] ? '<info>PASS</info>' : '<error>FAIL</error>'],
            $this->results,
        ));

        $failed = count(array_filter($this->results, fn (array $r) => ! $r[4]));

        if (! $this->option('keep')) {
            $this->cleanUp();
        }

        $failed === 0
            ? $this->info(count($this->results).' checks passed.')
            : $this->error("{$failed} of ".count($this->results).' checks failed.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** A user with $5 bought and $0.50 held for one queued (or still pending) generation. */
    private function makeGeneration(RecordCreditTransaction $credits, string $key, bool $linked): Generation
    {
        $user = new User;
        $user->forceFill([
            'name' => "Webhook drill {$key}",
            'email' => "webhook-drill-{$this->run}-".strtolower($key).'@pixelforge.test',
            'password' => Str::random(32),
            'email_verified_at' => now(),
        ])->save();

        $credits->handle($user->id, CreditTransactionType::Purchase, self::PURCHASED);

        $generation = Generation::create([
            'user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => "Webhook drill {$this->run} {$key}",
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_min_micro_usd' => 100_000,
            'estimated_max_micro_usd' => self::HELD,
        ]);

        $generation->forceFill([
            'status' => $linked ? GenerationStatus::Queued : GenerationStatus::Pending,
            'provider_generation_id' => $linked ? $this->providerId($generation) : null,
            'reserved_micro_usd' => self::HELD,
            'submitted_at' => now(),
        ])->save();

        $credits->handle($user->id, CreditTransactionType::Hold, -self::HELD, $generation->id);

        return $generation;
    }

    /** gen_drill_<run>_<scenario letter>, derived from the prompt so it's stable per scenario. */
    private function providerId(Generation $generation): string
    {
        return "gen_drill_{$this->run}_".strtolower(Str::afterLast($generation->prompt, ' '));
    }

    /**
     * A Pixelforge generation object, shaped like the docs example.
     *
     * @return array<string, mixed>
     */
    private function generationObject(Generation $generation, string $status, bool $withMetadata = false): array
    {
        $done = in_array($status, ['succeeded', 'failed'], true);
        $id = $this->providerId($generation);

        return [
            'id' => $id,
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => $withMetadata ? [ProviderGeneration::METADATA_KEY => $generation->id] : new \stdClass,
            'image_url' => $status === 'succeeded' ? "https://pixelforge.promptedit.dev/images/{$id}.png" : null,
            'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601ZuluString() : null,
            'usage' => $status === 'succeeded' ? self::USAGE : null,
            'rates' => self::RATES,
            'error' => $status === 'failed' ? ['code' => 'internal_error', 'message' => 'Drill failure.'] : null,
            'created_at' => now()->subMinute()->toIso8601ZuluString(),
            'started_at' => $status === 'queued' ? null : now()->subSeconds(30)->toIso8601ZuluString(),
            'completed_at' => $done ? now()->toIso8601ZuluString() : null,
        ];
    }

    /**
     * A webhook event envelope with a fresh event id.
     *
     * @return array<string, mixed>
     */
    private function event(Generation $generation, string $status, ?string $type = null, bool $withMetadata = false): array
    {
        return [
            'id' => 'evt_drill_'.$this->run.'_'.Str::lower(Str::random(10)),
            'object' => 'event',
            'type' => $type ?? "generation.{$status}",
            'created_at' => now()->toIso8601ZuluString(),
            'data' => ['object' => $this->generationObject($generation, $status, $withMetadata)],
        ];
    }

    /**
     * Signs exactly the bytes that will be sent, like Pixelforge: v1 = HMAC-SHA256("{t}.{raw body}").
     *
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: string} [raw body, signature header]
     */
    private function sign(string $secret, array $event, ?int $timestamp = null): array
    {
        $body = (string) json_encode($event, JSON_UNESCAPED_SLASHES);
        $t = $timestamp ?? time();

        return [$body, "t={$t},v1=".WebhookSignature::sign($secret, $t, $body)];
    }

    /** @param  array<string, mixed>  $event */
    private function signedRequest(PendingRequest $request, string $secret, array $event): mixed
    {
        [$body, $signature] = $this->sign($secret, $event);

        return $request->withHeaders(['Pixelforge-Signature' => $signature, 'Pixelforge-Event-Id' => (string) $event['id']])
            ->withBody($body, 'application/json')
            ->post(rtrim((string) $this->option('url'), '/').'/webhooks/pixelforge');
    }

    /** @param  array<string, mixed>  $event */
    private function send(string $url, string $secret, array $event, ?int $timestamp = null, bool $tamper = false): int
    {
        [$body, $signature] = $this->sign($secret, $event, $timestamp);

        if ($tamper) {
            $body = str_replace('"output_tokens":6450', '"output_tokens":64500', $body);
        }

        return Http::withHeaders(['Pixelforge-Signature' => $signature, 'Pixelforge-Event-Id' => (string) $event['id']])
            ->withBody($body, 'application/json')
            ->post($url)
            ->status();
    }

    private function eventsFor(Generation $generation, bool $processed = false): int
    {
        return WebhookEvent::query()
            ->where('provider_generation_id', $this->providerId($generation))
            ->when($processed, fn ($q) => $q->whereNotNull('processed_at'))
            ->count();
    }

    /** Succeeded, charged the exact amount once, hold released once, balance = $5 − charge = ledger sum. */
    private function checkCharged(string $scenario, Generation $generation): void
    {
        $generation->refresh();
        $ledger = CreditTransaction::query()->where('generation_id', $generation->id);
        $charges = (clone $ledger)->where('type', CreditTransactionType::Charge)->count();
        $releases = (clone $ledger)->where('type', CreditTransactionType::Release)->count();

        $this->check($scenario, 'status', 'succeeded', $generation->status->value, $generation->status === GenerationStatus::Succeeded);
        $this->check($scenario, 'charged_micro_usd', (string) self::EXPECTED_CHARGE, (string) ($generation->charged_micro_usd ?? 'null'), $generation->charged_micro_usd === self::EXPECTED_CHARGE);
        $this->check($scenario, 'ledger charge / release rows', '1 / 1', "{$charges} / {$releases}", $charges === 1 && $releases === 1);
        $this->checkBalance($scenario, $generation, self::PURCHASED - self::EXPECTED_CHARGE);
    }

    /** Nothing applied: still queued, never charged, the hold still in place. */
    private function checkUntouched(string $scenario, Generation $generation, int $code, int $expectedCode, int $eventsStored): void
    {
        $generation->refresh();
        $stored = $this->eventsFor($generation);

        $this->check($scenario, 'HTTP code', (string) $expectedCode, (string) $code, $code === $expectedCode);
        $this->check($scenario, 'events stored', (string) $eventsStored, (string) $stored, $stored === $eventsStored);

        if ($eventsStored > 0) {
            $processed = $this->eventsFor($generation, processed: true);
            $this->check($scenario, 'event marked processed', (string) $eventsStored, (string) $processed, $processed === $eventsStored);
        }

        $this->check($scenario, 'status / charged', 'queued / null', $generation->status->value.' / '.($generation->charged_micro_usd ?? 'null'), $generation->status === GenerationStatus::Queued && $generation->charged_micro_usd === null);
        $this->checkBalance($scenario, $generation, self::PURCHASED - self::HELD);
    }

    private function checkBalance(string $scenario, Generation $generation, int $expected): void
    {
        $balance = (int) User::query()->whereKey($generation->user_id)->value('balance_micro_usd');
        $ledgerSum = (int) CreditTransaction::query()->where('user_id', $generation->user_id)->sum('amount_micro_usd');

        $this->check($scenario, 'balance = ledger sum', "{$expected} = {$expected}", "{$balance} = {$ledgerSum}", $balance === $expected && $ledgerSum === $expected);
    }

    private function check(string $scenario, string $check, string $expected, string $actual, bool $passed): void
    {
        $this->results[] = [$scenario, $check, $expected, $actual, $passed];
    }

    /**
     * Removes this run's rows (generations first: they outlive users on purpose, see the migration).
     * The ledger is append-only and its model refuses deletes; this mass delete deliberately skips
     * model events because these rows are throwaway drill data on a local database, never real money.
     */
    private function cleanUp(): void
    {
        $userIds = User::query()->where('email', 'like', "webhook-drill-{$this->run}-%")->pluck('id');

        DB::transaction(function () use ($userIds) {
            CreditTransaction::query()->whereIn('user_id', $userIds)->delete();
            Generation::query()->whereIn('user_id', $userIds)->delete();
            WebhookEvent::query()->where('provider_event_id', 'like', "evt_drill_{$this->run}_%")->delete();
            User::query()->whereIn('id', $userIds)->delete();
        });

        $this->line('Drill rows deleted (use --keep to inspect them).');
    }
}
