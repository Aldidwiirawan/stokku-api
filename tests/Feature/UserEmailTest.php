<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_stored_in_lowercase_on_create(): void
    {
        $user = User::create([
            'name' => 'Budi',
            'email' => '  Budi.Santoso@StokKu.TEST ',
            'password' => 'password',
        ]);

        $this->assertSame('budi.santoso@stokku.test', $user->email);
        // Cek nilai yang benar-benar tersimpan di database.
        $this->assertDatabaseHas('users', ['email' => 'budi.santoso@stokku.test']);
    }

    public function test_email_is_stored_in_lowercase_on_update_and_factory(): void
    {
        $user = User::factory()->create(['email' => 'Dapur@StokKu.test']);
        $this->assertDatabaseHas('users', ['email' => 'dapur@stokku.test']);

        $user->email = 'GUDANG@stokku.test';
        $user->save();
        $this->assertDatabaseHas('users', ['email' => 'gudang@stokku.test']);
    }

    public function test_user_created_with_capital_letters_can_log_in(): void
    {
        User::factory()->create(['email' => 'Kasir@StokKu.test']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'KASIR@stokku.test',
            'password' => 'password',
            'device_name' => 'Test HP',
        ])->assertOk();
    }
}
