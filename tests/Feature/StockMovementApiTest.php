<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockMovementApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/stock-movements';

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Gudang']);
        $this->staff = User::factory()->create(['name' => 'Staf Dapur']);
    }

    /**
     * Item dengan stok tertentu (stok diisi langsung khusus untuk persiapan test).
     */
    private function itemWithStock(int $stock, int $minStock = 5, string $unit = 'kg'): Item
    {
        return Item::factory()->create(['stock' => $stock, 'min_stock' => $minStock, 'unit' => $unit]);
    }

    /**
     * Riwayat dengan waktu tertentu, untuk test filter & daftar.
     */
    private function movementAt(Item $item, User $user, string $utcTime, MovementType $type = MovementType::In): StockMovement
    {
        $movement = new StockMovement;
        $movement->fill(['item_id' => $item->id, 'type' => $type, 'quantity' => 1]);
        $movement->user_id = $user->id;
        $movement->stock_after = 1;
        $movement->created_at = Carbon::parse($utcTime, 'UTC');
        $movement->save();

        return $movement;
    }

    // =====================================================================
    // POST /stock-movements
    // =====================================================================

    public function test_stock_in_increases_stock_and_returns_201_with_details(): void
    {
        $item = $this->itemWithStock(10, minStock: 5);
        Sanctum::actingAs($this->staff);

        $response = $this->postJson(self::URL, [
            'item_id' => $item->id, 'type' => 'in', 'quantity' => 15, 'note' => 'Kiriman supplier',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'in')
            ->assertJsonPath('data.type_label', 'Masuk')
            ->assertJsonPath('data.quantity', 15)
            ->assertJsonPath('data.stock_after', 25)
            ->assertJsonPath('data.note', 'Kiriman supplier')
            ->assertJsonPath('data.user.name', 'Staf Dapur')
            ->assertJsonPath('data.item.stock', 25)
            ->assertJsonPath('data.item.is_low_stock', false);

        $this->assertSame(25, $item->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id, 'user_id' => $this->staff->id, 'type' => 'in', 'quantity' => 15, 'stock_after' => 25,
        ]);
    }

    public function test_stock_out_decreases_stock_and_flags_low_stock(): void
    {
        $item = $this->itemWithStock(10, minStock: 5);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'out', 'quantity' => 6])
            ->assertCreated()
            ->assertJsonPath('data.quantity', 6)
            ->assertJsonPath('data.stock_after', 4)
            ->assertJsonPath('data.item.is_low_stock', true);

        $this->assertSame(4, $item->fresh()->stock);
    }

    public function test_stock_out_of_entire_stock_is_allowed(): void
    {
        $item = $this->itemWithStock(7);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'out', 'quantity' => 7])
            ->assertCreated()
            ->assertJsonPath('data.stock_after', 0);
    }

    public function test_stock_out_more_than_stock_returns_422_and_changes_nothing(): void
    {
        $item = $this->itemWithStock(5, unit: 'ekor');
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'out', 'quantity' => 6])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Data tidak valid.',
                'errors' => ['quantity' => ['Jumlah melebihi stok saat ini (5 ekor).']],
            ]);

        $this->assertSame(5, $item->fresh()->stock);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_two_consecutive_stock_outs_never_make_stock_negative(): void
    {
        // Wajib (TASKS T-208): yang pertama berhasil, yang kedua 422, stok akhir ≥ 0.
        $item = $this->itemWithStock(10);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'out', 'quantity' => 7])
            ->assertCreated()
            ->assertJsonPath('data.stock_after', 3);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'out', 'quantity' => 7])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', 'Jumlah melebihi stok saat ini (3 kg).');

        $this->assertSame(3, $item->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, $item->fresh()->stock);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(0, StockMovement::where('stock_after', '<', 0)->count());
    }

    public function test_staff_can_record_waste_with_reason(): void
    {
        $item = $this->itemWithStock(10);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, [
            'item_id' => $item->id, 'type' => 'waste', 'quantity' => 2, 'reason' => 'cooking_error',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Terbuang')
            ->assertJsonPath('data.reason', 'cooking_error')
            ->assertJsonPath('data.reason_label', 'Gosong/Salah Olah')
            ->assertJsonPath('data.stock_after', 8);
    }

    public function test_waste_without_reason_returns_422(): void
    {
        $item = $this->itemWithStock(10);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'waste', 'quantity' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Alasan wajib diisi jika jenis transaksi adalah terbuang.');

        $this->assertSame(10, $item->fresh()->stock);
    }

    public function test_waste_with_reason_other_requires_note(): void
    {
        $item = $this->itemWithStock(10);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'waste', 'quantity' => 1, 'reason' => 'other'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.note.0', 'Catatan wajib diisi jika alasan adalah lainnya.');

        $this->postJson(self::URL, [
            'item_id' => $item->id, 'type' => 'waste', 'quantity' => 1, 'reason' => 'other', 'note' => 'Jatuh saat dipindah',
        ])->assertCreated();
    }

    public function test_waste_more_than_stock_returns_422(): void
    {
        $item = $this->itemWithStock(3);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'waste', 'quantity' => 4, 'reason' => 'expired'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', 'Jumlah melebihi stok saat ini (3 kg).');
    }

    public function test_reason_is_not_allowed_for_other_types(): void
    {
        $item = $this->itemWithStock(10);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'out', 'quantity' => 1, 'reason' => 'expired'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Alasan hanya boleh diisi jika jenis transaksi adalah terbuang.');
    }

    public function test_admin_adjust_stores_difference_and_sets_physical_stock(): void
    {
        $item = $this->itemWithStock(10);
        Sanctum::actingAs($this->admin);

        // Stok fisik lebih sedikit: selisih -3.
        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'adjust', 'quantity' => 7, 'note' => 'Stock opname'])
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Penyesuaian')
            ->assertJsonPath('data.quantity', -3)
            ->assertJsonPath('data.stock_after', 7);

        // Stok fisik lebih banyak: selisih +5.
        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'adjust', 'quantity' => 12])
            ->assertCreated()
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.stock_after', 12);

        // Stok fisik 0 (habis) boleh.
        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'adjust', 'quantity' => 0])
            ->assertCreated()
            ->assertJsonPath('data.quantity', -12)
            ->assertJsonPath('data.stock_after', 0);

        $this->assertSame(0, $item->fresh()->stock);
    }

    public function test_adjust_with_same_physical_stock_returns_422(): void
    {
        $item = $this->itemWithStock(10);
        Sanctum::actingAs($this->admin);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'adjust', 'quantity' => 10])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Data tidak valid.',
                'errors' => ['quantity' => ['Tidak ada selisih stok.']],
            ]);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_staff_cannot_adjust_stock(): void
    {
        $item = $this->itemWithStock(10);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'adjust', 'quantity' => 7])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Penyesuaian stok hanya bisa dilakukan Admin.']);

        $this->assertSame(10, $item->fresh()->stock);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_input_validation(): void
    {
        $deleted = $this->itemWithStock(10);
        $deleted->delete();
        $item = $this->itemWithStock(10);
        Sanctum::actingAs($this->staff);

        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.item_id.0', 'Bahan wajib diisi.')
            ->assertJsonPath('errors.type.0', 'Jenis transaksi wajib diisi.')
            ->assertJsonPath('errors.quantity.0', 'Jumlah wajib diisi.');

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'in', 'quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', 'Jumlah minimal 1.');

        $this->postJson(self::URL, ['item_id' => $item->id, 'type' => 'hilang', 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('errors.type.0', 'Jenis transaksi yang dipilih tidak valid.');

        $this->postJson(self::URL, ['item_id' => $deleted->id, 'type' => 'in', 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('errors.item_id.0', 'Bahan yang dipilih tidak ditemukan.');
    }

    public function test_requires_token(): void
    {
        $this->postJson(self::URL, [])->assertUnauthorized();
        $this->getJson(self::URL)->assertUnauthorized();
    }

    // =====================================================================
    // GET /stock-movements
    // =====================================================================

    public function test_admin_sees_all_and_staff_sees_only_own_movements(): void
    {
        $item = $this->itemWithStock(10);
        $otherStaff = User::factory()->create();
        $this->movementAt($item, $this->staff, '2026-11-08 01:00:00');
        $this->movementAt($item, $otherStaff, '2026-11-08 02:00:00');
        $this->movementAt($item, $this->admin, '2026-11-08 03:00:00');

        Sanctum::actingAs($this->admin);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(3, 'data');

        Sanctum::actingAs($this->staff);
        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $this->staff->id);
    }

    public function test_list_is_newest_first_with_item_and_user(): void
    {
        $item = $this->itemWithStock(10);
        $old = $this->movementAt($item, $this->admin, '2026-11-01 08:00:00');
        $new = $this->movementAt($item, $this->admin, '2026-11-08 08:00:00');
        Sanctum::actingAs($this->admin);

        $this->getJson(self::URL)
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonPath('data.0.item.name', $item->name)
            ->assertJsonPath('data.0.user.name', 'Admin Gudang')
            ->assertJsonPath('data.0.created_at', '2026-11-08T08:00:00+00:00')
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_filter_by_type_and_item(): void
    {
        $chicken = $this->itemWithStock(10);
        $rice = $this->itemWithStock(10);
        $this->movementAt($chicken, $this->admin, '2026-11-08 01:00:00', MovementType::In);
        $this->movementAt($chicken, $this->admin, '2026-11-08 02:00:00', MovementType::Out);
        $this->movementAt($rice, $this->admin, '2026-11-08 03:00:00', MovementType::Out);
        Sanctum::actingAs($this->admin);

        $this->getJson(self::URL.'?type=out')->assertJsonCount(2, 'data');
        $this->getJson(self::URL."?item_id={$chicken->id}")->assertJsonCount(2, 'data');
        $this->getJson(self::URL."?type=out&item_id={$chicken->id}")->assertJsonCount(1, 'data');
    }

    public function test_date_filter_uses_jakarta_dates(): void
    {
        // Wajib (TASKS T-208): 2026-11-08 23:30 UTC = 9 Nov 06:30 WIB.
        $item = $this->itemWithStock(10);
        $lateNight = $this->movementAt($item, $this->admin, '2026-11-08 23:30:00');
        Sanctum::actingAs($this->admin);

        $this->getJson(self::URL.'?from=2026-11-09&to=2026-11-09')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lateNight->id);

        $this->getJson(self::URL.'?from=2026-11-08&to=2026-11-08')
            ->assertJsonCount(0, 'data');
    }

    public function test_date_filter_boundaries_in_jakarta_time(): void
    {
        $item = $this->itemWithStock(10);
        // 9 Nov WIB = 8 Nov 17:00:00 UTC s.d. 9 Nov 16:59:59 UTC.
        $this->movementAt($item, $this->admin, '2026-11-08 16:59:59'); // 8 Nov 23:59:59 WIB → tidak
        $start = $this->movementAt($item, $this->admin, '2026-11-08 17:00:00'); // 9 Nov 00:00:00 WIB → ya
        $end = $this->movementAt($item, $this->admin, '2026-11-09 16:59:59'); // 9 Nov 23:59:59 WIB → ya
        $this->movementAt($item, $this->admin, '2026-11-09 17:00:00'); // 10 Nov 00:00:00 WIB → tidak
        Sanctum::actingAs($this->admin);

        $ids = collect($this->getJson(self::URL.'?from=2026-11-09&to=2026-11-09')->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$start->id, $end->id], $ids);
    }

    public function test_from_after_to_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson(self::URL.'?from=2026-11-10&to=2026-11-09')
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Data tidak valid.',
                'errors' => ['to' => ['Tanggal akhir tidak boleh sebelum tanggal mulai.']],
            ]);
    }

    public function test_invalid_filter_values_return_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson(self::URL.'?from=09-11-2026&type=hilang')
            ->assertUnprocessable()
            ->assertJsonPath('errors.from.0', 'Tanggal mulai harus berformat Y-m-d.')
            ->assertJsonPath('errors.type.0', 'Jenis transaksi yang dipilih tidak valid.');
    }

    public function test_history_still_shows_soft_deleted_item(): void
    {
        $item = $this->itemWithStock(10);
        $this->movementAt($item, $this->admin, '2026-11-08 01:00:00');
        $item->delete();
        Sanctum::actingAs($this->admin);

        $this->getJson(self::URL)->assertJsonPath('data.0.item.name', $item->name);
    }

    public function test_list_uses_the_same_number_of_queries_for_few_or_many_movements(): void
    {
        Sanctum::actingAs($this->admin);
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URL)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // Setiap transaksi: bahan dan pencatat berbeda.
        foreach (range(1, 2) as $i) {
            $this->movementAt($this->itemWithStock(1), User::factory()->create(), "2026-11-08 0{$i}:00:00");
        }
        $few = $countQueries();

        foreach (range(1, 18) as $i) {
            $this->movementAt($this->itemWithStock(1), User::factory()->create(), '2026-11-07 10:00:00');
        }
        $many = $countQueries();

        $this->assertSame($few, $many);
        // 4 query: hitung total, ambil transaksi, ambil bahannya, ambil pencatatnya.
        $this->assertSame(4, $many);
    }
}
