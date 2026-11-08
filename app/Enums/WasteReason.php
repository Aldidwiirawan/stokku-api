<?php

namespace App\Enums;

/**
 * Alasan bahan terbuang. Hanya dipakai jika jenis transaksinya `waste`.
 */
enum WasteReason: string
{
    case Expired = 'expired';
    case Damaged = 'damaged';
    case CookingError = 'cooking_error';
    case Other = 'other';

    /**
     * Nama alasan untuk ditampilkan ke pengguna.
     */
    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Kedaluwarsa',
            self::Damaged => 'Rusak/Busuk',
            self::CookingError => 'Gosong/Salah Olah',
            self::Other => 'Lainnya',
        };
    }
}
