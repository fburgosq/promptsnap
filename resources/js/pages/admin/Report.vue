<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Calendar, Filter, Users } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { report as adminReportRoute } from '@/routes/admin';

interface AggregateMetrics {
    generations_count: number;
    charged_nano: number;
    charged_formatted: string;
    provider_cost_nano: number;
    provider_cost_formatted: string;
    profit_nano: number;
    profit_formatted: string;
}

interface DayRow extends AggregateMetrics {
    date: string;
}

interface UserRow extends AggregateMetrics {
    user_id: number;
    user_name: string;
    user_email: string;
}

interface DayUserRow extends AggregateMetrics {
    date: string;
    user_id: number;
    user_name: string;
    user_email: string;
}

const props = defineProps<{
    filters: {
        start_date: string;
        end_date: string;
        view: 'by_day' | 'by_user';
    };
    totals: AggregateMetrics;
    by_day: DayRow[];
    by_user: UserRow[];
    by_day_and_user: DayUserRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Admin Reconciliation Report',
                href: adminReportRoute(),
            },
        ],
    },
});

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const activeView = ref<'by_day' | 'by_user'>(props.filters.view);
const filterErrors = ref<Record<string, string>>({});
const isFiltering = ref(false);

function applyFilters(viewOverride?: 'by_day' | 'by_user'): void {
    if (viewOverride) {
        activeView.value = viewOverride;
    }

    isFiltering.value = true;
    filterErrors.value = {};

    router.get(
        adminReportRoute.url(),
        {
            start_date: startDate.value,
            end_date: endDate.value,
            view: activeView.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onError: (errors) => {
                filterErrors.value = errors;
            },
            onFinish: () => {
                isFiltering.value = false;
            },
        },
    );
}
</script>

<template>
    <div>
        <Head title="Admin Reconciliation Report" />

        <div class="mx-auto flex max-w-6xl flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <Heading
                    title="Reconciliation Report"
                    description="Succeeded generations aggregated by UTC completion date (matching Pixelforge /v1/usage billing days) and by user."
                />
                <Badge variant="outline" class="font-mono text-xs">
                    UTC Billing Days · Max 92 Days
                </Badge>
            </div>

            <!-- Date Range Filter & View Switcher -->
            <Card>
                <CardContent class="pt-6">
                    <form
                        @submit.prevent="applyFilters()"
                        class="flex flex-wrap items-end justify-between gap-4"
                    >
                        <div class="flex flex-wrap items-end gap-4">
                            <div class="grid gap-1.5">
                                <Label for="start_date">Start Date (UTC)</Label>
                                <input
                                    id="start_date"
                                    v-model="startDate"
                                    type="date"
                                    required
                                    class="flex h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                <InputError
                                    :message="filterErrors.start_date"
                                />
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="end_date">End Date (UTC)</Label>
                                <input
                                    id="end_date"
                                    v-model="endDate"
                                    type="date"
                                    required
                                    class="flex h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                <InputError :message="filterErrors.end_date" />
                            </div>

                            <Button
                                type="submit"
                                data-test="apply-report-filters"
                                :disabled="isFiltering"
                            >
                                <Filter class="mr-2 size-4" />
                                {{
                                    isFiltering ? 'Filtering...' : 'Apply Range'
                                }}
                            </Button>
                        </div>

                        <!-- View Toggle -->
                        <div
                            class="inline-flex rounded-lg border bg-muted/40 p-1"
                        >
                            <Button
                                type="button"
                                size="sm"
                                data-test="view-by-day-button"
                                :variant="
                                    activeView === 'by_day'
                                        ? 'default'
                                        : 'ghost'
                                "
                                @click="applyFilters('by_day')"
                            >
                                <Calendar class="mr-1.5 size-4" />
                                By UTC Day
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                data-test="view-by-user-button"
                                :variant="
                                    activeView === 'by_user'
                                        ? 'default'
                                        : 'ghost'
                                "
                                @click="applyFilters('by_user')"
                            >
                                <Users class="mr-1.5 size-4" />
                                By User
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Summary KPI Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Succeeded Generations</CardDescription>
                        <CardTitle
                            data-test="summary-generations"
                            class="text-2xl tabular-nums"
                        >
                            {{ props.totals.generations_count }}
                        </CardTitle>
                    </CardHeader>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription
                            >Credit Spend (Charged)</CardDescription
                        >
                        <CardTitle
                            data-test="summary-charged"
                            class="font-mono text-2xl tabular-nums"
                        >
                            {{ props.totals.charged_formatted }}
                        </CardTitle>
                    </CardHeader>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Provider Cost</CardDescription>
                        <CardTitle
                            data-test="summary-provider-cost"
                            class="font-mono text-2xl tabular-nums"
                        >
                            {{ props.totals.provider_cost_formatted }}
                        </CardTitle>
                    </CardHeader>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Net Profit</CardDescription>
                        <CardTitle
                            data-test="summary-profit"
                            class="font-mono text-2xl text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            {{ props.totals.profit_formatted }}
                        </CardTitle>
                    </CardHeader>
                </Card>
            </div>

            <!-- By-Day View -->
            <Card v-if="activeView === 'by_day'" data-test="table-by-day">
                <CardHeader>
                    <CardTitle>Daily Reconciliation (UTC)</CardTitle>
                    <CardDescription>
                        Grouped by the UTC date on which each succeeded
                        generation completed.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr
                                    class="border-b text-xs text-muted-foreground uppercase"
                                >
                                    <th class="py-3 pr-4">UTC Date</th>
                                    <th class="px-4 py-3 text-right">
                                        Generations
                                    </th>
                                    <th class="px-4 py-3 text-right">
                                        Credit Spend
                                    </th>
                                    <th class="px-4 py-3 text-right">
                                        Provider Cost
                                    </th>
                                    <th class="py-3 pl-4 text-right">Profit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-if="props.by_day.length === 0">
                                    <td
                                        colspan="5"
                                        class="py-8 text-center text-muted-foreground"
                                    >
                                        No succeeded generations in this date
                                        range.
                                    </td>
                                </tr>
                                <tr
                                    v-for="row in props.by_day"
                                    :key="row.date"
                                    :data-test="`day-row-${row.date}`"
                                >
                                    <td class="py-3 pr-4 font-mono font-medium">
                                        {{ row.date }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ row.generations_count }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono tabular-nums"
                                    >
                                        {{ row.charged_formatted }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono tabular-nums"
                                    >
                                        {{ row.provider_cost_formatted }}
                                    </td>
                                    <td
                                        class="py-3 pl-4 text-right font-mono font-medium text-emerald-600 tabular-nums dark:text-emerald-400"
                                    >
                                        {{ row.profit_formatted }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr
                                    data-test="totals-row"
                                    class="border-t-2 font-semibold"
                                >
                                    <td class="pt-3 pr-4">Total</td>
                                    <td
                                        class="px-4 pt-3 text-right tabular-nums"
                                    >
                                        {{ props.totals.generations_count }}
                                    </td>
                                    <td
                                        class="px-4 pt-3 text-right font-mono tabular-nums"
                                    >
                                        {{ props.totals.charged_formatted }}
                                    </td>
                                    <td
                                        class="px-4 pt-3 text-right font-mono tabular-nums"
                                    >
                                        {{
                                            props.totals.provider_cost_formatted
                                        }}
                                    </td>
                                    <td
                                        class="pt-3 pl-4 text-right font-mono text-emerald-600 tabular-nums dark:text-emerald-400"
                                    >
                                        {{ props.totals.profit_formatted }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- By-User View -->
            <Card v-else data-test="table-by-user">
                <CardHeader>
                    <CardTitle>User Spend & Margin Breakdown</CardTitle>
                    <CardDescription>
                        Succeeded generations aggregated per user across
                        {{ props.filters.start_date }} to
                        {{ props.filters.end_date }}.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr
                                    class="border-b text-xs text-muted-foreground uppercase"
                                >
                                    <th class="py-3 pr-4">User</th>
                                    <th class="px-4 py-3 text-right">
                                        Generations
                                    </th>
                                    <th class="px-4 py-3 text-right">
                                        Credit Spend
                                    </th>
                                    <th class="px-4 py-3 text-right">
                                        Provider Cost
                                    </th>
                                    <th class="py-3 pl-4 text-right">Profit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-if="props.by_user.length === 0">
                                    <td
                                        colspan="5"
                                        class="py-8 text-center text-muted-foreground"
                                    >
                                        No succeeded generations in this date
                                        range.
                                    </td>
                                </tr>
                                <tr
                                    v-for="row in props.by_user"
                                    :key="row.user_id"
                                    :data-test="`user-row-${row.user_id}`"
                                >
                                    <td class="py-3 pr-4">
                                        <div class="font-medium">
                                            {{ row.user_name }}
                                        </div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ row.user_email }}
                                        </div>
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ row.generations_count }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono tabular-nums"
                                    >
                                        {{ row.charged_formatted }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono tabular-nums"
                                    >
                                        {{ row.provider_cost_formatted }}
                                    </td>
                                    <td
                                        class="py-3 pl-4 text-right font-mono font-medium text-emerald-600 tabular-nums dark:text-emerald-400"
                                    >
                                        {{ row.profit_formatted }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr
                                    data-test="totals-row"
                                    class="border-t-2 font-semibold"
                                >
                                    <td class="pt-3 pr-4">Total</td>
                                    <td
                                        class="px-4 pt-3 text-right tabular-nums"
                                    >
                                        {{ props.totals.generations_count }}
                                    </td>
                                    <td
                                        class="px-4 pt-3 text-right font-mono tabular-nums"
                                    >
                                        {{ props.totals.charged_formatted }}
                                    </td>
                                    <td
                                        class="px-4 pt-3 text-right font-mono tabular-nums"
                                    >
                                        {{
                                            props.totals.provider_cost_formatted
                                        }}
                                    </td>
                                    <td
                                        class="pt-3 pl-4 text-right font-mono text-emerald-600 tabular-nums dark:text-emerald-400"
                                    >
                                        {{ props.totals.profit_formatted }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Daily x User Matrix -->
            <Card>
                <CardHeader>
                    <CardTitle>Daily User Ledger (UTC Day × User)</CardTitle>
                    <CardDescription>
                        Granular breakdown by UTC completion date and user.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr
                                    class="border-b text-xs text-muted-foreground uppercase"
                                >
                                    <th class="py-3 pr-4">UTC Date</th>
                                    <th class="px-4 py-3">User</th>
                                    <th class="px-4 py-3 text-right">
                                        Generations
                                    </th>
                                    <th class="px-4 py-3 text-right">
                                        Credit Spend
                                    </th>
                                    <th class="px-4 py-3 text-right">
                                        Provider Cost
                                    </th>
                                    <th class="py-3 pl-4 text-right">Profit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-if="props.by_day_and_user.length === 0">
                                    <td
                                        colspan="6"
                                        class="py-8 text-center text-muted-foreground"
                                    >
                                        No succeeded generations in this date
                                        range.
                                    </td>
                                </tr>
                                <tr
                                    v-for="row in props.by_day_and_user"
                                    :key="`${row.date}-${row.user_id}`"
                                >
                                    <td class="py-3 pr-4 font-mono">
                                        {{ row.date }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium">{{
                                            row.user_name
                                        }}</span>
                                        <span
                                            class="ml-1.5 text-xs text-muted-foreground"
                                        >
                                            ({{ row.user_email }})
                                        </span>
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ row.generations_count }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono tabular-nums"
                                    >
                                        {{ row.charged_formatted }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono tabular-nums"
                                    >
                                        {{ row.provider_cost_formatted }}
                                    </td>
                                    <td
                                        class="py-3 pl-4 text-right font-mono text-emerald-600 tabular-nums dark:text-emerald-400"
                                    >
                                        {{ row.profit_formatted }}
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
