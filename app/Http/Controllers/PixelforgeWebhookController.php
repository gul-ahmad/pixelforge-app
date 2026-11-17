<?php

namespace App\Http\Controllers;

use App\Actions\Webhooks\RecordWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use JsonException;

/**
 * POST /webhooks/pixelforge. The signature is already verified by middleware.
 *
 * Kept deliberately small and fast: store the event once, queue the work, answer 2xx.
 * Pixelforge counts any 2xx within 10 seconds as delivered; anything else is retried.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, RecordWebhookEvent $recordEvent): Response
    {
        try {
            $event = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            // The attempt number is informational only (headers aren't signed).
            $attempt = (int) $request->header('Pixelforge-Delivery-Attempt', '1');

            $recordEvent->handle(is_array($event) ? $event : [], $attempt);
        } catch (JsonException|InvalidArgumentException) {
            // Signed by Pixelforge but not an event we understand: retrying won't help.
            return response('Malformed event.', 400);
        }

        return response()->noContent();
    }
}
