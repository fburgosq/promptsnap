<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Coins, PlusCircle, Sparkles } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    index as creditsIndex,
    purchase as purchaseCredits,
} from '@/routes/credits';

interface CreditPack {
    usd: number;
    label: string;
    nano: number;
}

interface LedgerItem {
    id: number;
    type: 'purchase' | 'generation_charge';
    amount_nano: number;
    amount_formatted: string;
    generation_id: string | null;
    created_at_formatted: string | null;
}

const props = defineProps<{
    balance_nano: number;
    balance_formatted: string;
    reserved_nano: number;
    reserved_formatted: string;
    available_nano: number;
    available_formatted: string;
    packs: CreditPack[];
    transactions: LedgerItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Credits & Billing',
                href: creditsIndex(),
            },
        ],
    },
});

const purchasingUsd = ref<number | null>(null);

function buyPack(usd: number): void {
    purchasingUsd.value = usd;

    router.post(
        purchaseCredits.url(),
        { pack_usd: usd },
        {
            preserveScroll: true,
            onFinish: () => {
                purchasingUsd.value = null;
            },
        },
    );
}
</script>

<template>
    <div>
        <Head title="Buy Credits" />

        <div class="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-6">
            <Heading
                title="Credits & Ledger"
                description="Purchase credit packs and inspect your nanodollar transaction ledger."
            />

            <!-- Balance Summary -->
            <div class="grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Total Balance</CardDescription>
                        <CardTitle
                            data-test="credits-total-balance"
                            class="font-mono text-2xl tabular-nums"
                        >
                            {{ props.balance_formatted }}
                        </CardTitle>
                    </CardHeader>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>
                            Reserved (In-Flight Max)
                        </CardDescription>
                        <CardTitle
                            data-test="credits-reserved-balance"
                            class="font-mono text-2xl text-muted-foreground tabular-nums"
                        >
                            {{ props.reserved_formatted }}
                        </CardTitle>
                    </CardHeader>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Available to Spend</CardDescription>
                        <CardTitle
                            data-test="credits-available-balance"
                            class="font-mono text-2xl text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            {{ props.available_formatted }}
                        </CardTitle>
                    </CardHeader>
                </Card>
            </div>

            <!-- Credit Packs -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Coins class="size-5" />
                        Buy Credits
                    </CardTitle>
                    <CardDescription>
                        Simulated instant top-up. Credits are added to your
                        ledger and balance in a single atomic transaction.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-3">
                    <Card
                        v-for="pack in props.packs"
                        :key="pack.usd"
                        class="flex flex-col justify-between border-2"
                    >
                        <CardHeader>
                            <CardTitle class="text-xl"
                                >${{ pack.usd }}.00</CardTitle
                            >
                            <CardDescription class="font-mono text-xs">
                                +{{ pack.nano.toLocaleString() }} nanodollars
                            </CardDescription>
                        </CardHeader>
                        <CardFooter>
                            <Button
                                type="button"
                                class="w-full"
                                :data-test="`buy-pack-${pack.usd}`"
                                :disabled="purchasingUsd !== null"
                                @click="buyPack(pack.usd)"
                            >
                                <PlusCircle class="mr-2 size-4" />
                                {{
                                    purchasingUsd === pack.usd
                                        ? 'Crediting...'
                                        : `Buy $${pack.usd} Pack`
                                }}
                            </Button>
                        </CardFooter>
                    </Card>
                </CardContent>
            </Card>

            <!-- Recent Ledger Transactions -->
            <Card>
                <CardHeader>
                    <CardTitle>Recent Credit Transactions</CardTitle>
                    <CardDescription>
                        Immutable ledger of credit pack purchases and settled
                        generation charges.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr
                                    class="border-b text-xs text-muted-foreground uppercase"
                                >
                                    <th class="py-3 pr-4">Type</th>
                                    <th class="px-4 py-3">Generation ULID</th>
                                    <th class="px-4 py-3 text-right">Amount</th>
                                    <th class="py-3 pl-4 text-right">
                                        Timestamp
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-if="props.transactions.length === 0">
                                    <td
                                        colspan="4"
                                        class="py-8 text-center text-muted-foreground"
                                    >
                                        No credit transactions recorded yet.
                                    </td>
                                </tr>
                                <tr
                                    v-for="tx in props.transactions"
                                    :key="tx.id"
                                >
                                    <td class="py-3 pr-4">
                                        <Badge
                                            :variant="
                                                tx.type === 'purchase'
                                                    ? 'default'
                                                    : 'secondary'
                                            "
                                        >
                                            <Sparkles
                                                v-if="
                                                    tx.type ===
                                                    'generation_charge'
                                                "
                                                class="mr-1 size-3"
                                            />
                                            {{ tx.type }}
                                        </Badge>
                                    </td>
                                    <td
                                        class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                    >
                                        {{ tx.generation_id ?? '—' }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono font-medium tabular-nums"
                                        :class="
                                            tx.amount_nano >= 0
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-foreground'
                                        "
                                    >
                                        {{ tx.amount_formatted }}
                                    </td>
                                    <td
                                        class="py-3 pl-4 text-right text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ tx.created_at_formatted }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
