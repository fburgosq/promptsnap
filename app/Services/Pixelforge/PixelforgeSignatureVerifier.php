<?php

namespace App\Services\Pixelforge;

use Illuminate\Support\Carbon;

final class PixelforgeSignatureVerifier
{
    private readonly string $secret;

    private readonly int $toleranceSeconds;

    public function __construct(
        ?string $secret = null,
        ?int $toleranceSeconds = null,
    ) {
        $this->secret = $secret ?? (string) config('services.pixelforge.webhook_secret', '');
        $this->toleranceSeconds = $toleranceSeconds ?? (int) config('services.pixelforge.webhook_tolerance_seconds', 300);
    }

    public function verify(?string $signatureHeader, string $rawBody, ?int $currentTimestamp = null): bool
    {
        if ($this->secret === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $parsed = $this->parseHeader($signatureHeader);
        if ($parsed === null) {
            return false;
        }

        ['t' => $timestamp, 'v1' => $signature] = $parsed;

        $now = $currentTimestamp ?? Carbon::now('UTC')->getTimestamp();

        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        return hash_equals($expected, strtolower($signature));
    }

    /**
     * @return array{t: int, v1: string}|null
     */
    private function parseHeader(string $header): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $segment) {
            $parts = explode('=', trim($segment), 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = $parts;

            if ($key === 't' && preg_match('/^\d+$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-fA-F0-9]{64}$/', $value) === 1) {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null) {
            return null;
        }

        return [
            't' => $timestamp,
            'v1' => $signature,
        ];
    }
}
