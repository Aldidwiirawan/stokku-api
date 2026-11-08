<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Kategori bahan ini.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Riwayat transaksi stok bahan ini.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Hanya bahan yang stoknya sudah menyentuh atau di bawah stok minimum.
     * Pemakaian: Item::lowStock()->get()
     *
     * @param  Builder<Item>  $query
     */
    #[Scope]
    protected function lowStock(Builder $query): void
    {
        $query->whereColumn('stock', '<=', 'min_stock');
    }

    /**
     * Cari bahan berdasarkan nama atau SKU, tidak peka huruf besar/kecil.
     * Pemakaian: Item::search('ayam')->get()
     *
     * @param  Builder<Item>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        // Dibungkus where(function) supaya hasilnya: ... AND (name LIKE ? OR sku LIKE ?)
        // whereLike(caseSensitive: false) → `ilike` di PostgreSQL, `like` di SQLite.
        $query->where(function (Builder $query) use ($term) {
            $query->whereLike('name', "%{$term}%", caseSensitive: false)
                ->orWhereLike('sku', "%{$term}%", caseSensitive: false);
        });
    }
}
