<?php

namespace App\Actions\Polling;

use App\Actions\Generations\ApplyProviderUpdate;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use Brick\Math\Exception\MathException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Asks Pixelforge for the current state of in-flight generations we haven't heard about
 * recently (GET /v1/generations/{id}) and applies it through ApplyProviderUpdate,
 * exactly as a webhook would. This is what rescues a generation whose webhook never came.
 */
class SyncStaleGenerations
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly ApplyProviderUpdate $applyUpdate,
        #[Config('services.pixelforge.polling.stale_after_seconds')] private readonly int $staleAfterSeconds,
        #[Config('services.pixelforge.polling.batch_size')] private readonly int $batchSize,
    ) {}

    /**
     * @return int how many generations were synced
     */
    public function handle(): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_generation_id')
            ->where(fn ($query) => $query
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<=', now()->subSeconds($this->staleAfterSeconds)))
            ->orderByRaw('last_synced_at is not null, last_synced_at')   // never-synced first, then oldest
            ->limit($this->batchSize)
            ->get();

        $synced = 0;

        foreach ($stale as $generation) {
            try {
                $applied = $this->applyUpdate->handle($this->client->getGeneration((string) $generation->provider_generation_id));

                if ($applied === null) {
                    // Refused (e.g. id conflict, already logged): wait a full interval before asking again.
                    $this->markChecked($generation);
                } else {
                    $synced++;
                }
            } catch (InvalidArgumentException|UnexpectedValueException|MathException $e) {
                // A payload we can't safely apply. Never guess with money: report it, move on to
                // the rest of the batch, and look again after the normal interval.
                report($e);
                $this->markChecked($generation);
            } catch (PixelforgeApiException $e) {
                if ($e->status === 429 || $e->status >= 500) {
                    // Rate limited or Pixelforge is struggling: stop this run, try again next minute.
                    Log::notice('Polling paused: Pixelforge answered '.$e->status.'.', ['code' => $e->errorCode]);
                    break;
                }

                // E.g. 404: don't let one bad row be retried every minute forever.
                Log::error('Polling could not fetch a generation.', [
                    'generation_id' => $generation->id,
                    'status' => $e->status,
                    'code' => $e->errorCode,
                ]);
                $this->markChecked($generation);
            } catch (ConnectionException $e) {
                Log::notice('Polling paused: could not reach Pixelforge.', ['error' => $e->getMessage()]);
                break;
            }
        }

        return $synced;
    }

    private function markChecked(Generation $generation): void
    {
        Generation::query()->whereKey($generation->id)->update(['last_synced_at' => now()]);
    }
}
