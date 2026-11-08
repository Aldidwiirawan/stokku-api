<?php

namespace App\Http\Resources;

use App\Models\Item;
use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            'stock' => $this->stock,
            'min_stock' => $this->min_stock,
            // Teks berformat 2 desimal, contoh "38000.00" (cast decimal:2, presisi uang terjaga).
            'price' => $this->price,
            'description' => $this->description,
            'is_low_stock' => $this->stock <= $this->min_stock,
            'photo_url' => StorageUrl::publicUrl($this->photo_path),
            // whenLoaded: hanya dikirim kalau relasinya sudah dimuat (eager load) di controller,
            // jadi resource ini tidak pernah memicu query tambahan sendiri.
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'recent_movements' => StockMovementResource::collection($this->whenLoaded('stockMovements')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
