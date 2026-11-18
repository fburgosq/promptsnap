<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationSettler;
use App\Services\Pixelforge\PixelforgeSignatureVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PixelforgeSignatureVerifier $verifier,
        GenerationSettler $settler,
    ): Response {
        $rawBody = $request->getContent();
        $signature = $request->header('Pixelforge-Signature');

        if (! $verifier->verify(is_string($signature) ? $signature : null, $rawBody)) {
            Log::warning('Rejected Pixelforge webhook with invalid or stale signature.', [
                'ip' => $request->ip(),
                'has_signature' => $signature !== null,
            ]);

            return response('Invalid webhook signature.', 400);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            Log::warning('Rejected Pixelforge webhook with malformed JSON payload.');

            return response('Invalid JSON payload.', 400);
        }

        $eventId = is_string($decoded['id'] ?? null) && $decoded['id'] !== ''
            ? $decoded['id']
            : null;
        $type = is_string($decoded['type'] ?? null) && $decoded['type'] !== ''
            ? $decoded['type']
            : null;

        if ($eventId === null || $type === null) {
            Log::warning('Rejected Pixelforge webhook missing id or type.');

            return response('Missing webhook event id or type.', 400);
        }

        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        /** @var array<string, mixed> $providerObject */
        $providerObject = is_array($data['object'] ?? null) ? $data['object'] : [];

        $providerGenerationId = is_string($providerObject['id'] ?? null) && $providerObject['id'] !== ''
            ? $providerObject['id']
            : null;

        try {
            DB::transaction(function () use (
                $eventId,
                $type,
                $providerGenerationId,
                $decoded,
                $providerObject,
                $settler,
            ): void {
                WebhookEvent::query()->create([
                    'event_id' => $eventId,
                    'type' => $type,
                    'provider_generation_id' => $providerGenerationId,
                    'payload' => $decoded,
                    'received_at' => Carbon::now('UTC'),
                ]);

                if ($type === 'ping' || ! str_starts_with($type, 'generation.')) {
                    return;
                }

                $generation = $this->resolveGeneration($providerObject, $providerGenerationId);

                if ($generation === null) {
                    Log::warning('Pixelforge webhook received for unknown generation.', [
                        'event_id' => $eventId,
                        'type' => $type,
                        'provider_generation_id' => $providerGenerationId,
                        'metadata_generation_id' => is_array($providerObject['metadata'] ?? null)
                            ? ($providerObject['metadata']['generation_id'] ?? null)
                            : null,
                    ]);

                    return;
                }

                $settler->applyProviderSnapshot($generation, $providerObject);
            });
        } catch (UniqueConstraintViolationException) {
            return response()->noContent();
        }

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $providerObject
     */
    private function resolveGeneration(array $providerObject, ?string $providerGenerationId): ?Generation
    {
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($providerObject['metadata'] ?? null) ? $providerObject['metadata'] : [];
        $localId = is_string($metadata['generation_id'] ?? null) && $metadata['generation_id'] !== ''
            ? $metadata['generation_id']
            : null;

        if ($localId !== null) {
            $byMetadata = Generation::query()->find($localId);
            if ($byMetadata !== null) {
                return $byMetadata;
            }
        }

        if ($providerGenerationId !== null) {
            return Generation::query()->where('provider_id', $providerGenerationId)->first();
        }

        return null;
    }
}
