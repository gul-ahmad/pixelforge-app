<?php

namespace App\Http\Middleware;

use App\Services\Pixelforge\WebhookSignature;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any webhook request that isn't signed by Pixelforge with our key's secret.
 * Runs before the controller, so unsigned requests never touch the database.
 */
class VerifyPixelforgeSignature
{
    public function __construct(
        #[Config('services.pixelforge.webhook_secret')] private readonly ?string $secret,
        #[Config('services.pixelforge.webhook_tolerance_seconds')] private readonly int $toleranceSeconds,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->secret === null || $this->secret === '') {
            // Our misconfiguration: answer 5xx so Pixelforge keeps retrying until it's fixed.
            Log::critical('PIXELFORGE_WEBHOOK_SECRET is not set; cannot verify webhooks.');

            return response()->json(['message' => 'Webhook receiver is not configured.'], 500);
        }

        $verifier = new WebhookSignature($this->secret, $this->toleranceSeconds);

        if (! $verifier->isValid($request->getContent(), $request->header('Pixelforge-Signature'), now()->getTimestamp())) {
            Log::warning('Rejected a webhook with an invalid or expired Pixelforge signature.', [
                'ip' => $request->ip(),
                'event_id' => $request->header('Pixelforge-Event-Id'),
            ]);

            return response()->json(['message' => 'Invalid Pixelforge signature.'], 400);
        }

        return $next($request);
    }
}
