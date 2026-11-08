<?php

namespace App\Http\Middleware;

use App\Exceptions\AccountInactiveException;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tolak pengguna yang akunnya sudah dinonaktifkan, walaupun tokennya masih ada.
 * Dipasang SETELAH auth:sanctum di semua route yang butuh login.
 */
class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        if (! $user->is_active) {
            throw new AccountInactiveException; // 403 + "code": "account_inactive"
        }

        return $next($request);
    }
}
