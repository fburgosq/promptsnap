<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { AlertCircle, Clock, ImageOff, Loader2, Sparkles } from '@lucide/vue';
import { computed, reactive, watchEffect } from 'vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import {
    create as createGeneration,
    index as generationsIndex,
} from '@/routes/generations';

interface GenerationItem {
    id: string;
    provider_id: string | null;
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    status_label: string;
    status_description: string;
    is_final: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_charge_nano: number;
    estimated_charge_formatted: string;
    estimated_max_charge_nano: number;
    estimated_max_charge_formatted: string;
    provider_cost_nano: number | null;
    provider_cost_formatted: string | null;
    charged_nano: number | null;
    charged_formatted: string | null;
    actual_charge_display: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    is_image_expired: boolean;
    error_code: string | null;
    error_message: string | null;
    submitted_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    created_at_formatted: string | null;
    completed_at_formatted: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedGenerations {
    data: GenerationItem[];
    links?: PaginationLink[];
    meta?: {
        current_page: number;
        last_page: number;
        total: number;
        links: PaginationLink[];
    };
}

const props = defineProps<{
    generations: PaginatedGenerations;
    statusMessage?: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Generation History',
                href: generationsIndex(),
            },
        ],
    },
});

const brokenImageIds = reactive<Record<string, boolean>>({});

const hasInFlightGenerations = computed(() =>
    props.generations.data.some((item) => !item.is_final),
);

const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    {
        only: ['generations'],
    },
    {
        autoStart: false,
    },
);

watchEffect(() => {
    if (hasInFlightGenerations.value) {
        startPolling();
    } else {
        stopPolling();
    }
});

function markImageBroken(id: string): void {
    brokenImageIds[id] = true;
}

function isImageUnavailable(item: GenerationItem): boolean {
    return (
        item.is_image_expired ||
        !item.image_url ||
        Boolean(brokenImageIds[item.id])
    );
}

function badgeVariant(
    status: GenerationItem['status'],
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'succeeded':
            return 'default';
        case 'processing':
            return 'secondary';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
}

const paginationLinks = computed<PaginationLink[]>(
    () => props.generations.meta?.links ?? props.generations.links ?? [],
);
</script>

<template>
    <div>
        <Head title="Generation History" />

        <div class="mx-auto flex max-w-6xl flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Heading
                        title="Generation History"
                        description="Track your queued, processing, and completed image generations."
                    />
                    <Badge
                        v-if="hasInFlightGenerations"
                        variant="secondary"
                        data-test="live-polling-indicator"
                        class="flex items-center gap-1.5"
                    >
                        <Loader2 class="size-3 animate-spin" />
                        Live updating
                    </Badge>
                </div>

                <Button as-child data-test="generate-another-button">
                    <Link :href="createGeneration()">
                        <Sparkles class="mr-2 size-4" />
                        Generate another
                    </Link>
                </Button>
            </div>

            <Alert v-if="props.statusMessage" data-test="flash-status">
                <Sparkles class="size-4" />
                <AlertTitle>Generation Queued</AlertTitle>
                <AlertDescription>{{ props.statusMessage }}</AlertDescription>
            </Alert>

            <!-- Empty State -->
            <Card
                v-if="props.generations.data.length === 0"
                data-test="empty-history-state"
                class="border-dashed py-12 text-center"
            >
                <CardContent class="flex flex-col items-center gap-4">
                    <div
                        class="flex size-12 items-center justify-center rounded-full bg-muted"
                    >
                        <Sparkles class="size-6 text-muted-foreground" />
                    </div>
                    <div class="space-y-1">
                        <h3 class="text-base font-semibold">
                            No generations yet
                        </h3>
                        <p class="max-w-sm text-sm text-muted-foreground">
                            Submit your first prompt to generate high-resolution
                            AI images with real-time token cost tracking.
                        </p>
                    </div>
                    <Button as-child>
                        <Link :href="createGeneration()">
                            <Sparkles class="mr-2 size-4" />
                            Generate another
                        </Link>
                    </Button>
                </CardContent>
            </Card>

            <!-- Generations Grid -->
            <div v-else class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <Card
                    v-for="item in props.generations.data"
                    :key="item.id"
                    :data-test="`generation-card-${item.id}`"
                    class="flex flex-col overflow-hidden py-0"
                >
                    <!-- Media Preview Area -->
                    <div
                        class="relative flex aspect-square w-full items-center justify-center overflow-hidden border-b bg-muted/40"
                    >
                        <!-- Succeeded & Active Image -->
                        <img
                            v-if="
                                item.status === 'succeeded' &&
                                !isImageUnavailable(item)
                            "
                            :src="item.image_url ?? ''"
                            :alt="item.prompt"
                            class="size-full object-cover"
                            loading="lazy"
                            @error="markImageBroken(item.id)"
                        />

                        <!-- Succeeded but Expired or Broken Image -->
                        <div
                            v-else-if="item.status === 'succeeded'"
                            data-test="image-expired-placeholder"
                            class="flex flex-col items-center gap-2 p-6 text-center text-muted-foreground"
                        >
                            <ImageOff class="size-8 opacity-60" />
                            <p class="text-sm font-medium text-foreground">
                                Image expired
                            </p>
                            <p class="text-xs">
                                Pixelforge image URLs expire 24 hours after
                                completion.
                            </p>
                        </div>

                        <!-- Processing State -->
                        <div
                            v-else-if="item.status === 'processing'"
                            data-test="status-processing-copy"
                            class="flex flex-col items-center gap-3 p-6 text-center"
                        >
                            <Loader2 class="size-8 animate-spin text-primary" />
                            <div class="space-y-1">
                                <p class="text-sm font-medium">
                                    Generating image...
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ item.status_description }}
                                </p>
                            </div>
                        </div>

                        <!-- Queued State (Distinguishes local queue vs Pixelforge queue) -->
                        <div
                            v-else-if="item.status === 'queued'"
                            data-test="status-queued-copy"
                            class="flex flex-col items-center gap-3 p-6 text-center"
                        >
                            <Clock
                                class="size-8 animate-pulse text-muted-foreground"
                            />
                            <div class="space-y-1">
                                <p class="text-sm font-medium">
                                    {{
                                        item.provider_id
                                            ? 'In Pixelforge Queue'
                                            : 'In Our Submission Queue'
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ item.status_description }}
                                </p>
                            </div>
                        </div>

                        <!-- Failed State -->
                        <div
                            v-else
                            data-test="status-failed-copy"
                            class="flex flex-col items-center gap-2 p-6 text-center"
                        >
                            <AlertCircle class="size-8 text-destructive" />
                            <p class="text-sm font-semibold text-destructive">
                                Generation Failed
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    item.error_message ??
                                    'An error occurred while generating this image.'
                                }}
                            </p>
                        </div>
                    </div>

                    <CardHeader class="space-y-2 pt-4 pb-2">
                        <div class="flex items-center justify-between gap-2">
                            <Badge :variant="badgeVariant(item.status)">
                                {{ item.status_label }}
                            </Badge>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ item.size }} · {{ item.quality }}
                            </span>
                        </div>

                        <CardTitle
                            class="line-clamp-3 text-sm leading-snug font-normal"
                            :title="item.prompt"
                        >
                            {{ item.prompt }}
                        </CardTitle>
                    </CardHeader>

                    <CardContent class="mt-auto space-y-3 pb-4 text-xs">
                        <Separator />

                        <!-- Cost Comparison -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <span class="block text-muted-foreground">
                                    Estimated
                                </span>
                                <span
                                    class="font-mono font-medium tabular-nums"
                                >
                                    {{ item.estimated_charge_formatted }}
                                </span>
                                <span
                                    class="block font-mono text-[11px] text-muted-foreground tabular-nums"
                                >
                                    max
                                    {{ item.estimated_max_charge_formatted }}
                                </span>
                            </div>

                            <div class="text-right">
                                <span class="block text-muted-foreground">
                                    Actual Charge
                                </span>
                                <span
                                    v-if="item.actual_charge_display !== null"
                                    data-test="actual-charge"
                                    class="font-mono font-semibold tabular-nums"
                                    :class="
                                        item.status === 'failed'
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-foreground'
                                    "
                                >
                                    {{ item.actual_charge_display }}
                                </span>
                                <span
                                    v-else
                                    class="text-muted-foreground italic"
                                >
                                    Pending completion
                                </span>
                            </div>
                        </div>

                        <!-- Error Banner if Failed -->
                        <div
                            v-if="item.status === 'failed'"
                            class="rounded-md border border-destructive/30 bg-destructive/10 p-2 text-destructive"
                        >
                            {{
                                item.error_message ??
                                'Generation failed. You were not charged.'
                            }}
                        </div>
                    </CardContent>

                    <CardFooter
                        class="flex flex-col items-start gap-0.5 border-t bg-muted/20 px-6 py-3 text-[11px] text-muted-foreground"
                    >
                        <div class="flex w-full justify-between">
                            <span>Created</span>
                            <span class="tabular-nums">{{
                                item.created_at_formatted
                            }}</span>
                        </div>
                        <div
                            v-if="item.completed_at_formatted"
                            class="flex w-full justify-between"
                        >
                            <span>Finished</span>
                            <span class="tabular-nums">{{
                                item.completed_at_formatted
                            }}</span>
                        </div>
                    </CardFooter>
                </Card>
            </div>

            <!-- Pagination -->
            <div
                v-if="paginationLinks.length > 3"
                class="flex flex-wrap items-center justify-center gap-1.5 pt-2"
            >
                <template v-for="(link, idx) in paginationLinks" :key="idx">
                    <Button
                        v-if="link.url"
                        as-child
                        size="sm"
                        :variant="link.active ? 'default' : 'outline'"
                    >
                        <Link :href="link.url" preserve-scroll>
                            <span v-html="link.label" />
                        </Link>
                    </Button>
                    <Button v-else size="sm" variant="ghost" disabled>
                        <span v-html="link.label" />
                    </Button>
                </template>
            </div>
        </div>
    </div>
</template>
