<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_defaults_to_active_staff(): void
    {
        // Dibuat tanpa factory supaya yang diuji adalah nilai default dari database.
        $user = User::create([
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'password' => 'password',
        ])->fresh();

        $this->assertSame('staf', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->photo_path);
        $this->assertFalse($user->isAdmin());
    }

    public function test_admin_factory_state_makes_user_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->isAdmin());
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Penyusup',
            'email' => 'penyusup@example.com',
            'password' => 'password',
            'role' => 'admin',
        ])->fresh();

        $this->assertSame('staf', $user->role);
    }
}
