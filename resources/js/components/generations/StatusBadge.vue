<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    CircleSlash,
    Clock,
    Loader2,
    XCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import type { GenerationStatus } from '@/types/generation';

const props = defineProps<{
    status: GenerationStatus;
    label: string;
    retrying?: boolean;
}>();

const style = computed(() => {
    if (props.retrying) {
        return {
            icon: AlertTriangle,
            spin: false,
            classes:
                'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',
        };
    }

    switch (props.status) {
        case 'succeeded':
            return {
                icon: CheckCircle2,
                spin: false,
                classes:
                    'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
            };
        case 'failed':
            return {
                icon: XCircle,
                spin: false,
                classes:
                    'bg-red-50 text-red-800 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
            };
        case 'rejected':
            return {
                icon: CircleSlash,
                spin: false,
                classes:
                    'bg-neutral-100 text-neutral-700 ring-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:ring-neutral-700',
            };
        case 'processing':
            return {
                icon: Loader2,
                spin: true,
                classes:
                    'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900',
            };
        default:
            // pending, queued
            return {
                icon: Clock,
                spin: false,
                classes:
                    'bg-sky-50 text-sky-800 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-900',
            };
    }
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset"
        :class="style.classes"
        data-test="status-badge"
    >
        <component
            :is="style.icon"
            class="size-3.5"
            :class="{ 'animate-spin': style.spin }"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
