<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Item;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    /**
     * 30 bahan baku dapur ayam bakar/ikan bakar.
     * `initial_stock` tidak dipakai di sini: stok awal dibuat sebagai transaksi
     * "masuk" oleh StockMovementSeeder, supaya setiap stok punya jejak.
     *
     * @var list<array{sku: string, name: string, category: string, unit: string, price: int, min_stock: int, initial_stock: int}>
     */
    public const ITEMS = [
        ['sku' => 'BHN-0001', 'name' => 'Ayam Potong Utuh', 'category' => 'Daging & Unggas', 'unit' => 'ekor', 'price' => 38000, 'min_stock' => 30, 'initial_stock' => 60],
        ['sku' => 'BHN-0002', 'name' => 'Ikan Gurame', 'category' => 'Daging & Unggas', 'unit' => 'kg', 'price' => 55000, 'min_stock' => 10, 'initial_stock' => 25],
        ['sku' => 'BHN-0003', 'name' => 'Ikan Nila', 'category' => 'Daging & Unggas', 'unit' => 'kg', 'price' => 35000, 'min_stock' => 10, 'initial_stock' => 8],
        ['sku' => 'BHN-0004', 'name' => 'Ati Ampela Ayam', 'category' => 'Daging & Unggas', 'unit' => 'kg', 'price' => 30000, 'min_stock' => 5, 'initial_stock' => 12],
        ['sku' => 'BHN-0005', 'name' => 'Ikan Lele', 'category' => 'Daging & Unggas', 'unit' => 'kg', 'price' => 28000, 'min_stock' => 10, 'initial_stock' => 30],
        ['sku' => 'BHN-0006', 'name' => 'Cabai Rawit', 'category' => 'Bumbu & Rempah', 'unit' => 'kg', 'price' => 60000, 'min_stock' => 5, 'initial_stock' => 10],
        ['sku' => 'BHN-0007', 'name' => 'Cabai Merah Keriting', 'category' => 'Bumbu & Rempah', 'unit' => 'kg', 'price' => 45000, 'min_stock' => 5, 'initial_stock' => 8],
        ['sku' => 'BHN-0008', 'name' => 'Bawang Merah', 'category' => 'Bumbu & Rempah', 'unit' => 'kg', 'price' => 40000, 'min_stock' => 10, 'initial_stock' => 15],
        ['sku' => 'BHN-0009', 'name' => 'Bawang Putih', 'category' => 'Bumbu & Rempah', 'unit' => 'kg', 'price' => 35000, 'min_stock' => 5, 'initial_stock' => 12],
        ['sku' => 'BHN-0010', 'name' => 'Kunyit', 'category' => 'Bumbu & Rempah', 'unit' => 'kg', 'price' => 20000, 'min_stock' => 2, 'initial_stock' => 5],
        ['sku' => 'BHN-0011', 'name' => 'Jahe', 'category' => 'Bumbu & Rempah', 'unit' => 'kg', 'price' => 25000, 'min_stock' => 2, 'initial_stock' => 1],
        ['sku' => 'BHN-0012', 'name' => 'Garam Dapur', 'category' => 'Bumbu & Rempah', 'unit' => 'pack', 'price' => 5000, 'min_stock' => 10, 'initial_stock' => 40],
        ['sku' => 'BHN-0013', 'name' => 'Timun', 'category' => 'Sayur & Lalapan', 'unit' => 'kg', 'price' => 10000, 'min_stock' => 5, 'initial_stock' => 15],
        ['sku' => 'BHN-0014', 'name' => 'Kol', 'category' => 'Sayur & Lalapan', 'unit' => 'kg', 'price' => 12000, 'min_stock' => 5, 'initial_stock' => 10],
        ['sku' => 'BHN-0015', 'name' => 'Kemangi', 'category' => 'Sayur & Lalapan', 'unit' => 'ikat', 'price' => 3000, 'min_stock' => 20, 'initial_stock' => 30],
        ['sku' => 'BHN-0016', 'name' => 'Tomat', 'category' => 'Sayur & Lalapan', 'unit' => 'kg', 'price' => 15000, 'min_stock' => 5, 'initial_stock' => 12],
        ['sku' => 'BHN-0017', 'name' => 'Beras', 'category' => 'Bahan Pokok', 'unit' => 'karung', 'price' => 340000, 'min_stock' => 3, 'initial_stock' => 8],
        ['sku' => 'BHN-0018', 'name' => 'Gula Pasir', 'category' => 'Bahan Pokok', 'unit' => 'kg', 'price' => 17000, 'min_stock' => 10, 'initial_stock' => 25],
        ['sku' => 'BHN-0019', 'name' => 'Telur Ayam', 'category' => 'Bahan Pokok', 'unit' => 'kg', 'price' => 28000, 'min_stock' => 10, 'initial_stock' => 20],
        ['sku' => 'BHN-0020', 'name' => 'Kecap Manis', 'category' => 'Minyak & Saus', 'unit' => 'liter', 'price' => 28000, 'min_stock' => 10, 'initial_stock' => 24],
        ['sku' => 'BHN-0021', 'name' => 'Minyak Goreng', 'category' => 'Minyak & Saus', 'unit' => 'liter', 'price' => 18000, 'min_stock' => 20, 'initial_stock' => 40],
        ['sku' => 'BHN-0022', 'name' => 'Saus Sambal', 'category' => 'Minyak & Saus', 'unit' => 'liter', 'price' => 22000, 'min_stock' => 5, 'initial_stock' => 6],
        ['sku' => 'BHN-0023', 'name' => 'Terasi', 'category' => 'Minyak & Saus', 'unit' => 'pack', 'price' => 8000, 'min_stock' => 5, 'initial_stock' => 10],
        ['sku' => 'BHN-0024', 'name' => 'Arang Batok', 'category' => 'Bahan Bakar Dapur', 'unit' => 'karung', 'price' => 75000, 'min_stock' => 5, 'initial_stock' => 12],
        ['sku' => 'BHN-0025', 'name' => 'Gas Elpiji 12 kg', 'category' => 'Bahan Bakar Dapur', 'unit' => 'tabung', 'price' => 210000, 'min_stock' => 2, 'initial_stock' => 4],
        ['sku' => 'BHN-0026', 'name' => 'Box Nasi', 'category' => 'Kemasan', 'unit' => 'pack', 'price' => 45000, 'min_stock' => 5, 'initial_stock' => 20],
        ['sku' => 'BHN-0027', 'name' => 'Kantong Plastik', 'category' => 'Kemasan', 'unit' => 'pack', 'price' => 15000, 'min_stock' => 10, 'initial_stock' => 9],
        ['sku' => 'BHN-0028', 'name' => 'Cup Sambal', 'category' => 'Kemasan', 'unit' => 'pack', 'price' => 12000, 'min_stock' => 10, 'initial_stock' => 30],
        ['sku' => 'BHN-0029', 'name' => 'Teh Melati', 'category' => 'Minuman', 'unit' => 'pack', 'price' => 12000, 'min_stock' => 10, 'initial_stock' => 25],
        ['sku' => 'BHN-0030', 'name' => 'Air Mineral Galon', 'category' => 'Minuman', 'unit' => 'galon', 'price' => 20000, 'min_stock' => 5, 'initial_stock' => 10],
    ];

    /**
     * Aman dijalankan ulang: bahan dicari berdasarkan SKU, lalu dibuat atau diperbarui.
     * Kolom `stock` tidak disentuh, karena stok hanya berubah lewat transaksi.
     */
    public function run(): void
    {
        // Contoh hasil: ['Daging & Unggas' => 1, 'Bumbu & Rempah' => 2, ...]
        $categoryIds = Category::pluck('id', 'name');

        foreach (self::ITEMS as $data) {
            // withTrashed(): bahan yang sudah dihapus (soft delete) tetap ditemukan,
            // jadi tidak dibuat ulang dengan SKU yang sama (yang akan error karena SKU unik).
            Item::withTrashed()->updateOrCreate(
                ['sku' => $data['sku']],
                [
                    'name' => $data['name'],
                    'category_id' => $categoryIds[$data['category']],
                    'unit' => $data['unit'],
                    'price' => $data['price'],
                    'min_stock' => $data['min_stock'],
                ],
            );
        }
    }
}
