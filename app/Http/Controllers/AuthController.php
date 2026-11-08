<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     * Cek email & password, lalu buat token baru untuk perangkat ini.
     */
    public function login(LoginRequest $request): JsonResource
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Pesan sama untuk "email tidak terdaftar" dan "password salah",
        // supaya orang lain tidak bisa menebak email mana yang terdaftar.
        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Email atau password salah.',
            ]);
        }

        // Status aktif baru dicek setelah password terbukti benar,
        // jadi hanya pemilik akun yang tahu akunnya nonaktif.
        if (! $user->is_active) {
            abort(403, 'Akun Anda nonaktif. Hubungi admin.');
        }

        $token = $user->createToken($request->validated('device_name'))->plainTextToken;

        return new JsonResource([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * GET /api/v1/auth/me
     * Data pengguna pemilik token.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * POST /api/v1/auth/logout
     * Hapus token yang sedang dipakai saja; token di perangkat lain tetap berlaku.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
