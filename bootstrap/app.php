<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Bawaan Laravel: tamu yang belum login diarahkan ke route web "login".
        // StokKu hanya API (tanpa halaman login web), jadi tidak diarahkan ke mana pun;
        // hasilnya AuthenticationException → dirender jadi JSON 401 di bawah.
        $middleware->redirectGuestsTo(null);

        // Nama pendek middleware untuk dipakai di routes/api.php.
        $middleware->alias([
            'role' => EnsureUserHasRole::class,     // ->middleware('role:admin')
            'active' => EnsureUserIsActive::class,  // ->middleware('active')
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Semua error di /api/* selalu dikirim sebagai JSON, walaupun aplikasi
        // lupa mengirim header "Accept: application/json".
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 401: belum login, token salah, atau token sudah dicabut.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null; // null = biarkan Laravel menangani seperti biasa
            }

            return response()->json([
                'message' => 'Belum login atau sesi sudah berakhir. Silakan login lagi.',
            ], 401);
        });

        // 422: validasi gagal. Format sesuai PRD: message + errors per field.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Data tidak valid.',
                'errors' => $e->errors(),
            ], $e->status);
        });

        // 403, 404, 429: dibedakan dari status code-nya.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            if ($status === 403) {
                // abort(403, 'pesan') dari kode kita sendiri → pakai pesan itu.
                // Penolakan lain (misalnya dari Gate/Policy) → pesan umum.
                $isOwnMessage = $e->getPrevious() === null && $e->getMessage() !== '';
                $message = $isOwnMessage ? $e->getMessage() : 'Akses ditolak.';
            } elseif ($status === 404) {
                // findOrFail() yang gagal → data tidak ada; selain itu → alamat salah.
                $message = $e->getPrevious() instanceof ModelNotFoundException
                    ? 'Data tidak ditemukan.'
                    : 'Endpoint tidak ditemukan.';
            } elseif ($status === 429) {
                $message = 'Terlalu banyak percobaan. Coba lagi sebentar.';
            } else {
                return null;
            }

            // getHeaders(): misalnya Retry-After pada 429 tetap ikut dikirim.
            return response()->json(['message' => $message], $status, $e->getHeaders());
        });
    })->create();
