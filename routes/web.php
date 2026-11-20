<?php

use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->withoutMiddleware('web')
    ->name('webhooks.pixelforge');

Route::get('/', function (Request $request) {
    if ($request->user()) {
        return to_route('dashboard');
    }

    return Inertia::render('auth/Login', [
        'canResetPassword' => Features::enabled(Features::resetPasswords()),
        'status' => $request->session()->get('status'),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:60,1')
        ->name('generations.estimate');

    Route::get('/credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('/credits/purchase', [CreditController::class, 'store'])->name('credits.purchase');

    Route::get('/admin/report', AdminReportController::class)
        ->middleware('admin')
        ->name('admin.report');
});

require __DIR__.'/settings.php';
