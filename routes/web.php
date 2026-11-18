<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->withoutMiddleware('web')
    ->name('webhooks.pixelforge');

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:60,1')
        ->name('generations.estimate');
});

require __DIR__.'/settings.php';
