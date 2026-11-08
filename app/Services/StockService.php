<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\WasteReason;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat yang mengubah stok bahan.
 *
 * Setiap perubahan stok:
 * 1. berjalan di dalam DB::transaction() (semua berhasil atau semua dibatalkan),
 * 2. mengunci baris bahan dengan lockForUpdate() supaya dua transaksi bersamaan
 *    tidak membaca stok lama yang sama,
 * 3. mencatat riwayat di stock_movements beserta stock_after.
 */
class StockService
{
    /**
     * @param  int  $quantity  untuk in/out/waste: jumlah (≥ 1); untuk adjust: stok FISIK hasil hitung (≥ 0)
     *
     * @throws ValidationException jika stok tidak cukup atau tidak ada selisih (adjust)
     */
    public function record(
        int $itemId,
        User $user,
        MovementType $type,
        int $quantity,
        ?WasteReason $reason = null,
        ?string $note = null,
    ): StockMovement {
        return DB::transaction(function () use ($itemId, $user, $type, $quantity, $reason, $note) {
            // Kunci baris bahan sampai transaksi selesai. Request lain yang ingin
            // mengubah bahan yang sama harus menunggu di baris ini.
            $item = Item::whereKey($itemId)->lockForUpdate()->firstOrFail();

            $stockBefore = $item->stock;

            if ($type === MovementType::Adjust) {
                $stockAfter = $quantity;                        // stok fisik
                $change = $stockAfter - $stockBefore;           // selisih yang disimpan (bisa minus)

                if ($change === 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Tidak ada selisih stok.',
                    ]);
                }
            } elseif ($type === MovementType::In) {
                $change = $quantity;
                $stockAfter = $stockBefore + $quantity;
            } else {
                // out dan waste: tidak boleh melebihi stok yang ada.
                if ($quantity > $stockBefore) {
                    throw ValidationException::withMessages([
                        'quantity' => "Jumlah melebihi stok saat ini ({$stockBefore} {$item->unit}).",
                    ]);
                }

                $change = $quantity;
                $stockAfter = $stockBefore - $quantity;
            }

            $item->stock = $stockAfter;
            $item->save();

            $movement = new StockMovement;
            $movement->fill([
                'item_id' => $item->id,
                'type' => $type,
                'quantity' => $change,
                'reason' => $reason,
                'note' => $note,
            ]);
            $movement->user_id = $user->id;
            $movement->stock_after = $stockAfter;
            $movement->save();

            // Bahan yang sudah dikunci & diperbarui dipasang ke relasi,
            // supaya respons memakai stok terbaru tanpa query tambahan.
            $movement->setRelation('item', $item);
            $movement->setRelation('user', $user);

            return $movement;
        });
    }
}
