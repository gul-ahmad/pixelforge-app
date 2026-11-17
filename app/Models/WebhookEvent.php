<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A verified Pixelforge webhook delivery, stored once per provider event id.
 *
 * @property int $id
 * @property string $provider_event_id
 * @property string $type
 * @property string|null $provider_generation_id
 * @property int $delivery_attempt
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'provider_event_id',
    'type',
    'provider_generation_id',
    'delivery_attempt',
    'payload',
    'received_at',
])]
class WebhookEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempt' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
