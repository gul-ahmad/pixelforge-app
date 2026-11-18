<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Actions\Generations\EstimateGenerationCost;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PricingUnavailableException;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Support\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * History: the user's generations, newest first.
     * Props are closures so the page's live polling can reload just what it needs.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('generations/Index', [
            'generations' => fn () => GenerationResource::collection(
                $user->generations()->latest()->latest('id')->paginate(20)
            ),
            'summary' => fn () => [
                'total_charged' => Pricing::formatMicroUsd(
                    (int) $user->generations()->where('status', GenerationStatus::Succeeded)->sum('charged_micro_usd')
                ),
                'succeeded' => $user->generations()->where('status', GenerationStatus::Succeeded)->count(),
            ],
        ]);
    }

    /**
     * The generate form, with the options and a starting estimate.
     * ?from={id} pre-fills it from one of the user's earlier generations ("Try again").
     */
    public function create(Request $request, EstimateGenerationCost $estimate): Response
    {
        $from = $request->filled('from')
            ? $request->user()->generations()->find($request->string('from')->toString())
            : null;

        $prefill = [
            'prompt' => $from->prompt ?? '',
            'size' => ($from->size ?? ImageSize::Square)->value,
            'quality' => ($from->quality ?? ImageQuality::Medium)->value,
            'reference_images' => $from->reference_images ?? [],
        ];

        try {
            $initialEstimate = $estimate->handle(
                mb_strlen($prefill['prompt'], 'UTF-8'),
                count($prefill['reference_images']),
                ImageSize::from($prefill['size']),
                ImageQuality::from($prefill['quality']),
            )->toArray();
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
            'prefill' => $prefill,
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
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages(['credits' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation submitted. You\'ll be charged the actual cost once it completes.')]);

        return to_route('generations.index');
    }
}
