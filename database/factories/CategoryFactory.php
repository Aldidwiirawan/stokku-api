<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use OverflowException;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Daftar nama kategori bahan baku kuliner yang bisa dipilih factory.
     *
     * @var list<string>
     */
    public const NAMES = [
        'Daging & Unggas',
        'Ikan & Seafood',
        'Bumbu & Rempah',
        'Sayur & Lalapan',
        'Bahan Pokok',
        'Minyak & Saus',
        'Bahan Bakar Dapur',
        'Kemasan',
        'Minuman',
        'Buah',
        'Telur & Susu',
        'Bahan Kering',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->uniqueName(),
        ];
    }

    /**
     * Ambil nama dari daftar selama masih ada yang belum terpakai.
     * Setelah daftar habis, buat nama pengganti seperti "Kategori Bahan 13".
     */
    private function uniqueName(): string
    {
        try {
            // unique() memastikan nama tidak terpilih dua kali dalam satu test.
            return fake()->unique()->randomElement(self::NAMES);
        } catch (OverflowException) {
            // Semua nama di daftar sudah terpakai.
            $number = fake()->unique()->numberBetween(count(self::NAMES) + 1, 99999);

            return 'Kategori Bahan '.$number;
        }
    }
}
