<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Contoh bahan baku dapur ayam bakar/ikan bakar: nama, satuan, dan harga per satuan (Rupiah).
     *
     * @var list<array{name: string, unit: string, price: int}>
     */
    public const INGREDIENTS = [
        ['name' => 'Ayam Potong Utuh', 'unit' => 'ekor', 'price' => 38000],
        ['name' => 'Ikan Gurame', 'unit' => 'kg', 'price' => 55000],
        ['name' => 'Ikan Nila', 'unit' => 'kg', 'price' => 35000],
        ['name' => 'Kecap Manis', 'unit' => 'liter', 'price' => 28000],
        ['name' => 'Cabai Rawit', 'unit' => 'kg', 'price' => 60000],
        ['name' => 'Bawang Merah', 'unit' => 'kg', 'price' => 40000],
        ['name' => 'Bawang Putih', 'unit' => 'kg', 'price' => 35000],
        ['name' => 'Tomat', 'unit' => 'kg', 'price' => 15000],
        ['name' => 'Timun', 'unit' => 'kg', 'price' => 10000],
        ['name' => 'Beras', 'unit' => 'karung', 'price' => 340000],
        ['name' => 'Minyak Goreng', 'unit' => 'liter', 'price' => 18000],
        ['name' => 'Arang Batok', 'unit' => 'karung', 'price' => 75000],
        ['name' => 'Gas Elpiji 12 kg', 'unit' => 'pcs', 'price' => 210000],
        ['name' => 'Box Nasi', 'unit' => 'pack', 'price' => 45000],
        ['name' => 'Teh Melati', 'unit' => 'pack', 'price' => 12000],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ingredient = fake()->randomElement(self::INGREDIENTS);

        return [
            'sku' => $this->uniqueSku(),
            'name' => $ingredient['name'],
            'category_id' => Category::factory(),
            'unit' => $ingredient['unit'],
            'stock' => fake()->numberBetween(0, 100),
            'min_stock' => fake()->numberBetween(5, 20),
            'price' => $ingredient['price'],
            'photo_path' => null,
            'description' => null,
        ];
    }

    /**
     * SKU berformat BHN-0001 sampai BHN-9999, tidak kembar dalam satu test.
     */
    private function uniqueSku(): string
    {
        $number = fake()->unique()->numberBetween(1, 9999);

        return sprintf('BHN-%04d', $number);
    }
}
