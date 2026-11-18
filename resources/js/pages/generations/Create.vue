<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ImagePlus, Info, Wallet, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index as creditsIndex } from '@/routes/credits';
import { create, estimate as estimateRoute, store } from '@/routes/generations';
import type {
    CostEstimate,
    GenerationPrefill,
    SelectOption,
} from '@/types/generation';

const props = defineProps<{
    sizes: SelectOption[];
    qualities: SelectOption[];
    limits: { prompt: number; reference_images: number };
    initialEstimate: CostEstimate | null;
    clientRequestId: string;
    /** Empty values, or an earlier generation's inputs when the user clicked "Try again". */
    prefill: GenerationPrefill;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

const form = useForm({
    // Same id on every resubmit of this form, so a double submit can't create two generations.
    client_request_id: props.clientRequestId,
    prompt: props.prefill.prompt,
    size: props.prefill.size,
    quality: props.prefill.quality,
    reference_images: [...props.prefill.reference_images],
});

// Unicode characters, the same way the server (mb_strlen) and Pixelforge count them.
const characters = computed(() => [...form.prompt].length);
const imageCount = computed(
    () => form.reference_images.filter((url) => url.trim() !== '').length,
);
const promptTooLong = computed(() => characters.value > props.limits.prompt);

/* ---------- Live estimate (debounced, server-calculated) ---------- */

const estimate = ref<CostEstimate | null>(props.initialEstimate);
const estimateLoading = ref(false);
const estimateError = ref<string | null>(
    props.initialEstimate ? null : 'Pricing is temporarily unavailable.',
);

let debounce: ReturnType<typeof setTimeout> | undefined;
let inFlight: AbortController | null = null;

async function refreshEstimate(): Promise<void> {
    if (promptTooLong.value) {
        return;
    }

    inFlight?.abort();
    inFlight = new AbortController();
    estimateLoading.value = true;

    try {
        const response = await fetch(
            estimateRoute.url({
                query: {
                    characters: characters.value,
                    reference_images: imageCount.value,
                    size: form.size,
                    quality: form.quality,
                },
            }),
            {
                headers: { Accept: 'application/json' },
                signal: inFlight.signal,
            },
        );

        if (!response.ok) {
            throw new Error(
                response.status === 503
                    ? 'Pricing is temporarily unavailable.'
                    : 'Could not calculate an estimate.',
            );
        }

        estimate.value = (await response.json()) as CostEstimate;
        estimateError.value = null;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            estimateError.value = (error as Error).message;
        }
    } finally {
        estimateLoading.value = false;
    }
}

watch([characters, imageCount, () => form.size, () => form.quality], () => {
    clearTimeout(debounce);
    debounce = setTimeout(refreshEstimate, 300);
});

onBeforeUnmount(() => {
    clearTimeout(debounce);
    inFlight?.abort();
});

/* ---------- Reference images ---------- */

// Kept in script: a "//" inside a component attribute within v-for trips up vue-tsc.
const referenceImagePlaceholder = 'https://example.com/style.png';

const canAddImage = computed(
    () => form.reference_images.length < props.limits.reference_images,
);

function addReferenceImage(): void {
    if (canAddImage.value) {
        form.reference_images.push('');
    }
}

function removeReferenceImage(index: number): void {
    form.reference_images.splice(index, 1);
}

function imageError(index: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `reference_images.${index}`
    ];
}

/* ---------- Submit ---------- */

// Form-level problems shown next to the button: pricing unavailable, not enough credits,
// or submitting too fast.
const formError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.pricing ?? errors.credits ?? errors.submit;
});

/* ---------- Credits ---------- */

const page = usePage();
const credits = computed(() => page.props.credits);

// The maximum is held from the balance when you submit, so it must fit. (Integers only;
// the server checks this again and is the one that decides.)
const canAfford = computed(
    () =>
        estimate.value === null ||
        credits.value === null ||
        credits.value.balance_micro_usd >= estimate.value.max_micro_usd,
);

const canSubmit = computed(
    () =>
        !form.processing &&
        form.prompt.trim() !== '' &&
        !promptTooLong.value &&
        estimate.value !== null &&
        canAfford.value,
);

function submit(): void {
    form.submit(store(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Generate an image" />

    <div class="mx-auto w-full max-w-5xl p-4 md:p-6">
        <Heading
            title="Generate an image"
            description="Describe what you want. You'll see the price range before you submit."
        />

        <form
            class="grid gap-6 lg:grid-cols-[1fr_20rem]"
            @submit.prevent="submit"
        >
            <!-- Left: what to generate -->
            <div class="space-y-6">
                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span
                            class="text-xs tabular-nums"
                            :class="
                                promptTooLong
                                    ? 'text-red-600 dark:text-red-500'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ characters.toLocaleString() }} /
                            {{ limits.prompt.toLocaleString() }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="6"
                        required
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        :aria-invalid="!!form.errors.prompt || promptTooLong"
                        class="w-full min-w-0 resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                    ></textarea>
                    <InputError :message="form.errors.prompt" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label
                            v-for="size in sizes"
                            :key="size.value"
                            class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
                        >
                            <input
                                v-model="form.size"
                                type="radio"
                                name="size"
                                :value="size.value"
                                class="accent-primary"
                            />
                            {{ size.label }}
                        </label>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="quality in qualities"
                            :key="quality.value"
                            class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
                        >
                            <input
                                v-model="form.quality"
                                type="radio"
                                name="quality"
                                :value="quality.value"
                                class="accent-primary"
                            />
                            {{ quality.label }}
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Higher quality costs more and takes longer.
                    </p>
                    <InputError :message="form.errors.quality" />
                </fieldset>

                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <span class="text-sm font-medium"
                            >Reference images
                            <span class="font-normal text-muted-foreground"
                                >(optional)</span
                            ></span
                        >
                        <span class="text-xs text-muted-foreground">
                            {{ form.reference_images.length }} /
                            {{ limits.reference_images }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Public http(s) image URLs to guide the style. Each one
                        adds to the cost.
                    </p>

                    <div
                        v-for="(url, index) in form.reference_images"
                        :key="index"
                        class="grid gap-1"
                    >
                        <div class="flex gap-2">
                            <Input
                                :model-value="url"
                                @update:model-value="
                                    form.reference_images[index] =
                                        String($event)
                                "
                                type="url"
                                inputmode="url"
                                :aria-label="`Reference image ${index + 1}`"
                                :placeholder="referenceImagePlaceholder"
                                :aria-invalid="!!imageError(index)"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="removeReferenceImage(index)"
                            >
                                <X />
                            </Button>
                        </div>
                        <InputError :message="imageError(index)" />
                    </div>

                    <InputError :message="form.errors.reference_images" />

                    <Button
                        v-if="canAddImage"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        @click="addReferenceImage"
                    >
                        <ImagePlus /> Add reference image
                    </Button>
                </div>
            </div>

            <!-- Right: cost, always visible next to the submit button -->
            <aside class="lg:sticky lg:top-4 lg:self-start">
                <div class="space-y-4 rounded-xl border p-4">
                    <div>
                        <p class="text-sm font-medium">Estimated cost</p>
                        <p
                            v-if="estimate"
                            class="mt-1 text-2xl font-semibold tabular-nums"
                            :class="{ 'opacity-60': estimateLoading }"
                            aria-live="polite"
                        >
                            {{ estimate.min }} – {{ estimate.max }}
                        </p>
                        <p
                            v-else
                            class="mt-1 text-sm text-red-600 dark:text-red-500"
                        >
                            {{ estimateError }}
                        </p>
                        <p
                            v-if="estimate && estimateError"
                            class="mt-1 text-xs text-amber-600 dark:text-amber-500"
                        >
                            {{ estimateError }} Showing the last estimate.
                        </p>
                    </div>

                    <div
                        class="flex gap-2 rounded-md bg-muted/60 p-3 text-xs text-muted-foreground"
                    >
                        <Info class="mt-0.5 size-4 shrink-0" />
                        <p>
                            You'll be charged the
                            <strong class="text-foreground">actual cost</strong>
                            after completion, which falls within this range.
                            <strong class="text-foreground"
                                >Failed generations are free.</strong
                            >
                        </p>
                    </div>

                    <div
                        v-if="credits"
                        class="flex items-center justify-between gap-2 border-t pt-3 text-sm"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <Wallet
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Balance
                            <strong class="tabular-nums" data-test="balance">{{
                                credits.balance
                            }}</strong>
                        </span>
                        <Link
                            :href="creditsIndex()"
                            class="text-xs text-primary underline-offset-4 hover:underline"
                            >Buy credits</Link
                        >
                    </div>
                    <p
                        v-if="!canAfford && estimate"
                        class="text-sm text-amber-700 dark:text-amber-400"
                        role="status"
                    >
                        This can cost up to {{ estimate.max }}, which is held
                        from your balance when you submit. Buy credits to
                        continue.
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        Up to the maximum is held from your balance when you
                        submit; anything not used is returned.
                    </p>

                    <InputError :message="formError" />

                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="!canSubmit"
                        data-test="generate-button"
                    >
                        <Spinner v-if="form.processing" />
                        {{ form.processing ? 'Submitting…' : 'Generate' }}
                    </Button>
                </div>
            </aside>
        </form>
    </div>
</template>
