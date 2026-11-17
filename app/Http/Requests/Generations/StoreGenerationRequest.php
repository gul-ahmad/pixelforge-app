<?php

namespace App\Http\Requests\Generations;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own limits, so a request we accept is one Pixelforge accepts
 * (a 422 from Pixelforge would mean our validation has drifted).
 */
class StoreGenerationRequest extends FormRequest
{
    public const int MAX_PROMPT_CHARACTERS = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    public function authorize(): bool
    {
        return true;   // any signed-in user may generate (route is behind auth)
    }

    /** Drop the empty URL rows the form may send. */
    protected function prepareForValidation(): void
    {
        $images = $this->input('reference_images');

        if (is_array($images)) {
            $this->merge([
                'reference_images' => array_values(array_filter($images, fn ($url) => $url !== null && $url !== '')),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Issued by the server when the form is rendered; makes double submits harmless.
            'client_request_id' => ['required', 'uuid'],
            // Laravel's max on strings counts Unicode characters (mb_strlen), like Pixelforge.
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            // Each image is billed (765 tokens), so duplicates are almost certainly a mistake.
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most :max reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.*.distinct' => 'This reference image is listed twice (each one is billed).',
        ];
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        /** @var list<string> */
        return $this->validated('reference_images') ?? [];
    }
}
