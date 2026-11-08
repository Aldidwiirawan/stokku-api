<?php

namespace App\Support;

/**
 * Membuat URL lengkap untuk file di disk "public" (storage/app/public).
 *
 * Memakai asset(), yang membentuk URL dari alamat request yang sedang berjalan.
 * Contoh: API dibuka dari HP lewat http://192.168.1.10:8000, maka URL foto juga
 * http://192.168.1.10:8000/storage/..., bukan http://localhost:8000 dari APP_URL
 * (localhost di HP berarti HP itu sendiri, jadi fotonya tidak akan terbuka).
 */
class StorageUrl
{
    public static function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return asset('storage/'.$path);
    }
}
