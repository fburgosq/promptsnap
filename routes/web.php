<?php

use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->withoutMiddleware('web')
    ->name('webhooks.pixelforge');

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
