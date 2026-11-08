<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// `stock` sengaja tidak fillable: stok hanya boleh berubah lewat transaksi.
// `photo_path` diisi dari hasil upload foto, bukan dari input teks.
#[Fillable(['sku', 'name', 'category_id', 'unit', 'min_stock', 'price', 'description'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
        ];
    }
}
