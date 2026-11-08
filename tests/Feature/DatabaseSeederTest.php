<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\WasteReason;
use App\Models\Category;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\ItemSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pastikan jumlah data sama persis dengan isi seeder.
     */
    private function assertSeededCounts(): void
    {
        $this->assertSame(2, User::count());
        $this->assertSame(8, Category::count());
        $this->assertSame(30, Item::withTrashed()->count());
        $this->assertSame(40, StockMovement::count());
    }

    public function test_seeder_creates_expected_demo_data(): void
    {
        $this->seed();

        $this->assertSeededCounts();

        $admin = User::where('email', UserSeeder::ADMIN_EMAIL)->first();
        $staff = User::where('email', UserSeeder::STAFF_EMAIL)->first();
        $this->assertTrue($admin->isAdmin());
        $this->assertSame('staf', $staff->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($staff->is_active);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue(Hash::check('password', $staff->password));
    }

    public function test_each_item_stock_equals_stock_after_of_its_latest_movement(): void
    {
        $this->seed();

        foreach (Item::all() as $item) {
            // Transaksi terakhir menurut waktu, lalu id (kalau waktunya sama).
            $latest = $item->stockMovements()->orderByDesc('created_at')->orderByDesc('id')->first();
            // Transaksi terakhir menurut urutan disimpan.
            $latestById = $item->stockMovements()->orderByDesc('id')->first();

            $this->assertNotNull($latest, "{$item->name} harus punya transaksi");
            $this->assertTrue($latest->is($latestById), "Urutan waktu transaksi {$item->name} harus sama dengan urutan id");
            $this->assertSame($latest->stock_after, $item->stock, "Stok {$item->name} harus sama dengan stock_after transaksi terakhirnya");
        }
    }

    public function test_stock_after_is_consistent_for_every_movement(): void
    {
        $this->seed();

        foreach (Item::all() as $item) {
            $stock = 0;

            foreach ($item->stockMovements()->orderBy('id')->get() as $movement) {
                if ($movement->type === MovementType::Adjust || $movement->type === MovementType::In) {
                    $stock += $movement->quantity;     // adjust menyimpan selisih (+/-)
                } else {
                    $stock -= $movement->quantity;     // out dan waste
                }

                $this->assertSame($stock, $movement->stock_after);
                $this->assertGreaterThanOrEqual(0, $movement->stock_after);
            }
        }
    }

    public function test_seeder_covers_all_movement_types_and_waste_reasons(): void
    {
        $this->seed();

        foreach (MovementType::cases() as $type) {
            $this->assertTrue(StockMovement::where('type', $type)->exists(), "Harus ada transaksi {$type->value}");
        }

        foreach (WasteReason::cases() as $reason) {
            $this->assertTrue(StockMovement::where('reason', $reason)->exists(), "Harus ada alasan {$reason->value}");
        }

        // Aturan PRD: waste wajib reason, reason other wajib note, adjust oleh admin.
        $this->assertSame(0, StockMovement::where('type', MovementType::Waste)->whereNull('reason')->count());
        $this->assertSame(0, StockMovement::where('reason', WasteReason::Other)->whereNull('note')->count());
        $admin = User::where('email', UserSeeder::ADMIN_EMAIL)->first();
        $this->assertSame(0, StockMovement::where('type', MovementType::Adjust)->where('user_id', '!=', $admin->id)->count());
    }

    public function test_some_items_are_below_minimum_stock(): void
    {
        $this->seed();

        $lowStockNames = Item::lowStock()->pluck('name');

        $this->assertGreaterThan(0, $lowStockNames->count());
        $this->assertContains('Ayam Potong Utuh', $lowStockNames);
    }

    public function test_seeder_has_movements_today_for_dashboard(): void
    {
        $this->seed();

        $this->assertGreaterThan(0, StockMovement::whereDate('created_at', today())->count());
    }

    public function test_running_seeder_twice_does_not_fail_or_duplicate_data(): void
    {
        $this->seed();
        $stockBefore = Item::orderBy('id')->pluck('stock', 'sku')->all();

        $this->seed();

        $this->assertSeededCounts();
        $this->assertSame($stockBefore, Item::orderBy('id')->pluck('stock', 'sku')->all());
    }

    public function test_rerun_keeps_changed_password_and_soft_deleted_item(): void
    {
        $this->seed();

        $staff = User::where('email', UserSeeder::STAFF_EMAIL)->first();
        $staff->password = 'rahasia-baru';
        $staff->save();

        $deletedSku = ItemSeeder::ITEMS[0]['sku'];
        Item::where('sku', $deletedSku)->first()->delete();

        $this->seed();

        $this->assertSeededCounts();
        $this->assertTrue(Hash::check('rahasia-baru', $staff->fresh()->password));
        $this->assertSoftDeleted('items', ['sku' => $deletedSku]);
    }
}
