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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name', 150)->index();
            // Kategori tidak bisa dihapus selama masih dipakai barang.
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('unit', 20);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('min_stock')->default(0);
            $table->decimal('price', 12, 2);
            $table->string('photo_path')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
