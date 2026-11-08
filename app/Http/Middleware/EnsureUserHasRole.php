<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hanya izinkan pengguna dengan peran tertentu.
 * Pemakaian di route: ->middleware('role:admin') atau ->middleware('role:admin,staf')
 * Harus dipasang SETELAH auth:sanctum, supaya pengguna sudah diketahui.
 */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$roles  peran yang diizinkan, dari teks setelah "role:"
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Pengaman kalau middleware ini lupa dipasang setelah auth:sanctum.
        if ($user === null) {
            throw new AuthenticationException;
        }

        if (! in_array($user->role, $roles, true)) {
            abort(403); // dirender menjadi "Akses ditolak." (lihat bootstrap/app.php)
        }

        return $next($request);
    }
}
