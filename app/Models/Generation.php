<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image generation requested by a user, and what it cost them.
 *
 * Status, provider and money fields are deliberately NOT mass assignable: they are only
 * changed in one place (the generation state service, ApplyProviderUpdate), inside a
 * transaction with a row lock. New rows always start as pending.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $idempotency_key
 * @property string|null $client_request_id
 * @property string|null $provider_generation_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property int $estimated_min_micro_usd
 * @property int $estimated_max_micro_usd
 * @property int $reserved_micro_usd
 * @property array<string, int>|null $usage
 * @property array<string, string>|null $rates
 * @property int|null $provider_cost_nano_usd
 * @property int|null $charged_micro_usd
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property bool $submit_outcome_unknown
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 */
#[Fillable([
    'user_id',
    'idempotency_key',
    'client_request_id',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'estimated_min_micro_usd',
    'estimated_max_micro_usd',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * Defaults for a new row: saved locally, not yet sent to Pixelforge.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'reference_images' => '[]',
        'submit_attempts' => 0,
        'submit_outcome_unknown' => false,
        'reserved_micro_usd' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'estimated_min_micro_usd' => 'integer',
            'estimated_max_micro_usd' => 'integer',
            'reserved_micro_usd' => 'integer',
            'provider_cost_nano_usd' => 'integer',
            'charged_micro_usd' => 'integer',
            'submit_attempts' => 'integer',
            'submit_outcome_unknown' => 'boolean',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Pixelforge image URLs are signed and stop working 24 hours after completion. */
    public function imageUrlHasExpired(?CarbonImmutable $now = null): bool
    {
        return $this->image_url_expires_at !== null
            && $this->image_url_expires_at->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }
}
