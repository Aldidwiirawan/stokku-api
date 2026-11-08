<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,            // "in" / "out" / "waste" / "adjust"
            'type_label' => $this->type->label(),     // "Masuk" / "Keluar" / "Terbuang" / "Penyesuaian"
            'quantity' => $this->quantity,
            'reason' => $this->reason?->value,        // null jika bukan waste
            'reason_label' => $this->reason?->label(),
            'note' => $this->note,
            'stock_after' => $this->stock_after,
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            // Data bahan SAAT INI (bukan saat transaksi). Dipakai aplikasi untuk
            // menampilkan stok terbaru dan memicu notifikasi stok menipis (F-21).
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'sku' => $this->item->sku,
                'name' => $this->item->name,
                'unit' => $this->item->unit,
                'stock' => $this->item->stock,
                'min_stock' => $this->item->min_stock,
                'is_low_stock' => $this->item->stock <= $this->item->min_stock,
            ]),
        ];
    }
}
