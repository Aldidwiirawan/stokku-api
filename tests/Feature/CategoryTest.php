<?php

namespace Tests\Feature;

use App\Models\Category;
use Database\Factories\CategoryFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_categories_with_unique_culinary_names(): void
    {
        $categories = Category::factory()->count(5)->create();

        foreach ($categories as $category) {
            $this->assertContains($category->name, CategoryFactory::NAMES);
        }

        $this->assertCount(5, $categories->pluck('name')->unique());
    }

    public function test_factory_can_create_more_categories_than_the_name_list(): void
    {
        $categories = Category::factory()->count(20)->create();
        $names = $categories->pluck('name');

        $this->assertCount(20, $names->unique());

        // 12 nama pertama dari daftar, sisanya nama pengganti.
        $fromList = $names->filter(fn (string $name) => in_array($name, CategoryFactory::NAMES));
        $fallback = $names->filter(fn (string $name) => str_starts_with($name, 'Kategori Bahan '));

        $this->assertCount(count(CategoryFactory::NAMES), $fromList);
        $this->assertCount(20 - count(CategoryFactory::NAMES), $fallback);
    }

    public function test_category_name_must_be_unique_in_database(): void
    {
        Category::create(['name' => 'Bumbu & Rempah']);

        $this->expectException(QueryException::class);

        Category::create(['name' => 'Bumbu & Rempah']);
    }
}
