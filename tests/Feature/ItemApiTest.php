<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\WasteReason;
use App\Models\Category;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Login sebagai Staf. Sanctum::actingAs tidak membuat query token,
        // jadi hitungan query di test N+1 hanya berisi query milik endpoint.
        Sanctum::actingAs(User::factory()->create());
    }

    /**
     * Catat transaksi seperti di seeder: user_id dan stock_after diisi eksplisit.
     */
    private function recordMovement(Item $item, User $user, int $minutesAgo, int $stockAfter): StockMovement
    {
        $movement = new StockMovement;
        $movement->fill(['item_id' => $item->id, 'type' => MovementType::In, 'quantity' => 1]);
        $movement->user_id = $user->id;
        $movement->stock_after = $stockAfter;
        $movement->created_at = now()->subMinutes($minutesAgo);
        $movement->save();

        return $movement;
    }

    /**
     * Hitung berapa query database yang dijalankan selama $callback.
     */
    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // ---------- GET /items ----------

    public function test_list_is_paginated_20_per_page_and_sorted_by_name(): void
    {
        foreach (range(1, 25) as $number) {
            Item::factory()->create(['name' => sprintf('Bahan %02d', $number)]);
        }

        $this->getJson('/api/v1/items')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.name', 'Bahan 01')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 25);

        $this->getJson('/api/v1/items?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.name', 'Bahan 21');
    }

    public function test_list_item_has_expected_fields(): void
    {
        $category = Category::create(['name' => 'Daging & Unggas']);
        $item = Item::factory()->create([
            'sku' => 'BHN-0001', 'name' => 'Ayam Potong Utuh', 'category_id' => $category->id,
            'unit' => 'ekor', 'stock' => 23, 'min_stock' => 30, 'price' => 38000,
        ]);

        $this->getJson('/api/v1/items')
            ->assertOk()
            ->assertJsonPath('data.0', [
                'id' => $item->id,
                'sku' => 'BHN-0001',
                'name' => 'Ayam Potong Utuh',
                'unit' => 'ekor',
                'stock' => 23,
                'min_stock' => 30,
                'price' => '38000.00',
                'description' => null,
                'is_low_stock' => true,
                'photo_url' => null,
                'category' => ['id' => $category->id, 'name' => 'Daging & Unggas'],
                'created_at' => $item->created_at->toIso8601String(),
                'updated_at' => $item->updated_at->toIso8601String(),
            ]);
    }

    public function test_list_excludes_soft_deleted_items(): void
    {
        Item::factory()->create(['name' => 'Masih Ada']);
        Item::factory()->create(['name' => 'Sudah Dihapus'])->delete();

        $this->getJson('/api/v1/items')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Masih Ada');
    }

    public function test_search_by_name_or_sku_ignoring_case(): void
    {
        Item::factory()->create(['name' => 'Ayam Potong Utuh', 'sku' => 'BHN-0001']);
        Item::factory()->create(['name' => 'Telur Ayam', 'sku' => 'BHN-0019']);
        Item::factory()->create(['name' => 'Kecap Manis', 'sku' => 'BHN-0020']);

        $this->getJson('/api/v1/items?search=AYAM')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ayam Potong Utuh')
            ->assertJsonPath('data.1.name', 'Telur Ayam');

        $this->getJson('/api/v1/items?search=bhn-0020')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kecap Manis');
    }

    public function test_filter_by_category(): void
    {
        $meat = Category::create(['name' => 'Daging & Unggas']);
        $spices = Category::create(['name' => 'Bumbu & Rempah']);
        Item::factory()->create(['name' => 'Ikan Gurame', 'category_id' => $meat->id]);
        Item::factory()->create(['name' => 'Cabai Rawit', 'category_id' => $spices->id]);

        $this->getJson("/api/v1/items?category_id={$meat->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ikan Gurame');

        $this->getJson('/api/v1/items?category_id=999')->assertJsonCount(0, 'data');
    }

    public function test_filter_low_stock(): void
    {
        Item::factory()->create(['name' => 'Jahe', 'stock' => 1, 'min_stock' => 2]);
        Item::factory()->create(['name' => 'Kemangi', 'stock' => 20, 'min_stock' => 20]); // sama = menipis
        Item::factory()->create(['name' => 'Beras', 'stock' => 8, 'min_stock' => 3]);

        $this->getJson('/api/v1/items?low_stock=1')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Jahe')
            ->assertJsonPath('data.1.name', 'Kemangi');

        // low_stock=0 = tidak memfilter.
        $this->getJson('/api/v1/items?low_stock=0')->assertJsonCount(3, 'data');
    }

    public function test_search_and_filters_can_be_combined_and_kept_in_page_links(): void
    {
        $meat = Category::create(['name' => 'Daging & Unggas']);
        $other = Category::create(['name' => 'Bahan Pokok']);
        $target = Item::factory()->create(['name' => 'Ayam Potong Utuh', 'category_id' => $meat->id, 'stock' => 5, 'min_stock' => 30]);
        Item::factory()->create(['name' => 'Ati Ampela Ayam', 'category_id' => $meat->id, 'stock' => 12, 'min_stock' => 5]);
        Item::factory()->create(['name' => 'Telur Ayam', 'category_id' => $other->id, 'stock' => 1, 'min_stock' => 10]);

        $response = $this->getJson("/api/v1/items?search=ayam&category_id={$meat->id}&low_stock=1");

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->assertStringContainsString('search=ayam', $response->json('links.first'));
        $this->assertStringContainsString('low_stock=1', $response->json('links.first'));
    }

    public function test_invalid_query_parameters_return_422(): void
    {
        $this->getJson('/api/v1/items?low_stock=mungkin&category_id=abc')
            ->assertUnprocessable()
            ->assertJsonPath('errors.low_stock.0', 'Filter stok menipis harus bernilai benar atau salah.')
            ->assertJsonPath('errors.category_id.0', 'Kategori harus berupa bilangan bulat.');
    }

    // ---------- GET /items/{item} ----------

    public function test_detail_contains_category_and_10_latest_movements_with_user_name(): void
    {
        $category = Category::create(['name' => 'Daging & Unggas']);
        $item = Item::factory()->create(['category_id' => $category->id]);
        $budi = User::factory()->create(['name' => 'Budi']);

        // 12 transaksi: yang paling baru dicatat 1 menit lalu (stock_after = 12).
        foreach (range(12, 1) as $minutesAgo) {
            $this->recordMovement($item, $budi, $minutesAgo, stockAfter: 13 - $minutesAgo);
        }
        $waste = $this->recordMovement($item, $budi, 0, stockAfter: 11);
        $waste->type = MovementType::Waste;
        $waste->quantity = 1;
        $waste->reason = WasteReason::Expired;
        $waste->save();

        $response = $this->getJson("/api/v1/items/{$item->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.category.name', 'Daging & Unggas')
            ->assertJsonCount(10, 'data.recent_movements')
            ->assertJsonPath('data.recent_movements.0', [
                'id' => $waste->id,
                'type' => 'waste',
                'type_label' => 'Terbuang',
                'quantity' => 1,
                'reason' => 'expired',
                'reason_label' => 'Kedaluwarsa',
                'note' => null,
                'stock_after' => 11,
                'created_at' => $waste->created_at->toIso8601String(),
                'user' => ['id' => $budi->id, 'name' => 'Budi'],
            ])
            ->assertJsonPath('data.recent_movements.1.stock_after', 12)
            ->assertJsonPath('data.recent_movements.9.stock_after', 4);
    }

    public function test_detail_of_soft_deleted_item_returns_404(): void
    {
        $item = Item::factory()->create();
        $item->delete();

        $this->getJson("/api/v1/items/{$item->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.']);
    }

    public function test_detail_of_missing_item_returns_404(): void
    {
        $this->getJson('/api/v1/items/999')->assertNotFound();
    }

    public function test_items_require_token(): void
    {
        // Lupakan pengguna dari setUp(), lalu kirim request tanpa header Authorization.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/items')->assertUnauthorized();
        $this->getJson('/api/v1/items/1')->assertUnauthorized();
    }

    // ---------- Bukti tidak ada N+1 ----------

    public function test_list_uses_the_same_number_of_queries_for_3_or_20_items(): void
    {
        // Setiap bahan punya kategori sendiri (factory membuat kategori baru per bahan).
        Item::factory()->count(3)->create();
        $queriesFor3 = $this->countQueries(fn () => $this->getJson('/api/v1/items')->assertJsonCount(3, 'data'));

        Item::factory()->count(17)->create();
        $queriesFor20 = $this->countQueries(fn () => $this->getJson('/api/v1/items')->assertJsonCount(20, 'data'));

        $this->assertSame($queriesFor3, $queriesFor20);
        // 3 query: hitung total (untuk meta), ambil 20 bahan, ambil semua kategorinya sekaligus.
        $this->assertSame(3, $queriesFor20);
    }

    public function test_detail_uses_the_same_number_of_queries_for_2_or_15_movements(): void
    {
        $fewItem = Item::factory()->create();
        foreach (range(1, 2) as $minutesAgo) {
            $this->recordMovement($fewItem, User::factory()->create(), $minutesAgo, stockAfter: $minutesAgo);
        }

        $manyItem = Item::factory()->create();
        foreach (range(1, 15) as $minutesAgo) {
            // Setiap transaksi dicatat pengguna BERBEDA: tanpa eager load, ini akan
            // memicu 1 query per transaksi untuk mengambil nama pencatat.
            $this->recordMovement($manyItem, User::factory()->create(), $minutesAgo, stockAfter: $minutesAgo);
        }

        $queriesForFew = $this->countQueries(fn () => $this->getJson("/api/v1/items/{$fewItem->id}")
            ->assertJsonCount(2, 'data.recent_movements'));
        $queriesForMany = $this->countQueries(fn () => $this->getJson("/api/v1/items/{$manyItem->id}")
            ->assertJsonCount(10, 'data.recent_movements'));

        $this->assertSame($queriesForFew, $queriesForMany);
        // 4 query: cari bahan, kategorinya, 10 transaksi terakhir, semua pencatatnya sekaligus.
        $this->assertSame(4, $queriesForMany);
    }
}
