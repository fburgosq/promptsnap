<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Nano;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'status' => $this->status->value,
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
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601ZuluString(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
