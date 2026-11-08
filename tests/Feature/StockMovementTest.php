<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\WasteReason;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ValueError;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simpan satu transaksi dengan cara yang sama seperti nanti di StockService:
     * data input lewat fill(), user_id dan stock_after diisi eksplisit.
     *
     * @param  array<string, mixed>  $input
     */
    private function recordMovement(Item $item, array $input, int $stockAfter): StockMovement
    {
        $movement = new StockMovement;
        $movement->fill(array_merge(['item_id' => $item->id], $input));
        $movement->user_id = User::factory()->create()->id;
        $movement->stock_after = $stockAfter;
        $movement->save();

        return $movement->fresh();
    }

    public function test_type_and_reason_are_cast_to_enums(): void
    {
        $item = Item::factory()->create();

        $movement = $this->recordMovement($item, [
            'type' => MovementType::Waste,
            'quantity' => 2,
            'reason' => WasteReason::CookingError,
        ], stockAfter: 8);

        $this->assertSame(MovementType::Waste, $movement->type);
        $this->assertSame(WasteReason::CookingError, $movement->reason);
        $this->assertSame('Gosong/Salah Olah', $movement->reason->label());
        $this->assertSame(2, $movement->quantity);
        $this->assertSame(8, $movement->stock_after);
    }

    public function test_reason_is_stored_as_plain_string_in_database(): void
    {
        $item = Item::factory()->create();

        $this->recordMovement($item, [
            'type' => 'waste',
            'quantity' => 1,
            'reason' => 'expired',
        ], stockAfter: 4);

        $this->assertDatabaseHas('stock_movements', ['type' => 'waste', 'reason' => 'expired']);
    }

    public function test_reason_and_note_can_be_empty_for_stock_in(): void
    {
        $item = Item::factory()->create();

        $movement = $this->recordMovement($item, [
            'type' => MovementType::In,
            'quantity' => 10,
        ], stockAfter: 10);

        $this->assertNull($movement->reason);
        $this->assertNull($movement->note);
    }

    public function test_adjust_quantity_can_be_negative(): void
    {
        $item = Item::factory()->create();

        $movement = $this->recordMovement($item, [
            'type' => MovementType::Adjust,
            'quantity' => -3,
        ], stockAfter: 7);

        $this->assertSame(-3, $movement->quantity);
    }

    public function test_only_created_at_is_recorded(): void
    {
        $item = Item::factory()->create();

        $movement = $this->recordMovement($item, [
            'type' => MovementType::In,
            'quantity' => 5,
        ], stockAfter: 5);

        $this->assertNotNull($movement->created_at);
        $this->assertArrayNotHasKey('updated_at', $movement->getAttributes());
    }

    public function test_user_id_and_stock_after_cannot_be_mass_assigned(): void
    {
        $movement = new StockMovement([
            'item_id' => 1,
            'type' => MovementType::Out,
            'quantity' => 1,
            'user_id' => 99,
            'stock_after' => 1000,
        ]);

        $this->assertNull($movement->user_id);
        $this->assertNull($movement->stock_after);
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->expectException(ValueError::class);

        new StockMovement(['type' => 'hilang']);
    }

    public function test_item_with_movements_cannot_be_force_deleted(): void
    {
        $item = Item::factory()->create();
        $this->recordMovement($item, ['type' => MovementType::In, 'quantity' => 5], stockAfter: 5);

        $this->expectException(QueryException::class);

        $item->forceDelete();
    }
}
