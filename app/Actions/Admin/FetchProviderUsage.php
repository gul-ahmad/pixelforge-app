<?php

namespace App\Actions\Admin;

use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * What Pixelforge says it billed our API key, per UTC day (GET /v1/usage). This is the
 * source of truth we reconcile our own provider costs against.
 */
class FetchProviderUsage
{
    /** Short cache: the page may be refreshed a few times, and the API limit is shared. */
    private const int CACHE_SECONDS = 60;

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array<string, array{generations: int, cost_nano_usd: int}>|null keyed by Y-m-d;
     *                                                                         null when Pixelforge can't be asked right now
     */
    public function handle(CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        try {
            /** @var array<string, array{generations: int, cost_nano_usd: int}> */
            return Cache::remember(
                "pixelforge.usage.{$startDate}.{$endDate}",
                self::CACHE_SECONDS,
                fn () => $this->parse($this->client->usage($startDate, $endDate)),
            );
        } catch (PixelforgeApiException|ConnectionException|InvalidArgumentException $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{generations: int, cost_nano_usd: int}>
     */
    private function parse(array $response): array
    {
        $days = [];

        foreach ((array) ($response['data'] ?? []) as $row) {
            $date = $row['date'] ?? null;
            $generations = $row['generations'] ?? null;
            $cost = $row['cost_usd'] ?? null;

            if (! is_string($date) || ! is_int($generations) || ! is_string($cost)) {
                throw new InvalidArgumentException('Unexpected row in Pixelforge usage response.');
            }

            $days[$date] = ['generations' => $generations, 'cost_nano_usd' => Pricing::usdStringToNano($cost)];
        }

        return $days;
    }
}
