<?php

namespace App\Http\Requests\Generations;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The live estimate only needs the prompt's length, never the prompt itself.
 * The final estimate is recomputed on submit from the real prompt.
 */
class EstimateGenerationCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'characters' => ['required', 'integer', 'min:0', 'max:'.StoreGenerationRequest::MAX_PROMPT_CHARACTERS],
            'reference_images' => ['required', 'integer', 'min:0', 'max:'.StoreGenerationRequest::MAX_REFERENCE_IMAGES],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
        ];
    }
}
