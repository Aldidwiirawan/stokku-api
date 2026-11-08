<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfilePhotoRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * PUT /api/v1/profile
     * Ubah nama, dan (opsional) ganti password.
     */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();

        $user->name = $request->validated('name');

        $newPassword = $request->validated('password');
        if ($newPassword !== null) {
            $user->password = $newPassword; // otomatis di-hash oleh cast 'hashed' di model User
        }

        $user->save();

        if ($newPassword !== null) {
            // Cabut semua token lain: perangkat lain (mungkin dipakai orang yang tahu
            // password lama) harus login ulang. Token yang sedang dipakai tetap berlaku.
            $currentTokenId = $user->currentAccessToken()->id;
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        return new UserResource($user);
    }

    /**
     * POST /api/v1/profile/photo
     * Unggah foto profil baru (multipart, field "photo"), lalu hapus foto lama.
     */
    public function updatePhoto(UpdateProfilePhotoRequest $request): UserResource
    {
        $user = $request->user();
        $oldPath = $user->photo_path;

        // Simpan foto baru dulu. Nama file dibuat acak oleh Laravel,
        // contoh hasil: "photos/users/a1B2c3....jpg"
        $user->photo_path = $request->file('photo')->store('photos/users', 'public');
        $user->save();

        // Foto lama baru dihapus setelah foto baru tersimpan,
        // supaya pengguna tidak kehilangan foto kalau penyimpanan gagal.
        if ($oldPath !== null) {
            Storage::disk('public')->delete($oldPath);
        }

        return new UserResource($user);
    }
}
