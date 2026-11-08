<?php

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

// Semua route di file ini otomatis berawalan /api (diatur di bootstrap/app.php).
// Hasil akhirnya: /api/v1/...
Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('/ping', PingController::class)->name('ping');
});
