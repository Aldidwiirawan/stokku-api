<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Semua route di file ini otomatis berawalan /api (diatur di bootstrap/app.php).
// Hasil akhirnya: /api/v1/...
Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('/ping', PingController::class)->name('ping');

    // Publik. throttle:5,1 = maksimal 5 percobaan per menit (per alamat IP).
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    // Wajib token, tapi TIDAK dicek aktif: akun yang baru dinonaktifkan
    // tetap boleh mencabut tokennya sendiri.
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('auth.logout');

    // Wajib token + akun aktif. Semua route baru yang butuh login masuk ke sini.
    // Route khusus Admin ditambah ->middleware('role:admin').
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/photo', [ProfileController::class, 'updatePhoto'])->name('profile.photo');
    });
});
