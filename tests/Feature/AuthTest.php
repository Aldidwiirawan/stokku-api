<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_URL = '/api/v1/auth/login';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function credentials(array $overrides = []): array
    {
        return array_merge([
            'email' => 'staf@stokku.test',
            'password' => 'password',
            'device_name' => 'Test HP',
        ], $overrides);
    }

    /**
     * Setelah token dihapus, Laravel masih "ingat" pengguna dari request sebelumnya
     * karena test memakai aplikasi yang sama. forgetGuards() membuatnya lupa,
     * seperti request baru dari HP.
     */
    private function forgetLoggedInUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_login_with_correct_credentials_returns_token_and_user(): void
    {
        $user = User::factory()->create(['email' => 'staf@stokku.test']);

        $response = $this->postJson(self::LOGIN_URL, $this->credentials());

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'staf@stokku.test')
            ->assertJsonPath('data.user.role', 'staf')
            ->assertJsonPath('data.user.is_active', true)
            ->assertJsonMissingPath('data.user.password');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Test HP',
        ]);
    }

    public function test_token_from_login_can_be_used_for_me(): void
    {
        User::factory()->create(['email' => 'staf@stokku.test', 'name' => 'Staf Dapur']);
        $token = $this->postJson(self::LOGIN_URL, $this->credentials())->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Staf Dapur');
    }

    public function test_email_is_not_case_sensitive(): void
    {
        User::factory()->create(['email' => 'staf@stokku.test']);

        $this->postJson(self::LOGIN_URL, $this->credentials(['email' => 'Staf@Stokku.TEST']))
            ->assertOk();
    }

    public function test_wrong_password_returns_422_with_indonesian_message(): void
    {
        User::factory()->create(['email' => 'staf@stokku.test']);

        $this->postJson(self::LOGIN_URL, $this->credentials(['password' => 'salah']))
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Data tidak valid.',
                'errors' => ['email' => ['Email atau password salah.']],
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_email_returns_the_same_message_as_wrong_password(): void
    {
        $this->postJson(self::LOGIN_URL, $this->credentials(['email' => 'tidak-ada@stokku.test']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email atau password salah.');
    }

    public function test_empty_fields_return_indonesian_validation_errors(): void
    {
        $this->postJson(self::LOGIN_URL, [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Data tidak valid.',
                'errors' => [
                    'email' => ['Email wajib diisi.'],
                    'password' => ['Password wajib diisi.'],
                    'device_name' => ['Nama perangkat wajib diisi.'],
                ],
            ]);
    }

    public function test_inactive_user_with_correct_password_gets_403_and_no_token(): void
    {
        $user = User::factory()->create(['email' => 'staf@stokku.test']);
        $user->is_active = false;
        $user->save();

        $this->postJson(self::LOGIN_URL, $this->credentials())
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Akun Anda nonaktif. Hubungi admin.',
                'code' => 'account_inactive',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_user_with_wrong_password_does_not_reveal_status(): void
    {
        $user = User::factory()->create(['email' => 'staf@stokku.test']);
        $user->is_active = false;
        $user->save();

        $this->postJson(self::LOGIN_URL, $this->credentials(['password' => 'salah']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email atau password salah.');
    }

    public function test_login_is_limited_to_five_attempts_per_minute(): void
    {
        User::factory()->create(['email' => 'staf@stokku.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(self::LOGIN_URL, $this->credentials(['password' => 'salah']))
                ->assertUnprocessable();
        }

        $this->postJson(self::LOGIN_URL, $this->credentials())
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Terlalu banyak percobaan. Coba lagi sebentar.');
    }

    public function test_me_without_token_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Belum login atau sesi sudah berakhir. Silakan login lagi.');
    }

    public function test_me_returns_iso8601_created_at_and_full_photo_url(): void
    {
        $user = User::factory()->create();
        $user->photo_path = 'photos/users/aldi.jpg';
        $user->save();
        $token = $user->createToken('Test HP')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.photo_url', config('app.url').'/storage/photos/users/aldi.jpg');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $response->json('data.created_at'),
        );
    }

    public function test_me_returns_null_photo_url_without_photo(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Test HP')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.photo_url', null);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $phoneToken = $user->createToken('HP Aldi')->plainTextToken;
        $tabletToken = $user->createToken('Tablet Dapur')->plainTextToken;

        $this->withToken($phoneToken)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(['Tablet Dapur'], PersonalAccessToken::pluck('name')->all());

        // Token HP sudah tidak berlaku.
        $this->forgetLoggedInUser();
        $this->withToken($phoneToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Token tablet masih berlaku.
        $this->forgetLoggedInUser();
        $this->withToken($tabletToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_logout_without_token_returns_401(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }
}
