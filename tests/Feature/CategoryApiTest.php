<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private const IN_USE_MESSAGE = 'Kategori tidak bisa dihapus karena masih dipakai oleh bahan, termasuk bahan yang sudah dihapus. Riwayat transaksi bahan tersebut harus tetap tersimpan.';

    private string $adminToken;

    private string $staffToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = User::factory()->admin()->create()->createToken('HP Admin')->plainTextToken;
        $this->staffToken = User::factory()->create()->createToken('HP Staf')->plainTextToken;
    }

    // ---------- GET /categories ----------

    public function test_staff_can_list_categories_sorted_by_name_with_active_items_count(): void
    {
        $spices = Category::create(['name' => 'Bumbu & Rempah']);
        Category::create(['name' => 'Air & Minuman']);
        Item::factory()->count(2)->create(['category_id' => $spices->id]);
        Item::factory()->create(['category_id' => $spices->id])->delete(); // tidak dihitung

        $this->withToken($this->staffToken)
            ->getJson('/api/v1/categories')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['id' => 2, 'name' => 'Air & Minuman', 'items_count' => 0],
                    ['id' => 1, 'name' => 'Bumbu & Rempah', 'items_count' => 2],
                ],
            ]);
    }

    public function test_listing_requires_token(): void
    {
        $this->getJson('/api/v1/categories')->assertUnauthorized();
    }

    // ---------- POST /categories ----------

    public function test_admin_can_create_category(): void
    {
        $this->withToken($this->adminToken)
            ->postJson('/api/v1/categories', ['name' => 'Bahan Kering'])
            ->assertCreated()
            ->assertExactJson(['data' => ['id' => 1, 'name' => 'Bahan Kering', 'items_count' => 0]]);

        $this->assertDatabaseHas('categories', ['name' => 'Bahan Kering']);
    }

    public function test_spaces_around_name_are_removed(): void
    {
        $this->withToken($this->adminToken)
            ->postJson('/api/v1/categories', ['name' => '   Bahan Kering  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Bahan Kering');
    }

    public function test_name_must_be_unique_ignoring_case_and_spaces(): void
    {
        Category::create(['name' => 'Bumbu & Rempah']);

        foreach (['Bumbu & Rempah', ' bumbu & rempah ', 'BUMBU & REMPAH'] as $name) {
            $this->withToken($this->adminToken)
                ->postJson('/api/v1/categories', ['name' => $name])
                ->assertUnprocessable()
                ->assertExactJson([
                    'message' => 'Data tidak valid.',
                    'errors' => ['name' => ['Nama kategori sudah digunakan.']],
                ]);
        }

        $this->assertSame(1, Category::count());
    }

    public function test_name_is_required_and_max_100_characters(): void
    {
        $this->withToken($this->adminToken)
            ->postJson('/api/v1/categories', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Nama wajib diisi.');

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/categories', ['name' => str_repeat('a', 101)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Nama maksimal 100 karakter.');
    }

    public function test_staff_cannot_create_category(): void
    {
        $this->withToken($this->staffToken)
            ->postJson('/api/v1/categories', ['name' => 'Bahan Kering'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Akses ditolak.']);

        $this->assertSame(0, Category::count());
    }

    // ---------- PUT /categories/{category} ----------

    public function test_admin_can_rename_category(): void
    {
        $category = Category::create(['name' => 'Bahan Kering']);
        Item::factory()->create(['category_id' => $category->id]);

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/categories/{$category->id}", ['name' => 'Bahan Kering & Tepung'])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $category->id, 'name' => 'Bahan Kering & Tepung', 'items_count' => 1]]);
    }

    public function test_admin_can_change_letter_case_of_its_own_name(): void
    {
        $category = Category::create(['name' => 'bumbu & rempah']);

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/categories/{$category->id}", ['name' => 'Bumbu & Rempah'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Bumbu & Rempah');
    }

    public function test_cannot_rename_to_another_categorys_name_in_different_case(): void
    {
        Category::create(['name' => 'Bumbu & Rempah']);
        $other = Category::create(['name' => 'Bahan Kering']);

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/categories/{$other->id}", ['name' => ' bumbu & rempah '])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Nama kategori sudah digunakan.');

        $this->assertSame('Bahan Kering', $other->fresh()->name);
    }

    public function test_updating_missing_category_returns_404(): void
    {
        $this->withToken($this->adminToken)
            ->putJson('/api/v1/categories/999', ['name' => 'Apa Saja'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.']);
    }

    public function test_staff_cannot_rename_category(): void
    {
        $category = Category::create(['name' => 'Bahan Kering']);

        $this->withToken($this->staffToken)
            ->putJson("/api/v1/categories/{$category->id}", ['name' => 'Diganti Staf'])
            ->assertForbidden();

        $this->assertSame('Bahan Kering', $category->fresh()->name);
    }

    // ---------- DELETE /categories/{category} ----------

    public function test_admin_can_delete_unused_category(): void
    {
        $category = Category::create(['name' => 'Bahan Kering']);

        $this->withToken($this->adminToken)
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertNoContent();

        $this->assertModelMissing($category);
    }

    public function test_category_used_by_item_cannot_be_deleted(): void
    {
        $category = Category::create(['name' => 'Daging & Unggas']);
        Item::factory()->create(['category_id' => $category->id]);

        $this->withToken($this->adminToken)
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertConflict()
            ->assertExactJson(['message' => self::IN_USE_MESSAGE]);

        $this->assertModelExists($category);
    }

    public function test_category_with_only_soft_deleted_items_still_returns_409(): void
    {
        $category = Category::create(['name' => 'Daging & Unggas']);
        Item::factory()->create(['category_id' => $category->id])->delete();

        // items_count = 0 (tampak kosong), tapi tetap tidak boleh dihapus.
        $this->withToken($this->adminToken)
            ->getJson('/api/v1/categories')
            ->assertJsonPath('data.0.items_count', 0);

        $this->withToken($this->adminToken)
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertConflict()
            ->assertExactJson(['message' => self::IN_USE_MESSAGE]);

        $this->assertModelExists($category);
    }

    public function test_staff_cannot_delete_category(): void
    {
        $category = Category::create(['name' => 'Bahan Kering']);

        $this->withToken($this->staffToken)
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertForbidden();

        $this->assertModelExists($category);
    }

    // ---------- Index unik lower(name) di database ----------

    /**
     * Sisipkan nama kembar langsung ke tabel (melewati model & validasi),
     * seperti Admin lain yang menyimpan di saat yang sama.
     */
    private function insertRawCategory(string $name): void
    {
        DB::table('categories')->insert(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_database_index_rejects_names_that_differ_only_in_case(): void
    {
        $this->insertRawCategory('Bumbu & Rempah');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertRawCategory('BUMBU & REMPAH');
    }

    public function test_simultaneous_create_with_same_name_returns_422_not_500(): void
    {
        // Validasi sudah lolos (belum ada "Bahan Kering"), lalu tepat sebelum disimpan,
        // "Admin lain" lebih dulu menyimpan "BAHAN KERING".
        Category::creating(function (Category $category) {
            if ($category->name === 'Bahan Kering') {
                $this->insertRawCategory('BAHAN KERING');
            }
        });

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/categories', ['name' => 'Bahan Kering'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Data tidak valid.',
                'errors' => ['name' => ['Nama kategori sudah digunakan.']],
            ]);

        $this->assertSame(1, Category::count());
    }

    public function test_simultaneous_rename_to_same_name_returns_422_not_500(): void
    {
        $category = Category::create(['name' => 'Bahan Lama']);

        Category::updating(function (Category $updated) {
            if ($updated->name === 'Bahan Kering') {
                $this->insertRawCategory('bahan kering');
            }
        });

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/categories/{$category->id}", ['name' => 'Bahan Kering'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Nama kategori sudah digunakan.');

        $this->assertSame('Bahan Lama', $category->fresh()->name);
    }

    // ---------- Model ----------

    public function test_model_trims_name_from_any_source(): void
    {
        $category = Category::create(['name' => "  Minuman \n"]);

        $this->assertSame('Minuman', $category->name);
        $this->assertDatabaseHas('categories', ['name' => 'Minuman']);
    }
}
