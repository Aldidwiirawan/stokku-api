<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            // URL lengkap dari host request, supaya aplikasi bisa langsung menampilkan fotonya.
            'photo_url' => StorageUrl::publicUrl($this->photo_path),
            // ISO 8601 dalam UTC, contoh: 2026-11-08T02:17:34+00:00
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
