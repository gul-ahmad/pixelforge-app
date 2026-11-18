<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronRight,
    CloudOff,
} from '@lucide/vue';
import { reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report as reportRoute } from '@/routes/admin';
import type { SpendReport } from '@/types/admin';

const props = defineProps<{ report: SpendReport; maxDays: number }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: reportRoute() }],
    },
});

const range = reactive({
    start_date: props.report.range.start,
    end_date: props.report.range.end,
});

const open = ref<Set<string>>(new Set());

function toggle(date: string): void {
    const next = new Set(open.value);

    if (next.has(date)) {
        next.delete(date);
    } else {
        next.add(date);
    }

    open.value = next;
}

function apply(): void {
    router.get(reportRoute.url(), range, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin report" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
        <Heading
            title="Admin report"
            description="What users were charged, what Pixelforge charged us, and the profit, per UTC day and per user."
        />

        <!-- Range -->
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div class="grid gap-1">
                <Label for="start_date">From (UTC)</Label>
                <Input
                    id="start_date"
                    v-model="range.start_date"
                    type="date"
                    class="w-44"
                />
            </div>
            <div class="grid gap-1">
                <Label for="end_date">To (UTC)</Label>
                <Input
                    id="end_date"
                    v-model="range.end_date"
                    type="date"
                    class="w-44"
                />
            </div>
            <Button type="submit" variant="outline">Show</Button>
            <span class="text-xs text-muted-foreground"
                >Up to {{ maxDays }} days (Pixelforge's usage limit).</span
            >
        </form>

        <!-- Reconciliation verdict -->
        <div
            v-if="!report.pixelforge_available"
            class="flex gap-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
        >
            <CloudOff class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            Couldn't reach Pixelforge's usage report right now. Showing our own
            numbers only; refresh in a minute to compare.
        </div>
        <div
            v-else-if="report.totals.matches"
            class="flex gap-2 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
            data-test="reconciled"
        >
            <CheckCircle2 class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            Our provider costs match Pixelforge's usage report exactly for every
            day in this range ({{ report.totals.provider_cost }}).
        </div>
        <div
            v-else
            class="flex gap-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
            data-test="not-reconciled"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                Our provider costs ({{ report.totals.provider_cost }}) differ
                from Pixelforge's ({{ report.totals.pixelforge_cost }}). Usually
                a generation that just finished and hasn't synced yet: polling
                catches up within a few minutes. Days that differ are marked
                below.
            </span>
        </div>

        <!-- Totals -->
        <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-xl border p-4">
                <dt class="text-xs text-muted-foreground">Charged to users</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums">
                    {{ report.totals.charged }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-xs text-muted-foreground">
                    Pixelforge cost (our records)
                </dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums">
                    {{ report.totals.provider_cost }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-xs text-muted-foreground">Profit</dt>
                <dd
                    class="mt-1 text-lg font-semibold text-emerald-700 tabular-nums dark:text-emerald-400"
                >
                    {{ report.totals.profit }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-xs text-muted-foreground">
                    Billed generations
                </dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums">
                    {{ report.totals.generations }}
                </dd>
            </div>
        </dl>

        <!-- By day -->
        <section class="space-y-2">
            <h2 class="text-base font-semibold">By day</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full min-w-[760px] text-sm">
                    <thead
                        class="bg-muted/40 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Day (UTC)</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Gens
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Charged
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pixelforge says
                            </th>
                            <th class="px-3 py-2 font-medium">Check</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="report.days.length === 0">
                            <td
                                colspan="7"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No billed generations in this range.
                            </td>
                        </tr>
                        <template v-for="day in report.days" :key="day.date">
                            <tr class="border-t">
                                <td class="px-3 py-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 font-medium"
                                        :aria-expanded="open.has(day.date)"
                                        @click="toggle(day.date)"
                                    >
                                        <ChevronRight
                                            class="size-4 transition-transform"
                                            :class="{
                                                'rotate-90': open.has(day.date),
                                            }"
                                            aria-hidden="true"
                                        />
                                        {{ day.date }}
                                    </button>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ day.generations }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ day.charged }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ day.provider_cost }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ day.profit }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right text-muted-foreground tabular-nums"
                                >
                                    <template v-if="day.pixelforge">
                                        {{ day.pixelforge.cost }} ·
                                        {{ day.pixelforge.generations }} gens
                                    </template>
                                    <template v-else>—</template>
                                </td>
                                <td class="px-3 py-2">
                                    <span
                                        v-if="day.matches === true"
                                        class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"
                                        ><CheckCircle2
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Matches</span
                                    >
                                    <span
                                        v-else-if="day.matches === false"
                                        class="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400"
                                        ><AlertTriangle
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Off by {{ day.difference }}</span
                                    >
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                            </tr>
                            <tr
                                v-for="user in open.has(day.date)
                                    ? day.users
                                    : []"
                                :key="`${day.date}-${user.user_id}`"
                                class="bg-muted/20 text-xs"
                            >
                                <td class="py-1.5 pr-3 pl-9">
                                    {{ user.name }}
                                    <span class="text-muted-foreground">{{
                                        user.email
                                    }}</span>
                                </td>
                                <td class="px-3 py-1.5 text-right tabular-nums">
                                    {{ user.generations }}
                                </td>
                                <td class="px-3 py-1.5 text-right tabular-nums">
                                    {{ user.charged }}
                                </td>
                                <td class="px-3 py-1.5 text-right tabular-nums">
                                    {{ user.provider_cost }}
                                </td>
                                <td class="px-3 py-1.5 text-right tabular-nums">
                                    {{ user.profit }}
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- By user -->
        <section class="space-y-2">
            <h2 class="text-base font-semibold">By user</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full min-w-[640px] text-sm">
                    <thead
                        class="bg-muted/40 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">User</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Gens
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Charged
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="report.users.length === 0">
                            <td
                                colspan="5"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No billed generations in this range.
                            </td>
                        </tr>
                        <tr
                            v-for="user in report.users"
                            :key="user.user_id ?? 'deleted'"
                            class="border-t"
                        >
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ user.name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ user.email }}
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ user.generations }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ user.charged }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ user.provider_cost }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ user.profit }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <ul class="list-disc space-y-1 pl-5 text-xs text-muted-foreground">
            <li>
                Days are UTC <strong>completion</strong> days, the day
                Pixelforge bills a generation, so they line up with
                <code>GET /v1/usage</code>.
            </li>
            <li>
                Only succeeded generations are billed. Failed and rejected ones
                cost us and the user nothing.
            </li>
            <li>
                Charged = provider cost × 1.25, rounded half-up to the
                micro-dollar per generation. All amounts are exact.
            </li>
            <li>"Pixelforge says" covers only this environment's API key.</li>
        </ul>
    </div>
</template>
