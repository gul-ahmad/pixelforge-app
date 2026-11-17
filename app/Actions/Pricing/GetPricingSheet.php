<?php

namespace App\Actions\Pricing;

use App\Exceptions\PricingUnavailableException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\PricingSheet;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Current Pixelforge pricing for estimates, cached.
 *
 * - Fresh copy cached for an hour (rates rarely change; estimates don't need to be live).
 * - The last good copy is kept forever, so an outage at Pixelforge degrades to a slightly
 *   stale estimate instead of a broken page. Real charges never use this: they use the
 *   rates on each generation.
 * - With no copy at all, throw: we refuse to submit without showing the user a price.
 */
class GetPricingSheet
{
    public const string CACHE_KEY = 'pixelforge.pricing';

    public const string LAST_GOOD_CACHE_KEY = 'pixelforge.pricing.last_good';

    /** After a failed refresh, wait this long before asking Pixelforge again. */
    private const int RETRY_AFTER_FAILURE_SECONDS = 60;

    public function __construct(
        private readonly PixelforgeClient $client,
        #[Config('services.pixelforge.pricing_cache_seconds')] private readonly int $cacheSeconds,
    ) {}

    public function handle(): PricingSheet
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return PricingSheet::fromApi($cached);
        }

        try {
            $data = $this->client->pricing();
            $sheet = PricingSheet::fromApi($data);   // validate before caching

            Cache::put(self::CACHE_KEY, $data, $this->cacheSeconds);
            Cache::forever(self::LAST_GOOD_CACHE_KEY, $data);

            return $sheet;
        } catch (ConnectionException|RequestException|InvalidArgumentException $e) {
            report($e);

            $lastGood = Cache::get(self::LAST_GOOD_CACHE_KEY);

            if (! is_array($lastGood)) {
                throw new PricingUnavailableException(previous: $e);
            }

            Cache::put(self::CACHE_KEY, $lastGood, self::RETRY_AFTER_FAILURE_SECONDS);

            return PricingSheet::fromApi($lastGood);
        }
    }
}
