<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { relativeTime } from '@/lib/time';
import { index, store } from '@/routes/credits';
import type { Paginated } from '@/types/generation';

type Pack = { micro_usd: number; label: string };
type Transaction = {
    id: number;
    type: 'purchase' | 'hold' | 'release' | 'charge';
    label: string;
    amount: string;
    is_credit: boolean;
    balance_after: string;
    generation_prompt: string | null;
    created_at: string;
};

const props = defineProps<{
    balance: string;
    packs: Pack[];
    clientRequestId: string;
    transactions: Paginated<Transaction>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const form = useForm({
    pack_micro_usd: 0,
    // Same id on every resubmit of this page's form, so a double-click buys only once.
    client_request_id: props.clientRequestId,
});

// After a purchase the server renders this page again with a NEW id. Take it, so the next
// (intentional) purchase isn't mistaken for a repeat of the previous one.
watch(
    () => props.clientRequestId,
    (id) => {
        form.client_request_id = id;
    },
);

function buy(pack: Pack): void {
    form.pack_micro_usd = pack.micro_usd;
    form.submit(store(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <Heading
            title="Credits"
            description="Generations are paid from your balance. The most a generation can cost is held when you submit; you pay the actual cost and the rest comes back. Failed generations are free."
        />

        <section class="rounded-xl border p-5">
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <Wallet class="size-4" aria-hidden="true" /> Your balance
            </div>
            <p
                class="mt-1 text-3xl font-semibold tabular-nums"
                data-test="credits-balance"
            >
                {{ balance }}
            </p>

            <h2 class="mt-6 text-sm font-medium">Buy credits</h2>
            <div class="mt-2 flex flex-wrap gap-2">
                <Button
                    v-for="pack in packs"
                    :key="pack.micro_usd"
                    variant="outline"
                    :disabled="form.processing"
                    :data-test="`buy-${pack.micro_usd}`"
                    @click="buy(pack)"
                >
                    <Spinner
                        v-if="
                            form.processing &&
                            form.pack_micro_usd === pack.micro_usd
                        "
                    />
                    Add {{ pack.label }}
                </Button>
            </div>
            <InputError class="mt-2" :message="form.errors.pack_micro_usd" />
            <p class="mt-2 text-xs text-muted-foreground">
                Demo purchase: no payment is taken.
            </p>
        </section>

        <section class="space-y-2">
            <h2 class="text-base font-semibold">History</h2>
            <p
                v-if="transactions.data.length === 0"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No credit activity yet.
            </p>
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full min-w-[560px] text-sm">
                    <thead
                        class="bg-muted/40 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">When</th>
                            <th class="px-3 py-2 font-medium">What</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Amount
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Balance
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="transaction in transactions.data"
                            :key="transaction.id"
                            class="border-t"
                        >
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                <time :datetime="transaction.created_at">{{
                                    relativeTime(transaction.created_at)
                                }}</time>
                            </td>
                            <td class="px-3 py-2">
                                {{ transaction.label }}
                                <span
                                    v-if="transaction.generation_prompt"
                                    class="block max-w-xs truncate text-xs text-muted-foreground"
                                    >{{ transaction.generation_prompt }}</span
                                >
                            </td>
                            <td
                                class="px-3 py-2 text-right tabular-nums"
                                :class="
                                    transaction.is_credit
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : ''
                                "
                            >
                                {{ transaction.amount }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ transaction.balance_after }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="transactions.meta.last_page > 1"
                class="flex items-center justify-between text-sm"
                aria-label="Pagination"
            >
                <Button
                    v-if="transactions.links.prev"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <Link :href="transactions.links.prev" preserve-scroll
                        >Newer</Link
                    >
                </Button>
                <span v-else></span>
                <span class="text-muted-foreground"
                    >Page {{ transactions.meta.current_page }} of
                    {{ transactions.meta.last_page }}</span
                >
                <Button
                    v-if="transactions.links.next"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <Link :href="transactions.links.next" preserve-scroll
                        >Older</Link
                    >
                </Button>
                <span v-else></span>
            </nav>
        </section>
    </div>
</template>
