<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Nano;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = Carbon::now('UTC');
        $isImageExpired = $this->status === GenerationStatus::Succeeded && (
            $this->image_url === null
            || $this->image_url === ''
            || ($this->image_url_expires_at !== null && $this->image_url_expires_at->lessThanOrEqualTo($now))
        );

        $actualChargeDisplay = match (true) {
            $this->status === GenerationStatus::Failed => 'Free',
            $this->charged_nano !== null => Nano::format($this->charged_nano),
            default => null,
        };

        $statusDescription = match ($this->status) {
            GenerationStatus::Queued => $this->provider_id === null
                ? 'Waiting in our queue to submit to Pixelforge...'
                : 'Waiting in the Pixelforge render queue...',
            GenerationStatus::Processing => 'Generating your image now...',
            GenerationStatus::Succeeded => 'Image completed.',
            GenerationStatus::Failed => $this->error_message ?? 'Generation failed. You were not charged.',
        };

        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'status' => $this->status->value,
            'status_label' => ucfirst($this->status->value),
            'status_description' => $statusDescription,
            'is_final' => $this->status->isFinal(),
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'estimated_charge_nano' => $this->estimated_charge_nano,
            'estimated_charge_formatted' => Nano::format($this->estimated_charge_nano),
            'estimated_max_charge_nano' => $this->estimated_max_charge_nano,
            'estimated_max_charge_formatted' => Nano::format($this->estimated_max_charge_nano),
            'provider_cost_nano' => $this->provider_cost_nano,
            'provider_cost_formatted' => $this->provider_cost_nano !== null ? Nano::format($this->provider_cost_nano) : null,
            'charged_nano' => $this->charged_nano,
            'charged_formatted' => $this->charged_nano !== null ? Nano::format($this->charged_nano) : null,
            'actual_charge_display' => $actualChargeDisplay,
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601ZuluString(),
            'is_image_expired' => $isImageExpired,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'created_at_formatted' => $this->created_at?->utc()->format('M j, Y H:i:s \U\T\C'),
            'completed_at_formatted' => $this->completed_at?->utc()->format('M j, Y H:i:s \U\T\C'),
        ];
    }
}
