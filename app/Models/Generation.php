<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string|null $provider_generation_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property int $estimated_min_micro_usd
 * @property int $estimated_max_micro_usd
 * @property array<string, int>|null $usage
 * @property array<string, string>|null $rates
 * @property int|null $provider_cost_nano_usd
 * @property int|null $charged_micro_usd
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property Carbon|null $submitted_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
#[Fillable([
    'user_id',
    'idempotency_key',
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
            'provider_cost_nano_usd' => 'integer',
            'charged_micro_usd' => 'integer',
            'submit_attempts' => 'integer',
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
    public function imageUrlHasExpired(?Carbon $now = null): bool
    {
        return $this->image_url_expires_at !== null
            && $this->image_url_expires_at->lessThanOrEqualTo($now ?? Carbon::now());
    }
}
