<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    create as createGeneration,
    index as generationsIndex,
} from '@/routes/generations';

interface GenerationItem {
    id: string;
    provider_id: string | null;
    status: string;
    is_final: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_charge_formatted: string;
    estimated_max_charge_formatted: string;
    charged_formatted: string | null;
    image_url: string | null;
    error_message: string | null;
    created_at: string | null;
}

defineProps<{
    generations: {
        data: GenerationItem[];
    };
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
</script>

<template>
    <div>
        <Head title="Generation History" />

        <div class="mx-auto flex max-w-6xl flex-col gap-6 p-4 md:p-6">
            <div class="flex items-center justify-between">
                <Heading
                    title="Generation History"
                    description="Track your queued, processing, and completed image generations."
                />

                <Button as-child>
                    <Link :href="createGeneration()">
                        <Sparkles class="mr-2 size-4" />
                        New Generation
                    </Link>
                </Button>
            </div>

            <Alert v-if="statusMessage" data-test="flash-status">
                <AlertTitle>Queued</AlertTitle>
                <AlertDescription>{{ statusMessage }}</AlertDescription>
            </Alert>

            <div
                v-if="generations.data.length === 0"
                class="rounded-xl border border-dashed p-12 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    No image generations yet.
                </p>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card v-for="item in generations.data" :key="item.id">
                    <CardHeader class="pb-2">
                        <div class="flex items-center justify-between">
                            <Badge variant="outline">{{ item.status }}</Badge>
                            <span class="font-mono text-xs tabular-nums">
                                {{
                                    item.charged_formatted ??
                                    `Est. ${item.estimated_charge_formatted}`
                                }}
                            </span>
                        </div>
                        <CardTitle class="line-clamp-2 text-sm font-normal">
                            {{ item.prompt }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="text-xs text-muted-foreground">
                        {{ item.size }} · {{ item.quality }}
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
