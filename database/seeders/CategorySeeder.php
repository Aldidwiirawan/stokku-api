<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * 8 kategori bahan baku dapur ayam bakar/ikan bakar.
     *
     * @var list<string>
     */
    public const NAMES = [
        'Daging & Unggas',
        'Bumbu & Rempah',
        'Sayur & Lalapan',
        'Bahan Pokok',
        'Minyak & Saus',
        'Bahan Bakar Dapur',
        'Kemasan',
        'Minuman',
    ];

    /**
     * Aman dijalankan ulang: kategori yang sudah ada tidak dibuat dobel.
     */
    public function run(): void
    {
        foreach (self::NAMES as $name) {
            Category::firstOrCreate(['name' => $name]);
        }
    }
}
