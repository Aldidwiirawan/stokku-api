<?php

namespace App\Http\Controllers;

use Illuminate\Http\Resources\Json\JsonResource;

class PingController extends Controller
{
    /**
     * Cek apakah API bisa dihubungi. Publik, tanpa token.
     */
    public function __invoke(): JsonResource
    {
        return new JsonResource([
            'status' => 'ok',
            'app' => 'StokKu API',
            'version' => 'v1',
            // ISO 8601 lengkap dengan zona waktu, contoh: 2026-11-08T03:15:00+00:00
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
