<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Disk "public" palsu: file tidak benar-benar ditulis ke storage/app/public.
        Storage::fake('public');

        $this->user = User::factory()->create(['name' => 'Staf Lama']);
        $this->token = $this->user->createToken('HP Aldi')->plainTextToken;
    }

    // ---------- PUT /profile ----------

    public function test_user_can_change_name(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/profile', ['name' => 'Staf Dapur Baru'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Staf Dapur Baru');

        $this->assertSame('Staf Dapur Baru', $this->user->fresh()->name);
    }

    public function test_name_is_required(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/profile', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Nama wajib diisi.');
    }

    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/profile', [
                'name' => 'Staf Lama',
                'current_password' => 'password',
                'password' => 'rahasia-baru',
                'password_confirmation' => 'rahasia-baru',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('rahasia-baru', $this->user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/profile', [
                'name' => 'Staf Lama',
                'current_password' => 'salah',
                'password' => 'rahasia-baru',
                'password_confirmation' => 'rahasia-baru',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'Password lama salah.');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_current_password_is_required_when_changing_password(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/profile', [
                'name' => 'Staf Lama',
                'password' => 'rahasia-baru',
                'password_confirmation' => 'rahasia-baru',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'Password lama wajib diisi jika password diisi.');
    }

    public function test_password_confirmation_must_match_and_be_long_enough(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/profile', [
                'name' => 'Staf Lama',
                'current_password' => 'password',
                'password' => 'pendek',
                'password_confirmation' => 'beda',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password', [
                'Password minimal 8 karakter.',
                'Konfirmasi password tidak cocok.',
            ]);
    }

    public function test_changing_password_revokes_other_tokens_but_keeps_current_one(): void
    {
        $tabletToken = $this->user->createToken('Tablet Dapur')->plainTextToken;
        $otherUserToken = User::factory()->create()->createToken('HP Orang Lain')->plainTextToken;

        $this->withToken($this->token)
            ->putJson('/api/v1/profile', [
                'name' => 'Staf Lama',
                'current_password' => 'password',
                'password' => 'rahasia-baru',
                'password_confirmation' => 'rahasia-baru',
            ])
            ->assertOk();

        // Token milik user ini: hanya "HP Aldi" yang tersisa. Token user lain tidak tersentuh.
        $this->assertSame(['HP Aldi'], $this->user->tokens()->pluck('name')->all());
        $this->assertSame(2, PersonalAccessToken::count());

        $this->app['auth']->forgetGuards();
        $this->withToken($tabletToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/v1/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($otherUserToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_changing_only_name_does_not_revoke_tokens(): void
    {
        $this->user->createToken('Tablet Dapur');

        $this->withToken($this->token)
            ->putJson('/api/v1/profile', ['name' => 'Nama Baru'])
            ->assertOk();

        $this->assertSame(2, $this->user->tokens()->count());
    }

    // ---------- POST /profile/photo ----------

    public function test_user_can_upload_photo(): void
    {
        $response = $this->withToken($this->token)
            ->post('/api/v1/profile/photo', [
                'photo' => UploadedFile::fake()->image('wajah.jpg', 400, 400),
            ], ['Accept' => 'application/json']);

        $response->assertOk();

        $path = $this->user->fresh()->photo_path;
        $this->assertStringStartsWith('photos/users/', $path);
        Storage::disk('public')->assertExists($path);
        $response->assertJsonPath('data.photo_url', config('app.url').'/storage/'.$path);
    }

    public function test_uploading_new_photo_deletes_the_old_one(): void
    {
        $this->withToken($this->token)
            ->post('/api/v1/profile/photo', ['photo' => UploadedFile::fake()->image('lama.jpg')], ['Accept' => 'application/json']);
        $oldPath = $this->user->fresh()->photo_path;

        $this->withToken($this->token)
            ->post('/api/v1/profile/photo', ['photo' => UploadedFile::fake()->image('baru.png')], ['Accept' => 'application/json'])
            ->assertOk();
        $newPath = $this->user->fresh()->photo_path;

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_photo_must_be_an_image(): void
    {
        $this->withToken($this->token)
            ->post('/api/v1/profile/photo', [
                'photo' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Foto harus berupa gambar.');

        $this->assertNull($this->user->fresh()->photo_path);
    }

    public function test_photo_must_not_exceed_2_mb(): void
    {
        $this->withToken($this->token)
            ->post('/api/v1/profile/photo', [
                'photo' => UploadedFile::fake()->image('besar.jpg')->size(2049), // kilobyte
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Foto maksimal 2048 kilobyte.');
    }

    public function test_photo_is_required(): void
    {
        $this->withToken($this->token)
            ->post('/api/v1/profile/photo', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Foto wajib diisi.');
    }

    public function test_profile_routes_require_token(): void
    {
        $this->putJson('/api/v1/profile', ['name' => 'X'])->assertUnauthorized();
        $this->postJson('/api/v1/profile/photo')->assertUnauthorized();
    }

    // ---------- photo_url mengikuti host request ----------

    public function test_photo_url_uses_the_host_of_the_request(): void
    {
        $this->user->photo_path = 'photos/users/aldi.jpg';
        $this->user->save();

        // Seolah-olah HP membuka API lewat IP laptop di jaringan Wi-Fi yang sama.
        $this->withToken($this->token)
            ->getJson('http://192.168.1.10:8000/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.photo_url', 'http://192.168.1.10:8000/storage/photos/users/aldi.jpg');

        // Dari host lain, URL ikut berubah, tidak terkunci ke APP_URL.
        $this->withToken($this->token)
            ->getJson('http://10.0.2.2:8000/api/v1/auth/me')
            ->assertJsonPath('data.photo_url', 'http://10.0.2.2:8000/storage/photos/users/aldi.jpg');
    }
}
