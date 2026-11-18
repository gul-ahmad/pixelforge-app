# Pixelforge image generation

A Laravel 13 + Vue 3 + Inertia app where signed-in users generate images through
**Pixelforge**, an asynchronous, per-token-priced image API, and are charged
**exactly** what each image cost plus a 25% markup. Failed generations are free.

- **Live app:** the URL, the server's basic-auth login and a demo account are in the submission form
- **Stack:** Laravel 13, PHP 8.4, Vue 3 `<script setup>` + TypeScript, Inertia 3, Tailwind 4, Pest, `brick/math`
- **Quality gates (CI on every push):** 327 Pest tests on **MySQL and SQLite**, PHPStan level 7, Pint, vue-tsc, Vite+ lint/format

---

## Contents

1. [What it does](#what-it-does)
2. [Money: how a charge is calculated](#money-how-a-charge-is-calculated)
3. [Architecture](#architecture)
4. [Generation lifecycle (state machine)](#generation-lifecycle-state-machine)
5. [Never charging twice](#never-charging-twice)
6. [Talking to Pixelforge: retries and limits](#talking-to-pixelforge-retries-and-limits)
7. [Webhooks](#webhooks)
8. [Polling fallback](#polling-fallback)
9. [Admin report (stretch goal)](#admin-report-stretch-goal)
10. [Credit balances (stretch goal)](#credit-balances-stretch-goal)
11. [Security](#security)
12. [Running it locally](#running-it-locally)
13. [Tests](#tests)
14. [Deployment](#deployment)
15. [Trade-offs and decisions](#trade-offs-and-decisions)
16. [What I'd do next](#what-id-do-next)

---

## What it does

- **Generate:** prompt (≤ 4,000 characters), size, quality, up to 4 reference image URLs.
  A **live price range** is shown before submitting: "You'll be charged the actual
  cost after completion, which falls within this range. Failed generations are free."
- **History:** newest first, live status updates (queued → generating → succeeded/failed),
  the estimate next to the **actual charge**, the image (with an "image link expired"
  state after Pixelforge's 24-hour URL expiry), plain-English errors
  ("Pixelforge is busy, retrying…", "Failed during generation. You were not charged."),
  a running total, and **Try again** for failed or rejected generations.
- **Behind the scenes:** submissions are queued and retried safely, webhooks are verified
  and deduplicated, and a scheduled job repairs anything a missed webhook left behind.

## Money: how a charge is calculated

Pixelforge never returns a price; it returns token `usage` and the `rates` fixed when the
generation was created. All money maths lives in one class, [`App\Support\Pricing`](app/Support/Pricing.php).

```
provider cost = Σ tokens × rate per 1M tokens                     (exact, never rounded)
charge        = provider cost × 1.25, rounded HALF-UP to $0.000001
```

**No floats anywhere:** amounts are integers in fixed units and the maths uses `BigDecimal`.

| Column                        | Unit   | Why                                                                                                                                                                                                       |
| ----------------------------- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `provider_cost_nano_usd`      | 1e-9 $ | Pixelforge's exact cost. `GET /v1/usage` reports 9 decimals, so our records reconcile with integer equality. If a rate were ever finer than a nano-dollar per token, the code throws instead of rounding. |
| `charged_micro_usd`           | 1e-6 $ | What the user pays, rounded once, at one documented point.                                                                                                                                                |
| `estimated_min/max_micro_usd` | 1e-6 $ | The range shown before submitting.                                                                                                                                                                        |
| `usage`, `rates` (JSON)       |        | Audit trail: every charge can be recomputed from its row.                                                                                                                                                 |

**Rounding rule:** half-up to the micro-dollar (`$0.000001`), applied once per generation.
For example, the documentation's worked example costs **$0.373875000**; × 1.25 = 467,343.75
micro-dollars, which rounds to **$0.467344**. The browser receives only server-formatted
strings, so there's no money maths in JavaScript.

**Estimate:** image tokens (765 each) and output tokens (by size × quality) are exact. Text tokens are
`ceil(characters / 4)` ±15% (Unicode characters, not bytes) and reasoning tokens use the quality's
min…max range. The range uses the extremes of both, and half-up rounding never decreases a
value, so the real charge always lands inside it (tested for every size, quality and image
count). Pricing is read from `GET /v1/pricing`, cached for an hour, with the last good copy
kept as a fallback.

## Architecture

```
app/
├── Support/Pricing.php                  all money maths (pure, no I/O)
├── Enums/GenerationStatus.php           statuses + the forward-only transition rule
├── Models/Generation, WebhookEvent      rows: casts, relations, small read-only helpers
├── Actions/                             one business operation per class, single handle()
│   ├── Generations/CreateGeneration     estimate → save pending → queue submit
│   ├── Generations/ApplyProviderUpdate  THE only place provider state is applied
│   ├── Generations/RejectGeneration     refused before creation → rejected, cost 0
│   ├── Generations/EstimateGenerationCost
│   ├── Pricing/GetPricingSheet          cached /v1/pricing with fallback
│   ├── Webhooks/RecordWebhookEvent      store each event once, queue processing
│   └── Polling/…                        sync stale, recover pending, retry lost events
├── Jobs/SubmitGeneration                timing and retry policy for POST /v1/generations
├── Jobs/ProcessWebhookEvent             applies a stored webhook event
├── Services/Pixelforge/                 HTTP client, typed snapshot, API exception, signature
├── Http/                                thin controllers, Form Requests, API Resource, signature middleware
└── Console/Commands/ReconcileWithPixelforge   scheduled polling fallback
```

- **Actions** hold business logic, and are reused by controllers, jobs, webhooks and the poller.
- **Models** are data only.
- **Services** wrap the external API and contain no business rules.
- **Support** is pure maths.
- **Jobs** decide _when_ to retry and delegate every state change to an Action.

**One place changes state.** The 202 create response, every webhook and every poll are parsed
into the same [`ProviderGeneration`](app/Services/Pixelforge/ProviderGeneration.php) snapshot
and go through [`ApplyProviderUpdate`](app/Actions/Generations/ApplyProviderUpdate.php). It runs
in a transaction, locks the row, only moves the status forward, and writes the charge exactly
once: on the first move to `succeeded`. Status and money columns aren't mass-assignable.

## Generation lifecycle (state machine)

```
                 ┌──────── refused before creation / retries exhausted ──────┐
                 │  (400 policy, 422, 401, 409, daily limit, capped 429/503)  ▼
 [pending] ──202 / webhook / poll──▶ [queued] ──▶ [processing] ──▶ [succeeded]   charged once
    ▲  │                                │               └────────▶ [failed]      $0
    └──┘ unknown outcome: retry         └──────────────────────────▶            [rejected]  $0, never billed
         with the same Idempotency-Key
```

- `pending` and `rejected` are local-only; the rest mirror Pixelforge.
- `succeeded` and `failed` are final. Late or out-of-order events are stored but ignored for state.
- `rejected → queued/processing/succeeded/failed` is allowed: if Pixelforge reports a generation we had given up on, it exists and may be billed, so the provider's state wins.

## Never charging twice

| Risk                                          | Protection                                                                                                                                 |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Double-click or a resent form POST            | A server-issued `client_request_id` per rendered form, unique per user; `createOrFirst` returns the existing generation                    |
| Retrying the Pixelforge call                  | A UUID `Idempotency-Key` per generation, sent on every retry                                                                               |
| Same webhook delivered twice                  | Unique `provider_event_id`; a duplicate answers 2xx and does nothing                                                                       |
| Webhook and poll applying "succeeded" at once | Transaction + row lock + forward-only rule. On SQLite, `IMMEDIATE` transactions serialize writers (verified with two concurrent processes) |

## Talking to Pixelforge: retries and limits

[`SubmitGeneration`](app/Jobs/SubmitGeneration.php) runs on the queue, so the user is redirected immediately.

| Pixelforge answer                                      | Meaning                                            | What we do                                                                                                                                                                                                |
| ------------------------------------------------------ | -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `202`                                                  | Accepted, or a replay that may already be finished | `ApplyProviderUpdate`                                                                                                                                                                                     |
| No free slot (3 per account)                           | "Not yet", not a failure                           | Check **our own** in-flight count first (free); Pixelforge's `429 concurrency_limit_exceeded` is the backstop. Wait up to 15 minutes ("Waiting for a free generation slot…"); doesn't count as an attempt |
| `429` rate limit / `503 overloaded`                    | Nothing was created                                | Back off `max(Retry-After, 2^attempt)` seconds; after 5 attempts → rejected, $0                                                                                                                           |
| Network error / other 5xx                              | **Unknown** whether it was created                 | Retry with the same key; never mark rejected (it may be billed); after the cap it stays pending for the poller                                                                                            |
| `400` content policy, `422`, `401`, `409`, daily limit | Refused                                            | Rejected now with a friendly reason; our own errors (`401`, `409`, `422`) are logged as critical                                                                                                          |

Pixelforge's 60 requests/minute limit is shared by the whole account. Waiting for a slot costs
no requests, polling uses at most 10 per minute, and pricing is cached.

## Webhooks

`POST /webhooks/pixelforge` is loaded **outside** the `web` middleware group, so there's no session, cookies or CSRF.

1. [`VerifyPixelforgeSignature`](app/Http/Middleware/VerifyPixelforgeSignature.php): HMAC-SHA256 of `"{t}.{raw body}"` with the key's `whsec_` secret, compared with `hash_equals`, timestamps outside ±300 s rejected. The signature is checked against the **raw** body, because re-encoded JSON would break it. Bad signature → `400`; missing secret → `500` so Pixelforge keeps retrying.
2. [`RecordWebhookEvent`](app/Actions/Webhooks/RecordWebhookEvent.php): stores the event once (ids are taken from the **signed body**, never the unsigned headers) and queues processing. A redelivery of an event that was never processed is re-queued.
3. Answers `204` immediately; Pixelforge counts any 2xx within 10 seconds as delivered.
4. [`ProcessWebhookEvent`](app/Jobs/ProcessWebhookEvent.php): applies `data.object` via `ApplyProviderUpdate` and marks the event processed **in the same transaction**. Matching is by provider id, or by our id in `metadata` when the webhook beats the create response.

## Polling fallback

Webhook delivery isn't guaranteed, so `pixelforge:reconcile` runs every minute
(`withoutOverlapping`, `onOneServer`):

- generations queued or processing with no update for 2 minutes → `GET /v1/generations/{id}` → `ApplyProviderUpdate` (at most 10 per run, oldest first; stops on 429, 5xx or a network error);
- stuck `pending` submissions → re-queued with the same key; after an hour → rejected (never billed),
  **unless** an earlier attempt had an unknown outcome (timeout or unexpected 5xx). Pixelforge may have
  created that one, so it is never rejected: we keep replaying the key (keys never expire) until it answers;
- webhook events stored but never processed → re-queued; an event that fails 3 full rounds is parked
  (`failed_at`, critical log) so it can't block retries of other events.

The History page's live updates are separate. The **browser** polls **our** server every
3 seconds (Inertia partial reload) only while something on the page is unfinished, and never
contacts Pixelforge.

## Admin report (stretch goal)

`/admin/report` is for operators only. It shows **per UTC day and per user**: what users were
charged, what Pixelforge charged us, and the profit, and checks every day against
**`GET /v1/usage`**.

- **Same days as Pixelforge.** A generation is counted on the UTC day it **completed**, which is the day Pixelforge bills it. Only succeeded generations count; failed and rejected ones are never billed.
- **Exact.** Sums are integers; profit = charge − cost in nano-dollars. Pixelforge's `cost_usd` is parsed to nano-dollars, so a day either matches exactly or shows the exact difference. If Pixelforge's report is unreachable, our own numbers are still shown.
- **Access.** An `is_admin` flag that isn't mass-assignable, granted only with `php artisan users:make-admin you@example.com`. A Gate (`view-admin-report`) is checked by both the route middleware and the Form Request. Code asks for the _ability_, not the role, so switching to a roles package later is a one-line change.
- **Verified live:** for the local key, 18 generations and **$0.247690000** matched Pixelforge to the nano-dollar, including every token count.

## Credit balances (stretch goal)

Users pay for generations from a credit balance. **Buy credits** adds a fixed pack
($1 / $5 / $10 / $20) as a demo purchase: no payment is taken.

| Moment            | Ledger entries                   | Effect                                                                  |
| ----------------- | -------------------------------- | ----------------------------------------------------------------------- |
| Buy credits       | `purchase +pack`                 | Balance goes up                                                         |
| Submit            | `hold −max estimate`             | Refused with a clear message if the balance can't cover the **maximum** |
| Succeeded         | `release +max`, `charge −actual` | The user pays exactly the actual charge                                 |
| Failed / rejected | `release +max`                   | Free; the balance is back where it was                                  |

- **Append-only ledger** (`credit_transactions`): every change is a new row, and rows can't be updated or deleted (refused in code). `users.balance_micro_usd` is the running total, written in the **same transaction** as the ledger row with the user's row locked, so the balance always equals the sum of the ledger (tested).
- **Never twice:** a unique index on `(generation_id, type)` allows one hold, one release and one charge per generation. Settlement runs inside the same locked, forward-only transitions that write the charge. Double-submitting a form holds once; double-clicking "Buy credits" buys once (a per-form id).
- **Concurrency:** submitting locks the user row while checking the balance and placing the hold, so two tabs can't spend the same credit.
- **Edge cases:** if a generation we had given up on turns out to have succeeded at Pixelforge, it is charged (its hold was already released). If Pixelforge raised its rates within the 1-hour pricing cache, the actual charge can exceed the hold. Both are correct but can take a balance slightly below what was expected, or below zero.
- **Verified live:** buy $1, then a `[succeed]` and a `[fail]` generation → balance $1.00 − $0.017319 = **$0.982681**, matching the sum of the ledger.

## Security

- **Authentication and authorization:** every page except the landing page and auth screens requires sign-in. Users only ever see their own generations and ledger (queries go through `$request->user()`). The admin report needs the `view-admin-report` ability.
- **Mass assignment:** `is_admin`, `balance_micro_usd`, status, provider ids and all money columns are not fillable (tested through the signup and profile forms).
- **Webhooks:** HMAC signature over the raw body, constant-time comparison, a 300-second replay window, event-id deduplication, and ids taken only from the signed body.
- **XSS:** Vue escapes all output (no `v-html`). Provider image URLs must be `https://`, so a tampered response can't inject a `javascript:` link.
- **Headers:** `X-Frame-Options: DENY` (stops clickjacking on Buy credits / Generate), `nosniff`, strict `Referrer-Policy`, minimal `Permissions-Policy`.
- **Rate limits:** named per-purpose limiters for submits (10/min), estimates (120/min) and purchases (10/min); Fortify limits logins.
- **Secrets:** only in `.env` (`chmod 600` on the server); none in git history (scanned).
- **Dependencies:** `composer audit` is clean. `npm audit` reports a ReDoS in `picomatch`, which is only used by build tools (Vite, vue-tsc) on our own file paths and never shipped to browsers; there's no patched version yet.
- **Known limitation:** because credits are free (demo purchase), they don't stop someone with many accounts from using our Pixelforge daily quota. On the live server the site sits behind basic auth; with real payments the balance itself would prevent it.

## Running it locally

Requirements: PHP 8.4, Composer, Node 22, MySQL 8 (or SQLite).

```bash
git clone https://github.com/gul-ahmad/pixelforge-app.git && cd pixelforge-app
composer setup                      # install, .env, key, migrate, npm install + build
# .env: DB_* and your Pixelforge key:
#   PIXELFORGE_API_KEY=pf_…   PIXELFORGE_WEBHOOK_SECRET=whsec_…
php artisan migrate
composer run dev                    # app + queue worker + Vite
php artisan schedule:work           # the polling fallback (cron in production)
```

For webhooks during development, expose the app with a tunnel (`ngrok http 8000`) and set
`https://<tunnel>/webhooks/pixelforge` as the webhook URL of your **local** API key. Use one
key per environment, so each one gets only its own events and its own `/v1/usage`.

**Try the failure paths** with Pixelforge's prompt keywords: `[blocked]`, `[overloaded]`,
`[fail]`, `[succeed]`, `[slow]`, `[duplicate]`.

## Tests

```bash
php artisan test                                       # MySQL (pixelforge_testing)
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
vendor/bin/phpstan analyse && vendor/bin/pint --test
npm run types:check && npm run check
```

Tests never reach the real API (`Http::preventStrayRequests()`), and fixtures are the real
`/v1/pricing` response and the docs' generation objects. Highlights:

- pricing maths against every worked example in the docs, including the `/v1/usage` example;
- signatures: valid, wrong secret, tampered body, re-encoded body, stale or future timestamp;
- duplicate and out-of-order webhooks, and a webhook that arrives before the create response;
- submit job: 429 with `Retry-After`, 503, concurrency waits, content policy, idempotency key reused on every retry, unknown outcomes never rejected;
- polling repairs a generation whose webhook never arrived, and charges once.

**Webhook drill (local, end to end, nothing faked).** Pest tests run inside a rolled-back
transaction and a single process, so they can't prove what happens when real requests and
several workers race. `pixelforge:webhook-drill` sends **real signed HTTP requests** to the
running app, then processes the queue with **several workers in parallel**, and checks the
final generation, webhook events, ledger and balance:

```bash
PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8011 --no-reload   # requests really overlap
php artisan pixelforge:webhook-drill --url=http://127.0.0.1:8011 --workers=4
```

| Scenario | Expected final state |
| --- | --- |
| Same `succeeded` event 5× at once | 1 event row, charged $0.381888 once, 1 charge + 1 release in the ledger |
| `succeeded`, then a late `processing` | stays succeeded, charged once |
| `succeeded`, then `failed` | stays succeeded, charged once, no error |
| Stale timestamp (301 s) / tampered body | 400, nothing stored, untouched, hold still in place |
| Unknown event type with a succeeded object | 204, stored and processed, generation **not** changed |
| Webhook before the create call returned, then the late 202 | matched via metadata, succeeded, charged once; the late `queued` is ignored |

All 39 checks pass (balance = ledger sum for every user; queue drained; no failed jobs). The 5
concurrent copies dispatch 5 jobs that 4 workers apply at the same time, so this exercises the
row lock for real. Breaking the forward-only rule makes the drill fail. It refuses to run in
production, and deletes its rows afterwards (`--keep` to inspect them).

Beyond the test suite, I ran every keyword against the real API through the real worker and
webhooks. That's how I found that waiting for a concurrency slot was spending the shared rate
limit, which is now fixed.

## Deployment

The app runs on a shared Ubuntu 24.04 server next to other applications, so the setup follows
the server admin's rules. Everything is in [`deploy/`](deploy/), with a step-by-step guide in
[`deploy/DEPLOY.md`](deploy/DEPLOY.md):

- **Nginx** site on port 8200 with basic auth. Only `/webhooks/pixelforge` is excluded, and it's passed straight to PHP-FPM so no internal redirect re-applies the password.
- **PHP 8.4 FPM pool** running as its own user, installed alongside the server's default PHP 8.2.
- **SQLite** in `IMMEDIATE`/WAL mode, since there's no MySQL on that server. The whole suite runs on both databases in CI.
- **Cron:** `schedule:run` every minute, plus a `flock`-guarded `queue:work --max-time=55` (there's no Supervisor).
- **`deploy/deploy.sh`:** pull, `composer install --no-dev`, build, migrate, cache, `queue:restart`.

## Trade-offs and decisions

- **Integer nano/micro-dollars instead of DECIMAL.** Exact in every database and in PHP, and it matches `/v1/usage` digit for digit.
- **Half-up to the micro-dollar.** It's the rounding customers expect. The worst-case bias is half a micro-dollar per generation, far below the 25% markup, so we never charge below cost.
- **A rejection only for refusals Pixelforge guarantees.** Timeouts and unknown 5xx stay pending, so our books never disagree with what Pixelforge billed.
- **Browser polling instead of WebSockets.** Generations take 5–90 seconds, so a 3-second poll looks the same to the user and needs no extra server process. `ApplyProviderUpdate` is the place to fire a broadcast event later.
- **Images aren't stored.** The assessment allows expired images, so we show an "image link expired" state.
- **Basic auth on the live site** is the server admin's requirement for this shared machine, not part of the app.

## What I'd do next

1. **Real payments** (Stripe Checkout) behind "Buy credits", writing the same `purchase` ledger rows from a verified payment webhook.
2. **Store images** in object storage on success, so history never expires.
3. **Broadcast status changes** (Laravel Reverb) instead of browser polling once there are many concurrent users.
4. **Alerting** on stuck pending generations, unprocessed webhook events and critical log entries.
