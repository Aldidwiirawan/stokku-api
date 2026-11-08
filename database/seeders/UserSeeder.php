<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@stokku.test';

    public const STAFF_EMAIL = 'staf@stokku.test';

    /**
     * Buat 2 akun demo. Aman dijalankan ulang: akun yang sudah ada tidak dibuat dobel.
     */
    public function run(): void
    {
        $this->seedAccount(self::ADMIN_EMAIL, 'Admin StokKu', 'admin');
        $this->seedAccount(self::STAFF_EMAIL, 'Staf Dapur', 'staf');
    }

    private function seedAccount(string $email, string $name, string $role): void
    {
        // Password hanya diisi saat akun pertama kali dibuat,
        // jadi password yang sudah diganti lewat aplikasi tidak ditimpa.
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password'],
        );

        // role dan is_active tidak fillable, jadi diisi eksplisit.
        $user->role = $role;
        $user->is_active = true;
        $user->save();
    }
}
