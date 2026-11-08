<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ItemManageTest extends TestCase
{
    use RefreshDatabase;

    private const SKU_TAKEN = 'SKU sudah digunakan oleh bahan lain, termasuk bahan yang sudah dihapus.';

    private const STOCK_NOT_ALLOWED = 'Stok hanya bisa diubah lewat transaksi.';

    private string $adminToken;

    private string $staffToken;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adminToken = User::factory()->admin()->create()->createToken('HP Admin')->plainTextToken;
        $this->staffToken = User::factory()->create()->createToken('HP Staf')->plainTextToken;
        // Nama sengaja TIDAK ada di CategoryFactory::NAMES, supaya tidak bentrok dengan
        // kategori acak yang dibuat Item::factory() (index unik lower(name), T-205).
        $this->category = Category::create(['name' => 'Kategori Uji Bahan']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'BHN-0100',
            'name' => 'Jeruk Nipis',
            'category_id' => $this->category->id,
            'unit' => 'kg',
            'price' => '18000',
            'min_stock' => '3',
            'description' => 'Untuk sambal dan perasan ikan bakar',
        ], $overrides);
    }

    /**
     * Kirim multipart (seperti aplikasi saat ada foto). Header Accept supaya error berupa JSON.
     *
     * @param  array<string, mixed>  $data
     */
    private function postMultipart(string $url, array $data, string $token): TestResponse
    {
        return $this->withToken($token)->post($url, $data, ['Accept' => 'application/json']);
    }

    // ---------- POST /items ----------

    public function test_admin_can_create_item_without_photo_and_stock_starts_at_zero(): void
    {
        $response = $this->withToken($this->adminToken)->postJson('/api/v1/items', $this->validData());

        $response->assertCreated()
            ->assertJsonPath('data.sku', 'BHN-0100')
            ->assertJsonPath('data.name', 'Jeruk Nipis')
            ->assertJsonPath('data.price', '18000.00')
            ->assertJsonPath('data.min_stock', 3)
            ->assertJsonPath('data.stock', 0)
            ->assertJsonPath('data.photo_url', null)
            ->assertJsonPath('data.category.name', 'Kategori Uji Bahan');
    }

    public function test_admin_can_create_item_with_photo_multipart(): void
    {
        $response = $this->postMultipart('/api/v1/items', $this->validData([
            'photo' => UploadedFile::fake()->image('jeruk.jpg', 600, 600),
        ]), $this->adminToken);

        $response->assertCreated();
        $path = Item::first()->photo_path;
        $this->assertStringStartsWith('photos/items/', $path);
        Storage::disk('public')->assertExists($path);
        $response->assertJsonPath('data.photo_url', config('app.url').'/storage/'.$path);
    }

    public function test_sku_is_stored_in_uppercase(): void
    {
        $this->withToken($this->adminToken)
            ->postJson('/api/v1/items', $this->validData(['sku' => '  bhn-0100 ']))
            ->assertCreated()
            ->assertJsonPath('data.sku', 'BHN-0100');

        $this->assertDatabaseHas('items', ['sku' => 'BHN-0100']);
    }

    public function test_duplicate_sku_in_different_case_is_rejected(): void
    {
        Item::factory()->create(['sku' => 'BHN-0100']);

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/items', $this->validData(['sku' => 'bhn-0100']))
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Data tidak valid.',
                'errors' => ['sku' => [self::SKU_TAKEN]],
            ]);
    }

    public function test_sku_of_soft_deleted_item_cannot_be_reused(): void
    {
        Item::factory()->create(['sku' => 'BHN-0100'])->delete();

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/items', $this->validData(['sku' => 'Bhn-0100']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.sku.0', self::SKU_TAKEN);
    }

    public function test_sending_stock_field_is_rejected_even_if_zero(): void
    {
        foreach (['25', '0'] as $stock) {
            $this->withToken($this->adminToken)
                ->postJson('/api/v1/items', $this->validData(['stock' => $stock]))
                ->assertUnprocessable()
                ->assertExactJson([
                    'message' => 'Data tidak valid.',
                    'errors' => ['stock' => [self::STOCK_NOT_ALLOWED]],
                ]);
        }

        $this->assertSame(0, Item::count());
    }

    public function test_required_fields_and_number_rules(): void
    {
        $this->withToken($this->adminToken)
            ->postJson('/api/v1/items', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.sku.0', 'SKU wajib diisi.')
            ->assertJsonPath('errors.name.0', 'Nama wajib diisi.')
            ->assertJsonPath('errors.category_id.0', 'Kategori wajib diisi.')
            ->assertJsonPath('errors.unit.0', 'Satuan wajib diisi.')
            ->assertJsonPath('errors.price.0', 'Harga wajib diisi.')
            ->assertJsonPath('errors.min_stock.0', 'Stok minimum wajib diisi.');

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/items', $this->validData([
                'price' => '-1',
                'min_stock' => '-2',
                'category_id' => 999,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.price.0', 'Harga minimal 0.')
            ->assertJsonPath('errors.min_stock.0', 'Stok minimum minimal 0.')
            ->assertJsonPath('errors.category_id.0', 'Kategori yang dipilih tidak ditemukan.');

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/items', $this->validData(['price' => '18000.555']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.price.0', 'Harga harus memiliki 0-2 angka di belakang koma.');
    }

    public function test_price_maximum_is_999_999_999(): void
    {
        $this->withToken($this->adminToken)
            ->postJson('/api/v1/items', $this->validData(['sku' => 'BHN-0101', 'price' => '999999999']))
            ->assertCreated()
            ->assertJsonPath('data.price', '999999999.00');

        foreach (['1000000000', '999999999.01', '99999999999'] as $price) {
            $this->withToken($this->adminToken)
                ->postJson('/api/v1/items', $this->validData(['sku' => 'BHN-0102', 'price' => $price]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.price.0', 'Harga maksimal 999999999.');
        }

        $this->assertSame(1, Item::count());
    }

    public function test_photo_must_be_an_image_of_max_2_mb(): void
    {
        $this->postMultipart('/api/v1/items', $this->validData([
            'photo' => UploadedFile::fake()->image('besar.jpg')->size(2049),
        ]), $this->adminToken)
            ->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Foto maksimal 2048 kilobyte.');
    }

    public function test_staff_cannot_create_item(): void
    {
        $this->withToken($this->staffToken)
            ->postJson('/api/v1/items', $this->validData())
            ->assertForbidden()
            ->assertExactJson(['message' => 'Akses ditolak.']);

        $this->assertSame(0, Item::count());
    }

    public function test_create_requires_token(): void
    {
        $this->postJson('/api/v1/items', $this->validData())->assertUnauthorized();
    }

    // ---------- PUT /items/{item} (POST + _method=PUT) ----------

    public function test_admin_can_update_item_with_new_photo_using_method_spoofing(): void
    {
        $item = Item::factory()->create(['sku' => 'BHN-0100', 'stock' => 7]);
        $oldPath = UploadedFile::fake()->image('lama.jpg')->store('photos/items', 'public');
        $item->photo_path = $oldPath;
        $item->save();

        $response = $this->postMultipart("/api/v1/items/{$item->id}", $this->validData([
            '_method' => 'PUT',
            'name' => 'Jeruk Nipis Lokal',
            'price' => '20000.50',
            'photo' => UploadedFile::fake()->image('baru.png'),
        ]), $this->adminToken);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Jeruk Nipis Lokal')
            ->assertJsonPath('data.price', '20000.50')
            ->assertJsonPath('data.stock', 7); // stok tidak berubah

        $newPath = $item->fresh()->photo_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_update_without_photo_keeps_existing_photo(): void
    {
        $item = Item::factory()->create(['sku' => 'BHN-0100']);
        $path = UploadedFile::fake()->image('tetap.jpg')->store('photos/items', 'public');
        $item->photo_path = $path;
        $item->save();

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/items/{$item->id}", $this->validData(['name' => 'Nama Baru']))
            ->assertOk();

        $this->assertSame($path, $item->fresh()->photo_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_update_can_keep_its_own_sku_written_in_lowercase(): void
    {
        $item = Item::factory()->create(['sku' => 'BHN-0100']);

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/items/{$item->id}", $this->validData(['sku' => 'bhn-0100']))
            ->assertOk()
            ->assertJsonPath('data.sku', 'BHN-0100');
    }

    public function test_update_to_another_items_sku_is_rejected(): void
    {
        Item::factory()->create(['sku' => 'BHN-0001']);
        $item = Item::factory()->create(['sku' => 'BHN-0100']);

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/items/{$item->id}", $this->validData(['sku' => 'bhn-0001']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.sku.0', self::SKU_TAKEN);
    }

    public function test_update_with_stock_field_is_rejected_and_stock_unchanged(): void
    {
        $item = Item::factory()->create(['sku' => 'BHN-0100', 'stock' => 7]);

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/items/{$item->id}", $this->validData(['stock' => '100']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.stock.0', self::STOCK_NOT_ALLOWED);

        $this->assertSame(7, $item->fresh()->stock);
    }

    public function test_update_soft_deleted_or_missing_item_returns_404(): void
    {
        $item = Item::factory()->create(['sku' => 'BHN-0100']);
        $item->delete();

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/items/{$item->id}", $this->validData())
            ->assertNotFound();

        $this->withToken($this->adminToken)
            ->putJson('/api/v1/items/999', $this->validData())
            ->assertNotFound();
    }

    public function test_staff_cannot_update_item(): void
    {
        $item = Item::factory()->create(['name' => 'Asli']);

        $this->withToken($this->staffToken)
            ->putJson("/api/v1/items/{$item->id}", $this->validData())
            ->assertForbidden();

        $this->assertSame('Asli', $item->fresh()->name);
    }

    // ---------- DELETE /items/{item} ----------

    public function test_admin_delete_is_soft_delete_and_keeps_photo_and_history(): void
    {
        $item = Item::factory()->create();
        $path = UploadedFile::fake()->image('foto.jpg')->store('photos/items', 'public');
        $item->photo_path = $path;
        $item->save();

        $movement = new StockMovement;
        $movement->fill(['item_id' => $item->id, 'type' => MovementType::In, 'quantity' => 5]);
        $movement->user_id = User::factory()->create()->id;
        $movement->stock_after = 5;
        $movement->save();

        $this->withToken($this->adminToken)
            ->deleteJson("/api/v1/items/{$item->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($item);
        Storage::disk('public')->assertExists($path);              // foto tetap ada
        $this->assertSame(1, StockMovement::where('item_id', $item->id)->count()); // riwayat utuh
        $this->withToken($this->adminToken)->getJson("/api/v1/items/{$item->id}")->assertNotFound();
    }

    public function test_staff_cannot_delete_item(): void
    {
        $item = Item::factory()->create();

        $this->withToken($this->staffToken)
            ->deleteJson("/api/v1/items/{$item->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($item);
    }

    // ---------- Simpan bersamaan: index unik database → 422, bukan 500 ----------

    /**
     * Sisipkan bahan langsung ke tabel (melewati validasi), seperti Admin lain
     * yang menyimpan SKU yang sama di saat bersamaan.
     */
    private function insertRawItem(string $sku): void
    {
        DB::table('items')->insert([
            'sku' => $sku, 'name' => 'Disimpan Admin Lain', 'category_id' => $this->category->id,
            'unit' => 'kg', 'stock' => 0, 'min_stock' => 0, 'price' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_simultaneous_create_with_same_sku_returns_422_and_removes_uploaded_photo(): void
    {
        Item::creating(function (Item $item) {
            if ($item->sku === 'BHN-0100') {
                $this->insertRawItem('BHN-0100');
            }
        });

        $this->postMultipart('/api/v1/items', $this->validData([
            'photo' => UploadedFile::fake()->image('jeruk.jpg'),
        ]), $this->adminToken)
            ->assertUnprocessable()
            ->assertJsonPath('errors.sku.0', self::SKU_TAKEN);

        $this->assertSame(1, Item::count()); // hanya milik "Admin lain"
        $this->assertSame([], Storage::disk('public')->allFiles('photos/items')); // tidak ada file sampah
    }

    public function test_simultaneous_update_to_same_sku_returns_422(): void
    {
        $item = Item::factory()->create(['sku' => 'BHN-0050']);

        Item::updating(function (Item $updated) {
            if ($updated->sku === 'BHN-0100') {
                $this->insertRawItem('BHN-0100');
            }
        });

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/items/{$item->id}", $this->validData())
            ->assertUnprocessable()
            ->assertJsonPath('errors.sku.0', self::SKU_TAKEN);

        $this->assertSame('BHN-0050', $item->fresh()->sku);
    }

    // ---------- Model ----------

    public function test_model_stores_sku_in_uppercase_from_any_source(): void
    {
        $item = Item::factory()->create(['sku' => ' bhn-0777 ']);

        $this->assertSame('BHN-0777', $item->sku);
        $this->assertDatabaseHas('items', ['sku' => 'BHN-0777']);
    }
}
