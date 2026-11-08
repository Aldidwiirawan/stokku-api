<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Dilempar saat akun pengguna nonaktif (saat login atau di request yang sudah login).
 *
 * Respons-nya punya field "code" supaya aplikasi bisa membedakannya dari 403 lain
 * tanpa mencocokkan teks pesan: akun nonaktif → aplikasi logout,
 * 403 lain ("Akses ditolak.") → tetap login.
 *
 * Turunan AccessDeniedHttpException (403), jadi tidak dicatat sebagai error di log.
 */
class AccountInactiveException extends AccessDeniedHttpException
{
    public const CODE = 'account_inactive';

    public const MESSAGE = 'Akun Anda nonaktif. Hubungi admin.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    /**
     * Laravel memanggil method ini otomatis untuk membuat respons.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => self::MESSAGE,
            'code' => self::CODE,
        ], 403);
    }
}
