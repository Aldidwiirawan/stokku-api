<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Bentuk JSON data pengguna. Password dan remember_token tidak pernah ikut,
     * karena hanya kolom yang ditulis di sini yang dikirim.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            // URL lengkap supaya aplikasi bisa langsung menampilkan fotonya.
            'photo_url' => $this->photo_path
                ? Storage::disk('public')->url($this->photo_path)
                : null,
            // ISO 8601 dalam UTC, contoh: 2026-11-08T02:17:34+00:00
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
