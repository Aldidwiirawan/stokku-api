<?php

namespace Database\Seeders;

use App\Enums\MovementType;
use App\Enums\WasteReason;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockMovementSeeder extends Seeder
{
    /**
     * Buat 40 transaksi contoh dan sesuaikan stok bahan.
     * Hanya berjalan jika tabel stock_movements masih kosong.
     */
    public function run(): void
    {
        if (StockMovement::exists()) {
            $this->command?->warn('Tabel stock_movements sudah berisi data, transaksi contoh dilewati.');

            return;
        }

        $admin = User::where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();
        $staff = User::where('email', UserSeeder::STAFF_EMAIL)->firstOrFail();

        // Satu transaksi database untuk semuanya: kalau ada yang gagal di tengah,
        // semua dibatalkan. Tanpa ini, tabel bisa terisi setengah, lalu run berikutnya
        // akan melewati seeder ini karena tabel sudah tidak kosong.
        DB::transaction(function () use ($admin, $staff) {
            foreach ($this->plan() as $entry) {
                $user = $entry['by'] === 'admin' ? $admin : $staff;
                $this->record($entry, $user);
            }
        });
    }

    /**
     * Daftar transaksi contoh, urut dari yang paling lama.
     * Untuk `adjust`, `quantity` berarti stok fisik hasil hitung (sama seperti di API).
     *
     * @return list<array{sku: string, type: MovementType, quantity: int, reason?: WasteReason, note?: string, by: string, at: Carbon}>
     */
    private function plan(): array
    {
        $plan = [];

        // 1–30: stok awal setiap bahan datang dari supplier, 7 hari lalu.
        $firstDay = now()->subDays(7)->setTime(8, 0);
        foreach (ItemSeeder::ITEMS as $index => $item) {
            $plan[] = [
                'sku' => $item['sku'],
                'type' => MovementType::In,
                'quantity' => $item['initial_stock'],
                'note' => 'Stok awal dari supplier',
                'by' => 'staf',
                'at' => $firstDay->copy()->addMinutes($index),
            ];
        }

        // 31–40: aktivitas dapur beberapa hari terakhir, termasuk hari ini.
        $activities = [
            ['sku' => 'BHN-0017', 'type' => MovementType::Out, 'quantity' => 2, 'by' => 'staf', 'at' => now()->subDays(5)->setTime(10, 0)],
            ['sku' => 'BHN-0002', 'type' => MovementType::Waste, 'quantity' => 3, 'reason' => WasteReason::Expired, 'by' => 'staf', 'at' => now()->subDays(4)->setTime(14, 0)],
            ['sku' => 'BHN-0006', 'type' => MovementType::Out, 'quantity' => 6, 'by' => 'staf', 'at' => now()->subDays(3)->setTime(9, 0)],
            ['sku' => 'BHN-0016', 'type' => MovementType::Waste, 'quantity' => 4, 'reason' => WasteReason::Damaged, 'by' => 'staf', 'at' => now()->subDays(2)->setTime(16, 0)],
            ['sku' => 'BHN-0015', 'type' => MovementType::Waste, 'quantity' => 12, 'reason' => WasteReason::Other, 'note' => 'Layu karena kulkas sempat mati', 'by' => 'staf', 'at' => now()->subDays(1)->setTime(15, 0)],
            ['sku' => 'BHN-0008', 'type' => MovementType::Adjust, 'quantity' => 13, 'note' => 'Stock opname mingguan', 'by' => 'admin', 'at' => now()->subDays(1)->setTime(20, 0)],
            ['sku' => 'BHN-0018', 'type' => MovementType::Adjust, 'quantity' => 26, 'note' => 'Stock opname mingguan', 'by' => 'admin', 'at' => now()->subDays(1)->setTime(20, 10)],
            ['sku' => 'BHN-0024', 'type' => MovementType::In, 'quantity' => 6, 'by' => 'staf', 'at' => now()->subMinutes(30)],
            ['sku' => 'BHN-0001', 'type' => MovementType::Out, 'quantity' => 35, 'by' => 'staf', 'at' => now()->subMinutes(20)],
            ['sku' => 'BHN-0001', 'type' => MovementType::Waste, 'quantity' => 2, 'reason' => WasteReason::CookingError, 'by' => 'staf', 'at' => now()->subMinutes(10)],
        ];

        return array_merge($plan, $activities);
    }

    /**
     * Catat satu transaksi: hitung stok baru, simpan ke bahan, lalu simpan riwayatnya.
     *
     * @param  array{sku: string, type: MovementType, quantity: int, reason?: WasteReason, note?: string, by: string, at: Carbon}  $entry
     */
    private function record(array $entry, User $user): void
    {
        // lockForUpdate(): baris bahan dikunci sampai transaksi selesai,
        // supaya tidak ada proses lain yang mengubah stok di saat yang sama.
        $item = Item::where('sku', $entry['sku'])->lockForUpdate()->firstOrFail();

        $stockBefore = $item->stock;
        $type = $entry['type'];

        if ($type === MovementType::Adjust) {
            $stockAfter = $entry['quantity'];               // stok fisik
            $quantity = $stockAfter - $stockBefore;         // selisih, bisa minus
        } else {
            $quantity = $entry['quantity'];
            $stockAfter = $type === MovementType::In
                ? $stockBefore + $quantity
                : $stockBefore - $quantity;                 // out dan waste
        }

        if ($stockAfter < 0) {
            throw new RuntimeException("Stok {$item->name} tidak cukup untuk transaksi contoh.");
        }

        $item->stock = $stockAfter;
        $item->save();

        $movement = new StockMovement;
        $movement->fill([
            'item_id' => $item->id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $entry['reason'] ?? null,
            'note' => $entry['note'] ?? null,
        ]);
        $movement->user_id = $user->id;
        $movement->stock_after = $stockAfter;
        $movement->created_at = $entry['at'];
        $movement->save();
    }
}
