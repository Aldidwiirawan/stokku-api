<?php

namespace App\Enums;

/**
 * Jenis transaksi stok. Nilai string-nya yang disimpan di kolom `stock_movements.type`.
 */
enum MovementType: string
{
    case In = 'in';
    case Out = 'out';
    case Waste = 'waste';
    case Adjust = 'adjust';

    /**
     * Nama jenis transaksi untuk ditampilkan ke pengguna.
     */
    public function label(): string
    {
        return match ($this) {
            self::In => 'Masuk',
            self::Out => 'Keluar',
            self::Waste => 'Terbuang',
            self::Adjust => 'Penyesuaian',
        };
    }
}
