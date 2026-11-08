<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    private const THIRTY_DAYS_IN_MINUTES = 30 * 24 * 60;

    public function test_default_token_lifetime_is_thirty_days(): void
    {
        $this->assertSame(self::THIRTY_DAYS_IN_MINUTES, config('sanctum.expiration'));
    }

    public function test_token_still_works_just_before_thirty_days(): void
    {
        $token = User::factory()->create()->createToken('Test HP')->plainTextToken;

        // Majukan "jam" aplikasi: 30 hari kurang 1 menit setelah login.
        $this->travel(self::THIRTY_DAYS_IN_MINUTES - 1)->minutes();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_token_older_than_thirty_days_gets_401(): void
    {
        $token = User::factory()->create()->createToken('Test HP')->plainTextToken;

        // 30 hari lebih 1 menit setelah login.
        $this->travel(self::THIRTY_DAYS_IN_MINUTES + 1)->minutes();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Belum login atau sesi sudah berakhir. Silakan login lagi.']);
    }

    public function test_logging_in_again_gives_a_fresh_thirty_day_token(): void
    {
        User::factory()->create(['email' => 'staf@stokku.test']);
        $this->travel(self::THIRTY_DAYS_IN_MINUTES + 1)->minutes();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'staf@stokku.test',
            'password' => 'password',
            'device_name' => 'Test HP',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }
}
