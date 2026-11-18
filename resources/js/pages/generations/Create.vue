<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import {
    ImagePlus,
    Info,
    Plus,
    ShieldCheck,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { create, estimate as estimateRoute, store } from '@/routes/generations';

interface OptionItem {
    value: string;
    label: string;
}

interface CostEstimatePayload {
    expected_charge_nano: number;
    expected_charge_formatted: string;
    min_charge_nano: number;
    min_charge_formatted: string;
    max_charge_nano: number;
    max_charge_formatted: string;
    expected_provider_cost_nano: number;
    expected_provider_cost_formatted: string;
    max_provider_cost_nano: number;
    max_provider_cost_formatted: string;
    breakdown: {
        input_text: {
            label: string;
            expected_tokens: number;
            min_tokens: number;
            max_tokens: number;
            expected_charge_nano: number;
            expected_charge_formatted: string;
            min_charge_formatted: string;
            max_charge_formatted: string;
        };
        input_image: {
            label: string;
            image_count: number;
            tokens: number;
            charge_nano: number;
            charge_formatted: string;
        };
        output: {
            label: string;
            tokens: number;
            charge_nano: number;
            charge_formatted: string;
        };
        reasoning: {
            label: string;
            expected_tokens: number;
            min_tokens: number;
            max_tokens: number;
            expected_charge_nano: number;
            expected_charge_formatted: string;
            min_charge_formatted: string;
            max_charge_formatted: string;
        };
    };
}

interface ReferenceImageSlot {
    id: number;
    url: string;
    clientError?: string;
}

const props = defineProps<{
    defaults: {
        size: string;
        quality: string;
    };
    sizes: OptionItem[];
    qualities: OptionItem[];
    initialEstimate: CostEstimatePayload;
}>();

const { defaults, sizes, qualities, initialEstimate } = props;

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Generate Image',
                href: create(),
            },
        ],
    },
});

const MAX_PROMPT_LENGTH = 4000;
const MAX_REFERENCE_IMAGES = 4;

const prompt = ref('');
const size = ref(defaults.size);
const quality = ref(defaults.quality);
const referenceSlots = ref<ReferenceImageSlot[]>([]);
let nextSlotId = 1;

const isSubmitting = ref(false);
const serverErrors = ref<Record<string, string>>({});
const isEstimating = ref(false);
const currentEstimate = ref<CostEstimatePayload>(initialEstimate);

const estimateHttp = useHttp({
    prompt: '',
    size: defaults.size,
    quality: defaults.quality,
    reference_images: [] as string[],
    reference_image_count: 0,
});

const promptLength = computed(() => Array.from(prompt.value).length);

const activeReferenceUrls = computed(() =>
    referenceSlots.value
        .map((slot) => slot.url.trim())
        .filter((url) => url.length > 0),
);

function isValidHttpUrl(value: string): boolean {
    const trimmed = value.trim();
    if (trimmed === '') {
        return true;
    }

    try {
        const parsed = new URL(trimmed);

        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
        return false;
    }
}

function validateSlot(slot: ReferenceImageSlot): boolean {
    if (!isValidHttpUrl(slot.url)) {
        slot.clientError = 'Please enter a valid http:// or https:// URL.';

        return false;
    }

    slot.clientError = undefined;

    return true;
}

function addReferenceImage(): void {
    if (referenceSlots.value.length < MAX_REFERENCE_IMAGES) {
        referenceSlots.value.push({
            id: nextSlotId++,
            url: '',
        });
    }
}

function removeReferenceImage(slotId: number): void {
    referenceSlots.value = referenceSlots.value.filter(
        (slot) => slot.id !== slotId,
    );
}

function slotError(slot: ReferenceImageSlot): string | undefined {
    const idx = referenceSlots.value.findIndex((item) => item.id === slot.id);
    const serverMsg =
        idx >= 0 ? serverErrors.value[`reference_images.${idx}`] : undefined;

    return serverMsg ?? slot.clientError;
}

const refreshEstimate = useDebounceFn(() => {
    estimateHttp.prompt = prompt.value.slice(0, MAX_PROMPT_LENGTH);
    estimateHttp.size = size.value;
    estimateHttp.quality = quality.value;
    estimateHttp.reference_images = activeReferenceUrls.value;
    estimateHttp.reference_image_count = activeReferenceUrls.value.length;

    isEstimating.value = true;

    estimateHttp.post(estimateRoute.url(), {
        onSuccess: (response) => {
            currentEstimate.value = response as unknown as CostEstimatePayload;
        },
        onFinish: () => {
            isEstimating.value = false;
        },
    });
}, 250);

watch(
    () => [
        prompt.value,
        size.value,
        quality.value,
        activeReferenceUrls.value.length,
    ],
    () => {
        refreshEstimate();
    },
);

function submitGeneration(): void {
    let allValid = true;
    for (const slot of referenceSlots.value) {
        if (!validateSlot(slot)) {
            allValid = false;
        }
    }

    if (!allValid) {
        return;
    }

    isSubmitting.value = true;
    serverErrors.value = {};

    router.post(
        store.url(),
        {
            prompt: prompt.value,
            size: size.value,
            quality: quality.value,
            reference_images: activeReferenceUrls.value,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                serverErrors.value = errors;
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        },
    );
}
</script>

<template>
    <div>
        <Head title="Generate Image" />

        <div class="mx-auto flex max-w-6xl flex-col gap-6 p-4 md:p-6">
            <Heading
                title="Generate Image"
                description="Create AI images via Pixelforge with transparent, real-time token pricing."
            />

            <div class="grid gap-6 lg:grid-cols-12">
                <!-- Left Column: Generation Form -->
                <div class="lg:col-span-7">
                    <form @submit.prevent="submitGeneration" class="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Image Parameters</CardTitle>
                                <CardDescription>
                                    Describe your image and choose resolution,
                                    quality, and optional reference images.
                                </CardDescription>
                            </CardHeader>

                            <CardContent class="space-y-6">
                                <!-- Prompt -->
                                <div class="grid gap-2">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <Label for="prompt">Prompt</Label>
                                        <span
                                            data-test="prompt-counter"
                                            class="text-xs tabular-nums"
                                            :class="
                                                promptLength > MAX_PROMPT_LENGTH
                                                    ? 'font-semibold text-destructive'
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{ promptLength }} /
                                            {{ MAX_PROMPT_LENGTH }}
                                        </span>
                                    </div>

                                    <textarea
                                        id="prompt"
                                        v-model="prompt"
                                        rows="5"
                                        maxlength="4000"
                                        required
                                        placeholder="A cinematic wide shot of a glass observatory perched on a snowy mountain ridge at dusk..."
                                        class="flex min-h-[120px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs ring-offset-background transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                                    />

                                    <InputError
                                        :message="serverErrors.prompt"
                                    />
                                </div>

                                <!-- Size & Quality -->
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="size">Size</Label>
                                        <Select v-model="size">
                                            <SelectTrigger
                                                id="size"
                                                class="w-full"
                                            >
                                                <SelectValue
                                                    placeholder="Select image size"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="sizeOption in sizes"
                                                    :key="sizeOption.value"
                                                    :value="sizeOption.value"
                                                >
                                                    {{ sizeOption.label }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            :message="serverErrors.size"
                                        />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="quality">Quality</Label>
                                        <Select v-model="quality">
                                            <SelectTrigger
                                                id="quality"
                                                class="w-full"
                                            >
                                                <SelectValue
                                                    placeholder="Select image quality"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="qualityOption in qualities"
                                                    :key="qualityOption.value"
                                                    :value="qualityOption.value"
                                                >
                                                    {{ qualityOption.label }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            :message="serverErrors.quality"
                                        />
                                    </div>
                                </div>

                                <Separator />

                                <!-- Reference Images -->
                                <div class="space-y-3">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div class="space-y-0.5">
                                            <Label
                                                class="flex items-center gap-1.5"
                                            >
                                                <ImagePlus
                                                    class="size-4 text-muted-foreground"
                                                />
                                                Reference Images
                                            </Label>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                Up to
                                                {{ MAX_REFERENCE_IMAGES }}
                                                public HTTP(S) image URLs (765
                                                tokens each).
                                            </p>
                                        </div>

                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            data-test="add-reference-image"
                                            :disabled="
                                                referenceSlots.length >=
                                                MAX_REFERENCE_IMAGES
                                            "
                                            @click="addReferenceImage"
                                        >
                                            <Plus class="mr-1.5 size-4" />
                                            Add URL ({{
                                                referenceSlots.length
                                            }}/{{ MAX_REFERENCE_IMAGES }})
                                        </Button>
                                    </div>

                                    <div class="space-y-3">
                                        <div
                                            v-for="refSlot in referenceSlots"
                                            :key="refSlot.id"
                                            class="space-y-1"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <input
                                                    v-model="refSlot.url"
                                                    type="url"
                                                    placeholder="https://example.com/reference.png"
                                                    aria-label="Reference image URL"
                                                    class="flex h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                                                    @blur="
                                                        validateSlot(refSlot)
                                                    "
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Remove reference image"
                                                    @click="
                                                        removeReferenceImage(
                                                            refSlot.id,
                                                        )
                                                    "
                                                >
                                                    <Trash2
                                                        class="size-4 text-muted-foreground hover:text-destructive"
                                                    />
                                                </Button>
                                            </div>
                                            <InputError
                                                :message="slotError(refSlot)"
                                            />
                                        </div>
                                    </div>

                                    <InputError
                                        :message="serverErrors.reference_images"
                                    />
                                </div>
                            </CardContent>

                            <CardFooter
                                class="flex items-center justify-between border-t pt-6"
                            >
                                <p class="text-xs text-muted-foreground">
                                    Submitted asynchronously to Pixelforge.
                                </p>
                                <Button
                                    type="submit"
                                    data-test="submit-generation"
                                    :disabled="
                                        isSubmitting ||
                                        promptLength === 0 ||
                                        promptLength > MAX_PROMPT_LENGTH
                                    "
                                >
                                    <Sparkles class="mr-2 size-4" />
                                    {{
                                        isSubmitting
                                            ? 'Submitting...'
                                            : 'Generate Image'
                                    }}
                                </Button>
                            </CardFooter>
                        </Card>
                    </form>
                </div>

                <!-- Right Column: Live Price Estimate Panel -->
                <div class="lg:col-span-5">
                    <Card class="sticky top-6">
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <CardTitle>Live Price Estimate</CardTitle>
                                <Badge variant="secondary" class="tabular-nums">
                                    {{
                                        isEstimating
                                            ? 'Updating...'
                                            : 'Real-time'
                                    }}
                                </Badge>
                            </div>
                            <CardDescription
                                data-test="estimate-headline"
                                class="pt-1 text-sm text-foreground"
                            >
                                Estimated
                                <span class="font-semibold tabular-nums">{{
                                    currentEstimate.expected_charge_formatted
                                }}</span
                                >, final price depends on how much the model
                                'thinks'; at most
                                <span class="font-semibold tabular-nums">{{
                                    currentEstimate.max_charge_formatted
                                }}</span
                                >.
                            </CardDescription>
                        </CardHeader>

                        <CardContent class="space-y-4">
                            <div class="rounded-lg border bg-muted/40 p-4">
                                <div
                                    class="flex items-baseline justify-between"
                                >
                                    <span
                                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                                    >
                                        Expected Charge
                                    </span>
                                    <span
                                        data-test="expected-charge"
                                        class="text-2xl font-bold tracking-tight tabular-nums"
                                    >
                                        {{
                                            currentEstimate.expected_charge_formatted
                                        }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1 flex items-baseline justify-between text-xs text-muted-foreground"
                                >
                                    <span>Range (min – max)</span>
                                    <span class="tabular-nums">
                                        {{
                                            currentEstimate.min_charge_formatted
                                        }}
                                        –
                                        {{
                                            currentEstimate.max_charge_formatted
                                        }}
                                    </span>
                                </div>
                            </div>

                            <Separator />

                            <!-- Plain-language Breakdown -->
                            <div class="space-y-3 text-sm">
                                <h3
                                    class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Cost Breakdown
                                </h3>

                                <!-- Text -->
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div>
                                        <p class="font-medium">Prompt text</p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            ~{{
                                                currentEstimate.breakdown
                                                    .input_text.expected_tokens
                                            }}
                                            tokens ({{
                                                currentEstimate.breakdown
                                                    .input_text.min_tokens
                                            }}–{{
                                                currentEstimate.breakdown
                                                    .input_text.max_tokens
                                            }}, ±15%)
                                        </p>
                                    </div>
                                    <span
                                        class="font-mono text-xs tabular-nums"
                                    >
                                        {{
                                            currentEstimate.breakdown.input_text
                                                .expected_charge_formatted
                                        }}
                                    </span>
                                </div>

                                <!-- Reference Images -->
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div>
                                        <p class="font-medium">
                                            Reference images
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                currentEstimate.breakdown
                                                    .input_image.image_count
                                            }}
                                            {{
                                                currentEstimate.breakdown
                                                    .input_image.image_count ===
                                                1
                                                    ? 'image'
                                                    : 'images'
                                            }}
                                            ({{
                                                currentEstimate.breakdown
                                                    .input_image.tokens
                                            }}
                                            exact tokens)
                                        </p>
                                    </div>
                                    <span
                                        class="font-mono text-xs tabular-nums"
                                    >
                                        {{
                                            currentEstimate.breakdown
                                                .input_image.charge_formatted
                                        }}
                                    </span>
                                </div>

                                <!-- Output -->
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div>
                                        <p class="font-medium">
                                            Rendered image output
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ quality }} · {{ size }} ({{
                                                currentEstimate.breakdown.output
                                                    .tokens
                                            }}
                                            exact tokens)
                                        </p>
                                    </div>
                                    <span
                                        class="font-mono text-xs tabular-nums"
                                    >
                                        {{
                                            currentEstimate.breakdown.output
                                                .charge_formatted
                                        }}
                                    </span>
                                </div>

                                <!-- Reasoning -->
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div>
                                        <p class="font-medium">
                                            Model thinking (reasoning)
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            ~{{
                                                currentEstimate.breakdown
                                                    .reasoning.expected_tokens
                                            }}
                                            tokens (range
                                            {{
                                                currentEstimate.breakdown
                                                    .reasoning.min_tokens
                                            }}–{{
                                                currentEstimate.breakdown
                                                    .reasoning.max_tokens
                                            }})
                                        </p>
                                    </div>
                                    <div
                                        class="text-right font-mono text-xs tabular-nums"
                                    >
                                        <div>
                                            {{
                                                currentEstimate.breakdown
                                                    .reasoning
                                                    .expected_charge_formatted
                                            }}
                                        </div>
                                        <div
                                            class="text-[11px] text-muted-foreground"
                                        >
                                            up to
                                            {{
                                                currentEstimate.breakdown
                                                    .reasoning
                                                    .max_charge_formatted
                                            }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            <!-- Guarantees / Billing Notice -->
                            <div class="space-y-2">
                                <Alert>
                                    <ShieldCheck class="size-4" />
                                    <AlertTitle
                                        >Failed generations are free</AlertTitle
                                    >
                                    <AlertDescription>
                                        If a generation fails or is blocked by
                                        safety filters, you are charged $0.0000.
                                    </AlertDescription>
                                </Alert>

                                <Alert>
                                    <Info class="size-4" />
                                    <AlertTitle
                                        >When is the final price
                                        recorded?</AlertTitle
                                    >
                                    <AlertDescription>
                                        The final price is recorded only when
                                        the image finishes rendering, using the
                                        exact token counts and the rate snapshot
                                        locked at creation.
                                    </AlertDescription>
                                </Alert>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </div>
</template>
