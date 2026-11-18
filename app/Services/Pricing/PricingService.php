<?php

namespace App\Services\Pricing;

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PricingService
{
    public const string CACHE_KEY = 'pixelforge:pricing:v1';

    public const int TTL_SECONDS = 300;

    public function __construct(
        private readonly PixelforgeClient $client,
    ) {}

    public function current(): PricingData
    {
        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return PricingData::fromArray($cached);
        }

        try {
            $payload = $this->client->getPricing();
            $data = PricingData::fromArray($payload);

            Cache::put(self::CACHE_KEY, $data->toArray(), self::TTL_SECONDS);

            return $data;
        } catch (Throwable) {
            return PricingData::fallback();
        }
    }
}
