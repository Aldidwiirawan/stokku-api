<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_stock_includes_items_at_or_below_minimum(): void
    {
        $below = Item::factory()->create(['stock' => 2, 'min_stock' => 10]);
        $equal = Item::factory()->create(['stock' => 10, 'min_stock' => 10]);
        Item::factory()->create(['stock' => 11, 'min_stock' => 10]);

        $ids = Item::lowStock()->pluck('id')->sort()->values()->all();

        $this->assertSame([$below->id, $equal->id], $ids);
    }

    public function test_search_matches_name_regardless_of_letter_case(): void
    {
        $item = Item::factory()->create(['name' => 'Ayam Potong Utuh']);
        Item::factory()->create(['name' => 'Kecap Manis']);

        foreach (['ayam', 'AYAM', 'Potong', 'utuh'] as $term) {
            $found = Item::search($term)->pluck('id')->all();

            $this->assertSame([$item->id], $found, "Kata kunci '{$term}' harus menemukan Ayam Potong Utuh");
        }
    }

    public function test_search_matches_sku(): void
    {
        $item = Item::factory()->create(['sku' => 'BHN-0042', 'name' => 'Arang Batok']);
        Item::factory()->create(['sku' => 'BHN-0001', 'name' => 'Beras']);

        $this->assertSame([$item->id], Item::search('bhn-0042')->pluck('id')->all());
    }

    public function test_empty_search_term_returns_all_items(): void
    {
        Item::factory()->count(3)->create();

        $this->assertSame(3, Item::search('')->count());
        $this->assertSame(3, Item::search('   ')->count());
        $this->assertSame(3, Item::search(null)->count());
    }

    public function test_search_can_be_combined_with_low_stock(): void
    {
        $lowAyam = Item::factory()->create(['name' => 'Ayam Potong Utuh', 'stock' => 1, 'min_stock' => 5]);
        Item::factory()->create(['name' => 'Ayam Fillet', 'stock' => 50, 'min_stock' => 5]);
        Item::factory()->create(['name' => 'Cabai Rawit', 'stock' => 1, 'min_stock' => 5]);

        $found = Item::search('ayam')->lowStock()->pluck('id')->all();

        $this->assertSame([$lowAyam->id], $found);
    }

    public function test_search_uses_ilike_on_postgresql(): void
    {
        // Koneksi PostgreSQL palsu: host tidak ada dan tidak pernah dihubungi.
        // toSql() hanya menyusun teks SQL, tidak menjalankan query.
        config(['database.connections.fake_pgsql' => [
            'driver' => 'pgsql',
            'host' => 'tidak-ada.invalid',
            'database' => 'fake',
            'username' => 'fake',
            'password' => 'fake',
        ]]);

        $sql = Item::on('fake_pgsql')->search('ayam')->toSql();

        $this->assertStringContainsString('"name"::text ilike ?', $sql);
        $this->assertStringContainsString('"sku"::text ilike ?', $sql);
    }
}
