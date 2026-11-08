<?php

namespace Tests\Feature;

use App\Exceptions\AccountInactiveException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Route khusus test, disusun persis seperti route asli di routes/api.php.
        Route::prefix('api/v1/_test')->middleware(['auth:sanctum', 'active'])->group(function () {
            Route::get('admin-only', fn () => ['ok' => true])->middleware('role:admin');
            Route::get('admin-or-staff', fn () => ['ok' => true])->middleware('role:admin,staf');
        });
    }

    /**
     * Login sungguhan dengan token Sanctum, seperti dari aplikasi.
     */
    private function tokenFor(User $user): string
    {
        return $user->createToken('Test HP')->plainTextToken;
    }

    /**
     * Respons akun nonaktif: aplikasi membaca "code" untuk memutuskan logout.
     *
     * @var array<string, string>
     */
    private const INACTIVE_RESPONSE = [
        'message' => 'Akun Anda nonaktif. Hubungi admin.',
        'code' => 'account_inactive',
    ];

    public function test_staff_gets_403_on_admin_route_without_inactive_code(): void
    {
        $staff = User::factory()->create();

        // 403 biasa: TANPA "code", supaya aplikasi tidak logout.
        $this->withToken($this->tokenFor($staff))
            ->getJson('/api/v1/_test/admin-only')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Akses ditolak.'])
            ->assertJsonMissingPath('code');
    }

    public function test_admin_can_open_admin_route(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/_test/admin-only')
            ->assertOk();
    }

    public function test_role_middleware_accepts_several_roles(): void
    {
        $staff = User::factory()->create();

        $this->withToken($this->tokenFor($staff))
            ->getJson('/api/v1/_test/admin-or-staff')
            ->assertOk();
    }

    public function test_admin_route_without_token_returns_401(): void
    {
        $this->getJson('/api/v1/_test/admin-only')->assertUnauthorized();
    }

    public function test_inactive_user_with_valid_token_gets_403_on_me(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        // Akun dinonaktifkan SETELAH token dibuat (misalnya oleh admin).
        $user->is_active = false;
        $user->save();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertExactJson(self::INACTIVE_RESPONSE);
    }

    public function test_inactive_admin_gets_inactive_message_before_role_check(): void
    {
        $admin = User::factory()->admin()->inactive()->create();

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/_test/admin-only')
            ->assertForbidden()
            ->assertExactJson(self::INACTIVE_RESPONSE);
    }

    public function test_inactive_account_is_not_reported_as_server_error(): void
    {
        Exceptions::fake();
        $user = User::factory()->inactive()->create();

        $this->withToken($this->tokenFor($user))->getJson('/api/v1/auth/me')->assertForbidden();

        // Akun nonaktif adalah kejadian normal, bukan bug: jangan memenuhi log error.
        Exceptions::assertNotReported(AccountInactiveException::class);
    }

    public function test_inactive_user_can_still_log_out(): void
    {
        $user = User::factory()->inactive()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }
}
