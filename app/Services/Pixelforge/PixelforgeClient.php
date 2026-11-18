<?php

namespace App\Services\Pixelforge;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP wrapper around the Pixelforge API. It knows URLs, headers and error shapes;
 * it does not retry and has no business rules (jobs and actions decide what to do).
 */
class PixelforgeClient
{
    public function __construct(
        #[Config('services.pixelforge.base_url')] private readonly string $baseUrl,
        #[Config('services.pixelforge.api_key')] private readonly ?string $apiKey,
        #[Config('services.pixelforge.timeout')] private readonly int $timeout,
        #[Config('services.pixelforge.connect_timeout')] private readonly int $connectTimeout,
        #[Config('services.pixelforge.pricing_timeout')] private readonly int $pricingTimeout,
    ) {}

    /**
     * POST /v1/generations. The same Idempotency-Key must be sent on every retry of one
     * submission, so a retry can never create (and bill) a second generation.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws PixelforgeApiException on any non-2xx answer
     * @throws ConnectionException when no answer arrived (the outcome is unknown)
     */
    public function createGeneration(array $payload, string $idempotencyKey): ProviderGeneration
    {
        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $payload);

        if (! $response->successful()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        if ($response->header('Idempotent-Replayed') === 'true') {
            Log::info('Pixelforge replayed an idempotent create.', ['idempotency_key' => $idempotencyKey]);
        }

        return ProviderGeneration::fromApi((array) $response->json());
    }

    /**
     * GET /v1/generations/{id}.
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function getGeneration(string $providerGenerationId): ProviderGeneration
    {
        $response = $this->request()->get('generations/'.rawurlencode($providerGenerationId));

        if (! $response->successful()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        return ProviderGeneration::fromApi((array) $response->json());
    }

    /**
     * GET /v1/usage: what Pixelforge billed THIS API key per UTC day (by completion day).
     * At most 92 days per request.
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function usage(string $startDate, string $endDate): array
    {
        $response = $this->request()->get('usage', ['start_date' => $startDate, 'end_date' => $endDate]);

        if (! $response->successful()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        return (array) $response->json();
    }

    /**
     * GET /v1/pricing (no authentication needed).
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function pricing(): array
    {
        return (array) Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout($this->pricingTimeout)
            ->connectTimeout($this->connectTimeout)
            ->get('pricing')
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);
    }
}
