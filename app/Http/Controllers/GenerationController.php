<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Requests\Generations\EstimateGenerationRequest;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Http\Resources\CostEstimateResource;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\Pricing\CostCalculator;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Generation::class);

        $generations = Generation::query()
            ->forUser($request->user())
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(12);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'statusMessage' => $request->session()->get('status'),
        ]);
    }

    public function create(PricingService $pricingService, CostCalculator $calculator): Response
    {
        $pricing = $pricingService->current();
        $defaultSize = ImageSize::Square;
        $defaultQuality = ImageQuality::Medium;

        $initialEstimate = $calculator->estimate(
            prompt: '',
            size: $defaultSize,
            quality: $defaultQuality,
            referenceImageCount: 0,
            pricing: $pricing,
        );

        return Inertia::render('generations/Create', [
            'defaults' => [
                'size' => $defaultSize->value,
                'quality' => $defaultQuality->value,
            ],
            'sizes' => [
                ['value' => ImageSize::Square->value, 'label' => '1024 × 1024 (Square)'],
                ['value' => ImageSize::Landscape->value, 'label' => '1536 × 1024 (Landscape)'],
                ['value' => ImageSize::Portrait->value, 'label' => '1024 × 1536 (Portrait)'],
            ],
            'qualities' => [
                ['value' => ImageQuality::Low->value, 'label' => 'Low'],
                ['value' => ImageQuality::Medium->value, 'label' => 'Medium (Default)'],
                ['value' => ImageQuality::High->value, 'label' => 'High'],
            ],
            'initialEstimate' => (new CostEstimateResource($initialEstimate, $calculator))->resolve(),
        ]);
    }

    public function estimate(
        EstimateGenerationRequest $request,
        PricingService $pricingService,
        CostCalculator $calculator,
    ): JsonResponse {
        $validated = $request->validated();
        $pricing = $pricingService->current();

        $estimate = $calculator->estimate(
            prompt: (string) $validated['prompt'],
            size: ImageSize::from((string) $validated['size']),
            quality: ImageQuality::from((string) $validated['quality']),
            referenceImageCount: (int) $validated['reference_image_count'],
            pricing: $pricing,
        );

        return response()->json(
            (new CostEstimateResource($estimate, $calculator))->resolve($request)
        );
    }

    public function store(
        StoreGenerationRequest $request,
        PricingService $pricingService,
        CostCalculator $calculator,
    ): RedirectResponse {
        $validated = $request->validated();
        $pricing = $pricingService->current();

        $size = ImageSize::from((string) $validated['size']);
        $quality = ImageQuality::from((string) $validated['quality']);
        /** @var list<string> $referenceImages */
        $referenceImages = array_values($validated['reference_images']);

        $estimate = $calculator->estimate(
            prompt: (string) $validated['prompt'],
            size: $size,
            quality: $quality,
            referenceImageCount: count($referenceImages),
            pricing: $pricing,
        );

        $generation = Generation::query()->create([
            'user_id' => $request->user()->id,
            'status' => GenerationStatus::Queued,
            'prompt' => (string) $validated['prompt'],
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'estimated_charge_nano' => $estimate->expectedChargedNano,
            'estimated_max_charge_nano' => $estimate->maxChargedNano,
        ]);

        SubmitGenerationJob::dispatch($generation);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Generation queued! Your image is being processed.',
        ]);

        return to_route('generations.index')
            ->with('status', 'Generation queued! Your image is being processed.');
    }
}
