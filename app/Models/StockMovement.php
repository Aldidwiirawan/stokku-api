<?php

namespace App\Models;

use App\Enums\MovementType;
use App\Enums\WasteReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// `user_id` dan `stock_after` dihitung server, jadi diisi secara eksplisit.
#[Fillable(['item_id', 'type', 'quantity', 'reason', 'note'])]
class StockMovement extends Model
{
    /**
     * Tabel ini tidak punya kolom updated_at, karena riwayat transaksi tidak pernah diubah.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'reason' => WasteReason::class,
            'quantity' => 'integer',
            'stock_after' => 'integer',
        ];
    }

    /**
     * Bahan yang ditransaksikan. withTrashed(): bahan yang sudah dihapus
     * (soft delete) tetap terbaca, supaya riwayat lama tetap lengkap.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * Pengguna yang mencatat transaksi ini.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
