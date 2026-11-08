<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            // Restrict: riwayat transaksi adalah jejak audit, jadi bahan dan
            // pengguna yang punya riwayat tidak boleh terhapus permanen.
            // index(): PostgreSQL tidak otomatis membuat index untuk kolom FK,
            // padahal riwayat sering dicari per bahan dan per pengguna.
            $table->foreignId('item_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->integer('quantity');
            $table->string('reason', 30)->nullable();
            $table->unsignedInteger('stock_after');
            $table->string('note', 255)->nullable();
            // Hanya created_at: riwayat transaksi tidak pernah diubah.
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
