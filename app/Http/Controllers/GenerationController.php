<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Actions\Generations\EstimateGenerationCost;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /** History: the user's generations, newest first. */
    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest()->latest('id')->paginate(20);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
        ]);
    }

    /** The generate form, with the options and a starting estimate. */
    public function create(EstimateGenerationCost $estimate): Response
    {
        try {
            $initialEstimate = $estimate->handle(0, 0, ImageSize::Square, ImageQuality::Medium)->toArray();
        } catch (PricingUnavailableException) {
            $initialEstimate = null;
        }

        return Inertia::render('generations/Create', [
            'sizes' => array_map(fn (ImageSize $s) => ['value' => $s->value, 'label' => $s->label()], ImageSize::cases()),
            'qualities' => array_map(fn (ImageQuality $q) => ['value' => $q->value, 'label' => $q->label()], ImageQuality::cases()),
            'limits' => [
                'prompt' => StoreGenerationRequest::MAX_PROMPT_CHARACTERS,
                'reference_images' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'initialEstimate' => $initialEstimate,
            // A fresh id per rendered form; resubmitting this form can't create a second generation.
            'clientRequestId' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $createGeneration->handle(
                user: $request->user(),
                clientRequestId: $request->string('client_request_id')->toString(),
                prompt: $request->string('prompt')->toString(),
                size: $request->enum('size', ImageSize::class) ?? ImageSize::Square,
                quality: $request->enum('quality', ImageQuality::class) ?? ImageQuality::Medium,
                referenceImages: $request->referenceImages(),
            );
        } catch (PricingUnavailableException $e) {
            // We never submit a paid request without having shown its price.
            throw ValidationException::withMessages(['pricing' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation submitted. You\'ll be charged the actual cost once it completes.')]);

        return to_route('generations.index');
    }
}
