<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type { Generation, Paginated } from '@/types/generation';

defineProps<{ generations: Paginated<Generation> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});
</script>

<template>
    <Head title="History" />

    <div class="mx-auto w-full max-w-5xl p-4 md:p-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="History"
                description="Your generations, newest first."
            />
            <Button as-child>
                <Link :href="create()"><Sparkles /> Generate</Link>
            </Button>
        </div>

        <p
            v-if="generations.data.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            No generations yet.
        </p>

        <ul class="space-y-3">
            <li
                v-for="generation in generations.data"
                :key="generation.id"
                class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row"
            >
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex items-center gap-2">
                        <Badge variant="secondary">{{
                            generation.status
                        }}</Badge>
                        <span class="text-xs text-muted-foreground"
                            >{{ generation.size }} ·
                            {{ generation.quality }}</span
                        >
                    </div>
                    <p class="line-clamp-2 text-sm">{{ generation.prompt }}</p>
                    <p
                        v-if="generation.error_message"
                        class="text-xs text-muted-foreground"
                    >
                        {{ generation.error_message }}
                    </p>
                </div>
                <div class="text-sm sm:text-right">
                    <p class="text-xs text-muted-foreground">Estimate</p>
                    <p class="tabular-nums">
                        {{ generation.estimate.min }} –
                        {{ generation.estimate.max }}
                    </p>
                    <template v-if="generation.charged !== null">
                        <p class="mt-1 text-xs text-muted-foreground">
                            Charged
                        </p>
                        <p class="font-medium tabular-nums">
                            {{ generation.charged }}
                        </p>
                    </template>
                </div>
            </li>
        </ul>
    </div>
</template>
