<?php

use App\Services\Pixelforge\PixelforgeSignatureVerifier;

it('verifies a valid Pixelforge-Signature header within tolerance', function () {
    $secret = 'whsec_test_secret_123';
    $rawBody = '{"id":"evt_1","type":"ping","data":{"object":{}}}';
    $timestamp = 1_763_470_000;
    $sig = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

    $verifier = new PixelforgeSignatureVerifier(secret: $secret, toleranceSeconds: 300);

    expect($verifier->verify("t={$timestamp},v1={$sig}", $rawBody, $timestamp + 120))->toBeTrue()
        ->and($verifier->verify("t={$timestamp},v1=".strtoupper($sig), $rawBody, $timestamp - 300))->toBeTrue();
});

it('rejects missing, malformed, wrong-secret, stale, or tampered signatures', function () {
    $secret = 'whsec_test_secret_123';
    $rawBody = '{"id":"evt_1","type":"ping"}';
    $timestamp = 1_763_470_000;
    $validSig = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

    $verifier = new PixelforgeSignatureVerifier(secret: $secret, toleranceSeconds: 300);

    // Missing or malformed header
    expect($verifier->verify(null, $rawBody, $timestamp))->toBeFalse()
        ->and($verifier->verify('', $rawBody, $timestamp))->toBeFalse()
        ->and($verifier->verify("t={$timestamp}", $rawBody, $timestamp))->toBeFalse()
        ->and($verifier->verify("v1={$validSig}", $rawBody, $timestamp))->toBeFalse()
        ->and($verifier->verify('t=notanumber,v1=invalid', $rawBody, $timestamp))->toBeFalse();

    // Wrong secret
    $wrongSecretSig = hash_hmac('sha256', "{$timestamp}.{$rawBody}", 'whsec_wrong');
    expect($verifier->verify("t={$timestamp},v1={$wrongSecretSig}", $rawBody, $timestamp))->toBeFalse();

    // Stale timestamp (> 300s old or > 300s in the future)
    expect($verifier->verify("t={$timestamp},v1={$validSig}", $rawBody, $timestamp + 301))->toBeFalse()
        ->and($verifier->verify("t={$timestamp},v1={$validSig}", $rawBody, $timestamp - 301))->toBeFalse();

    // Tampered raw body (even a single whitespace difference)
    expect($verifier->verify("t={$timestamp},v1={$validSig}", $rawBody.' ', $timestamp))->toBeFalse()
        ->and($verifier->verify("t={$timestamp},v1={$validSig}", '{"id":"evt_2","type":"ping"}', $timestamp))->toBeFalse();
});
