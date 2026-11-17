<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { RefreshCw, Sparkles } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type {
    Generation,
    GenerationSummary,
    Paginated,
} from '@/types/generation';

const props = defineProps<{
    generations: Paginated<Generation>;
    summary: GenerationSummary;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

/*
 * Live updates. While anything on this page is still running, ask the server for fresh
 * data every 3 seconds (a partial reload of just these two props, no full page load).
 * When everything is finished, stop, so an idle tab costs nothing.
 */
const hasActive = computed(() =>
    props.generations.data.some((generation) => !generation.is_terminal),
);

const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'summary'] },
    { autoStart: false },
);

watch(hasActive, (active) => (active ? start() : stop()), { immediate: true });
</script>

<template>
    <Head title="History" />

    <div class="mx-auto w-full max-w-5xl p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="History"
                description="Your generations, newest first. You're only charged for images that succeed."
            />
            <Button as-child>
                <Link :href="create()"><Sparkles /> Generate</Link>
            </Button>
        </div>

        <div
            v-if="generations.meta.total > 0"
            class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border bg-muted/30 px-4 py-3 text-sm"
        >
            <span>
                <span class="mr-1 text-muted-foreground">Total charged:</span>
                <strong class="tabular-nums" data-test="total-charged">{{
                    summary.total_charged
                }}</strong>
            </span>
            <span class="text-muted-foreground">
                {{ summary.succeeded }} succeeded of
                {{ generations.meta.total }}
            </span>
            <span
                v-if="hasActive"
                class="inline-flex items-center gap-1 text-muted-foreground"
                aria-live="polite"
            >
                <RefreshCw class="size-3.5 animate-spin" aria-hidden="true" />
                Updating live
            </span>
        </div>

        <div
            v-if="generations.data.length === 0"
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center"
        >
            <p class="text-sm text-muted-foreground">
                No generations yet. You'll see the price range before you
                submit, and failed generations are free.
            </p>
            <Button as-child variant="outline">
                <Link :href="create()"
                    ><Sparkles /> Generate your first image</Link
                >
            </Button>
        </div>

        <ul class="space-y-3">
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </ul>

        <nav
            v-if="generations.meta.last_page > 1"
            class="mt-6 flex items-center justify-between text-sm"
            aria-label="Pagination"
        >
            <Button
                v-if="generations.links.prev"
                as-child
                variant="outline"
                size="sm"
            >
                <Link :href="generations.links.prev" preserve-scroll
                    >Newer</Link
                >
            </Button>
            <span v-else></span>
            <span class="text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Button
                v-if="generations.links.next"
                as-child
                variant="outline"
                size="sm"
            >
                <Link :href="generations.links.next" preserve-scroll
                    >Older</Link
                >
            </Button>
            <span v-else></span>
        </nav>
    </div>
</template>
