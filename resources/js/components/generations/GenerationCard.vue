<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink, ImageOff, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { relativeTime } from '@/lib/time';
import { create } from '@/routes/generations';
import type { Generation } from '@/types/generation';

const props = defineProps<{ generation: Generation }>();

// The server hides expired URLs, but a page can stay open past the expiry time,
// and a link can also stop working early: treat a failed image load as expired too.
const imageFailed = ref(false);

const imageExpired = computed(
    () =>
        props.generation.image_expired ||
        imageFailed.value ||
        (props.generation.image_url_expires_at !== null &&
            Date.parse(props.generation.image_url_expires_at) <= Date.now()),
);

const showImage = computed(
    () => props.generation.image_url !== null && !imageExpired.value,
);

const canTryAgain = computed(
    () =>
        props.generation.status === 'failed' ||
        props.generation.status === 'rejected',
);

const messageClasses = computed(() => {
    if (props.generation.is_retrying) {
        return 'text-amber-700 dark:text-amber-400';
    }

    if (props.generation.status === 'failed') {
        return 'text-red-700 dark:text-red-400';
    }

    return 'text-muted-foreground';
});
</script>

<template>
    <li
        class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row"
        data-test="generation-card"
    >
        <!-- Image / placeholder -->
        <div
            class="flex aspect-square w-full shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted sm:w-32"
        >
            <a
                v-if="showImage"
                :href="generation.image_url ?? undefined"
                target="_blank"
                rel="noopener noreferrer"
                class="block size-full"
                title="Open full size"
            >
                <img
                    :src="generation.image_url ?? undefined"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="size-full object-cover"
                    @error="imageFailed = true"
                />
            </a>
            <div
                v-else-if="generation.status === 'succeeded'"
                class="flex flex-col items-center gap-1 p-2 text-center text-xs text-muted-foreground"
            >
                <ImageOff class="size-5" aria-hidden="true" />
                Image link expired
            </div>
            <div
                v-else-if="!generation.is_terminal"
                class="size-full animate-pulse bg-muted-foreground/10"
                aria-hidden="true"
            ></div>
            <ImageOff
                v-else
                class="size-5 text-muted-foreground"
                aria-hidden="true"
            />
        </div>

        <!-- What and status -->
        <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <StatusBadge
                    :status="generation.status"
                    :label="generation.status_label"
                    :retrying="generation.is_retrying"
                />
                <span class="text-xs text-muted-foreground">
                    {{ generation.size }} · {{ generation.quality }}
                    <template v-if="generation.reference_images.length">
                        · {{ generation.reference_images.length }} reference
                        {{
                            generation.reference_images.length === 1
                                ? 'image'
                                : 'images'
                        }}
                    </template>
                    ·
                    <time :datetime="generation.created_at ?? undefined">{{
                        relativeTime(generation.created_at)
                    }}</time>
                </span>
            </div>

            <p class="line-clamp-3 text-sm break-words">
                {{ generation.prompt }}
            </p>

            <p
                v-if="generation.status_message"
                class="text-sm"
                :class="messageClasses"
            >
                {{ generation.status_message }}
            </p>

            <div class="flex flex-wrap gap-3 text-sm">
                <a
                    v-if="showImage"
                    :href="generation.image_url ?? undefined"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-primary underline-offset-4 hover:underline"
                >
                    <ExternalLink class="size-3.5" aria-hidden="true" /> Open
                    image
                </a>
                <span
                    v-if="showImage && generation.image_url_expires_at"
                    class="text-xs text-muted-foreground"
                >
                    Link expires
                    {{ relativeTime(generation.image_url_expires_at) }}
                </span>
                <Link
                    v-if="canTryAgain"
                    :href="create({ query: { from: generation.id } })"
                    class="inline-flex items-center gap-1 text-primary underline-offset-4 hover:underline"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" /> Try again
                </Link>
            </div>
        </div>

        <!-- Money: estimate vs actual -->
        <dl
            class="grid shrink-0 grid-cols-2 gap-x-4 gap-y-1 text-sm sm:w-48 sm:grid-cols-1 sm:text-right"
        >
            <div>
                <dt class="text-xs text-muted-foreground">Estimate</dt>
                <dd class="tabular-nums">
                    {{ generation.estimate.min }} –
                    {{ generation.estimate.max }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Charged</dt>
                <dd
                    class="font-semibold tabular-nums"
                    data-test="charged"
                    :class="{
                        'font-normal text-muted-foreground':
                            generation.status !== 'succeeded',
                    }"
                >
                    {{
                        generation.status === 'succeeded'
                            ? generation.charged
                            : generation.is_terminal
                              ? '$0.00'
                              : '—'
                    }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    {{ generation.charge_note }}
                </dd>
            </div>
        </dl>
    </li>
</template>
