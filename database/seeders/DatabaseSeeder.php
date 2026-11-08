<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Isi database dengan data contoh StokKu.
     * Aman dijalankan ulang (php artisan db:seed) tanpa data dobel.
     * Urutan penting: transaksi butuh akun dan bahan, bahan butuh kategori.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            CategorySeeder::class,
            ItemSeeder::class,
            StockMovementSeeder::class,
        ]);
    }
}
