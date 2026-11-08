<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationsTest extends TestCase
{
    use RefreshDatabase;

    private function recordMovement(Item $item, User $user): StockMovement
    {
        $movement = $item->stockMovements()->make([
            'type' => MovementType::In,
            'quantity' => 5,
        ]);
        $movement->user_id = $user->id;
        $movement->stock_after = 5;
        $movement->save();

        return $movement;
    }

    public function test_category_has_many_items_and_item_belongs_to_category(): void
    {
        $category = Category::factory()->create();
        $items = Item::factory()->count(3)->create(['category_id' => $category->id]);

        $this->assertCount(3, $category->items);
        $this->assertTrue($items->first()->category->is($category));
    }

    public function test_category_items_exclude_soft_deleted_items(): void
    {
        $category = Category::factory()->create();
        Item::factory()->count(2)->create(['category_id' => $category->id]);
        Item::factory()->create(['category_id' => $category->id])->delete();

        $this->assertCount(2, $category->items);
        $this->assertSame(3, $category->items()->withTrashed()->count());
    }

    public function test_item_has_many_stock_movements(): void
    {
        $item = Item::factory()->create();
        $user = User::factory()->create();
        $this->recordMovement($item, $user);
        $this->recordMovement($item, $user);

        $this->assertCount(2, $item->stockMovements);
    }

    public function test_stock_movement_belongs_to_item_and_user(): void
    {
        $item = Item::factory()->create();
        $user = User::factory()->create();
        $movement = $this->recordMovement($item, $user)->fresh();

        $this->assertTrue($movement->item->is($item));
        $this->assertTrue($movement->user->is($user));
    }

    public function test_user_has_many_stock_movements(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $item = Item::factory()->create();
        $this->recordMovement($item, $user);
        $this->recordMovement($item, $user);
        $this->recordMovement($item, $otherUser);

        $this->assertCount(2, $user->stockMovements);
        $this->assertCount(1, $otherUser->stockMovements);
    }

    public function test_movement_still_shows_soft_deleted_item(): void
    {
        $item = Item::factory()->create(['name' => 'Ikan Gurame']);
        $movement = $this->recordMovement($item, User::factory()->create());

        $item->delete();

        $this->assertSame('Ikan Gurame', $movement->fresh()->item->name);
    }
}
