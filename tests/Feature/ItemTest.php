<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_items_with_bhn_sku_format(): void
    {
        $items = Item::factory()->count(20)->create();

        foreach ($items as $item) {
            $this->assertMatchesRegularExpression('/^BHN-\d{4}$/', $item->sku);
        }

        $this->assertCount(20, $items->pluck('sku')->unique());
    }

    public function test_price_and_stock_are_cast_to_the_right_types(): void
    {
        $item = Item::factory()->create(['price' => 38000, 'stock' => 12, 'min_stock' => 5])->fresh();

        $this->assertSame('38000.00', $item->price);
        $this->assertSame(12, $item->stock);
        $this->assertSame(5, $item->min_stock);
    }

    public function test_stock_cannot_be_mass_assigned(): void
    {
        $category = Category::factory()->create();

        $item = Item::create([
            'sku' => 'BHN-0001',
            'name' => 'Ayam Potong Utuh',
            'category_id' => $category->id,
            'unit' => 'ekor',
            'price' => 38000,
            'stock' => 50,
        ])->fresh();

        $this->assertSame(0, $item->stock);
    }

    public function test_sku_must_be_unique_in_database(): void
    {
        Item::factory()->create(['sku' => 'BHN-0001']);

        $this->expectException(QueryException::class);

        Item::factory()->create(['sku' => 'BHN-0001']);
    }

    public function test_delete_is_soft_delete(): void
    {
        $item = Item::factory()->create();

        $item->delete();

        $this->assertSoftDeleted($item);
        $this->assertNull(Item::find($item->id));
        $this->assertNotNull(Item::withTrashed()->find($item->id));
    }

    public function test_category_used_by_item_cannot_be_deleted(): void
    {
        $item = Item::factory()->create();

        $this->expectException(QueryException::class);

        Category::find($item->category_id)->delete();
    }
}
