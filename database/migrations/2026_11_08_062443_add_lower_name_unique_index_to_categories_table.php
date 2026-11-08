<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expression index: database menyimpan dan menjaga keunikan HASIL lower(name),
     * jadi "Bumbu & Rempah" dan "bumbu & rempah" tidak bisa tersimpan bersamaan,
     * bahkan jika dua request datang di detik yang sama.
     *
     * Ditulis dengan SQL langsung karena Schema Builder Laravel tidak menyediakan
     * unique index berbasis ekspresi. Sintaks ini sama di PostgreSQL dan SQLite.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX categories_name_lower_unique ON categories (lower(name))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX categories_name_lower_unique');
    }
};
