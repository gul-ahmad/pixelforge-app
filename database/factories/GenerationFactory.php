<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * A freshly created, not yet submitted generation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'estimated_min_micro_usd' => 58_125,
            'estimated_max_micro_usd' => 86_250,
        ];
    }

    public function withStatus(GenerationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
