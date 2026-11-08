<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

// Semua route di file ini otomatis berawalan /api (diatur di bootstrap/app.php).
// Hasil akhirnya: /api/v1/...
Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('/ping', PingController::class)->name('ping');

    // Publik. throttle:5,1 = maksimal 5 percobaan per menit (per alamat IP).
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    // Wajib mengirim header "Authorization: Bearer <token>".
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});
